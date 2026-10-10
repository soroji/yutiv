import React, { useEffect, useId, useRef, useState } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { Label } from '../basic/Label';
import { Checkbox } from '../basic/Checkbox';
import { Textarea } from '../basic/Textarea';
import { A } from '../basic/A';
import { applyTranslationResults, collectTranslationBindings, staleTranslationFields, TranslationBinding, TranslationItem } from './catalogTranslation';

const base = '/api/modules/sirsoft-ecommerce/admin/catalog-translations';
const core = () => (window as any).G7Core;
const t = (key: string) => core()?.t?.(`sirsoft-ecommerce.admin.translation.${key}`) ?? key;
class TranslationRateLimitError extends Error {
  constructor(public readonly retryAfter: number) { super('catalog_translation_rate_limited'); }
}
async function request(path: string, method = 'GET', body?: unknown, signal?: AbortSignal) {
  const token = core()?.api?.getToken?.();
  const response = await fetch(base + path, { method, credentials: 'same-origin', signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, ...(body !== undefined ? { body: JSON.stringify(body) } : {}) });
  const json = await response.json();
  if (response.status === 429 && json.errors?.code === 'catalog_translation_rate_limited') {
    const seconds = Number(response.headers?.get('Retry-After') ?? json.errors.retry_after);
    throw new TranslationRateLimitError(Number.isFinite(seconds) && seconds > 0 ? Math.ceil(seconds) : 60);
  }
  if (!response.ok) throw new Error(Object.values(json.errors ?? {}).flat().join(' ') || json.message || t('request_failed'));
  return json.data;
}

export interface CatalogTranslationPanelProps {
  kind?: 'product' | 'category';
  value?: Record<string, any>;
  optionInputs?: any[];
  entityId?: number | null;
  disabled?: boolean;
  configurationOnly?: boolean;
  defaultExpanded?: boolean;
  /** Engine custom event: flush pending input debounce before reading a snapshot. */
  onSnapshot?: () => void;
  __componentContext?: { state?: Record<string, any>; stateRef?: { current: Record<string, any> } };
  onChange?: (event: { target: { value: { form: Record<string, any>; optionInputs: any[] } } }) => void;
}

export const CatalogTranslationPanel: React.FC<CatalogTranslationPanelProps> = ({ kind = 'product', value: providedValue, optionInputs = [], entityId, disabled, configurationOnly, defaultExpanded = false, onChange, onSnapshot, __componentContext }) => {
  const value = providedValue ?? {};
  const latest = useRef({ value, optionInputs }); latest.current = { value, optionInputs };
  const context = useRef(__componentContext); context.current = __componentContext;
  const readCurrent = () => {
    onSnapshot?.();
    // getLocal includes flushed input pending before the next React render updates stateRef.
    const state = core()?.state?.getLocal?.() ?? context.current?.stateRef?.current ?? context.current?.state;
    if (state?.form && (!latest.current.value.id || state.form.id === latest.current.value.id)) return { value: state.form, optionInputs: state.ui?.optionInputs ?? latest.current.optionInputs };
    return latest.current;
  };
  const [opened, setOpened] = useState(!!configurationOnly || defaultExpanded);
  const panelId = useId();
  const [configuration, setConfiguration] = useState<{ configured: boolean; status?: string } | null>(null);
  const [locales, setLocales] = useState(['en', 'ja', 'zh-CN']);
  const allFields = ['name', 'description', 'meta_title', 'meta_description', ...(kind === 'product' ? ['meta_keywords', 'option_group_name', 'option_value', 'option_name', 'additional_option_name', 'additional_option_value'] : [])];
  const [fields, setFields] = useState(allFields);
  const [overwrite, setOverwrite] = useState(false);
  const [terms, setTerms] = useState('');
  const [job, setJob] = useState<any>(null);
  const [review, setReview] = useState<TranslationItem[]>([]);
  const edited = useRef(new Map<string, string>());
  const bindings = useRef<TranslationBinding[]>([]);
  const requestKey = useRef<{ payload: string; id: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const running = useRef(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const blockedUntil = useRef(0);
  const [waitSeconds, setWaitSeconds] = useState(0);
  useEffect(() => {
    if (!waitSeconds) return;
    const timer = setInterval(() => setWaitSeconds(Math.max(0, Math.ceil((blockedUntil.current - Date.now()) / 1000))), 1000);
    return () => clearInterval(timer);
  }, [waitSeconds]);
  const generation = useRef(0);
  const controller = useRef<AbortController | null>(null);
  const stale = staleTranslationFields(value, optionInputs, kind);
  const jobRef = useRef(job); jobRef.current = job;
  const scope = `${kind}:${entityId ?? value.id ?? 'new'}`;
  useEffect(() => {
    generation.current++; controller.current?.abort(); bindings.current = []; requestKey.current = null; edited.current.clear(); setJob(null); setReview([]); setNotice('');
    return () => { if (jobRef.current?.id && !jobRef.current.cancelled) void request('/' + jobRef.current.id + '/cancel', 'POST').catch(() => {}); };
  }, [scope]);
  useEffect(() => () => { generation.current++; controller.current?.abort(); }, []);
  useEffect(() => {
    if (!opened) return;
    let active = true;
    request('/configuration').then(data => { if (active) setConfiguration(data); }).catch(e => { if (active) setError(e.message); });
    return () => { active = false; };
  }, [opened]);
  useEffect(() => {
    if (!job || job.cancelled || !job.items?.some((item: TranslationItem) => ['pending', 'processing'].includes(item.status ?? ''))) return;
    const epoch = generation.current;
    const timer = setTimeout(async () => {
      try {
        const next = await request('/' + job.id);
        if (epoch !== generation.current) return;
        setJob(next); setReview(next.items.map((item: TranslationItem) => ({ ...item, result: edited.current.get(item.id) ?? item.result })));
      } catch (e) { if (epoch === generation.current) { setError((e as Error).message); setJob((current: any) => ({ ...current, paused: true })); } }
    }, 1500);
    if (job.paused) clearTimeout(timer);
    return () => clearTimeout(timer);
  }, [job]);
  const run = async (action: () => Promise<void>) => {
    if (running.current) return;
    running.current = true; setBusy(true); setError('');
    const epoch = generation.current;
    try { await action(); } catch (e) {
      if (epoch === generation.current && (e as Error).name !== 'AbortError') {
        if (e instanceof TranslationRateLimitError) {
          blockedUntil.current = Date.now() + e.retryAfter * 1000; setWaitSeconds(e.retryAfter);
        } else setError((e as Error).message);
      }
    }
    finally { running.current = false; setBusy(false); }
  };
  const translating = !!job && !job.cancelled && job.items?.some((item: TranslationItem) => ['pending', 'processing'].includes(item.status ?? ''));
  const start = () => run(async () => {
    if (translating || disabled || Date.now() < blockedUntil.current) return;
    const current = readCurrent();
    const selected = fields.filter(field => !(kind === 'product' && ((field === 'meta_title' && current.value.seo_sync_title) || (field === 'meta_description' && current.value.seo_sync_description))));
    const entries = collectTranslationBindings(current.value, current.optionInputs, kind, locales, selected, overwrite);
    if (!entries.length || entries.length > 200 || !locales.length || !fields.length) throw new Error(t('limits'));
    const brands = [current.value.brand?.name?.ko, typeof current.value.brand_name === 'string' ? current.value.brand_name : current.value.brand_name?.ko].filter((term): term is string => typeof term === 'string' && !!term.trim());
    const data = { kind, entity_id: entityId ?? current.value.id ?? null, terms: [...new Set([...brands, ...terms.split('\n').map(term => term.trim()).filter(Boolean)])], items: entries.map(({ locate: _locate, ...item }) => item) };
    const payload = JSON.stringify(data);
    if (job?.cancelled) requestKey.current = null;
    if (requestKey.current?.payload !== payload) requestKey.current = { payload, id: crypto.randomUUID() };
    const epoch = generation.current;
    controller.current = new AbortController();
    const next = await request('', 'POST', { ...data, request_id: requestKey.current!.id }, controller.current.signal);
    if (epoch !== generation.current) return;
    bindings.current = entries; edited.current.clear(); setNotice(''); setJob(next); setReview(next.items);
  });
  const apply = () => {
    if (disabled || running.current) return;
    // Read current form at apply time, not the request snapshot.
    const current = readCurrent();
    const result = applyTranslationResults(current.value, current.optionInputs, kind, bindings.current, review);
    onChange?.({ target: { value: { form: result.form, optionInputs: result.optionInputs } } });
    setNotice(`${t('applied')}: ${result.applied}. ${t('conflicts')}: ${result.conflicts.length}`);
    setReview(items => items.filter(item => item.status !== 'completed' || result.conflicts.includes(item.id)));
  };
  return <Div className="min-w-0">
    {!configurationOnly && <Button type="button" className="btn-primary inline-flex items-center gap-2" onClick={() => setOpened(current => !current)} aria-label={t('title')} aria-expanded={opened} aria-controls={panelId}>{t('title')}<Span className="text-sm">{t(opened ? 'collapse' : 'expand')}</Span></Button>}
    {stale.length > 0 && <Span className="block text-sm text-amber-700 dark:text-amber-300">{t('stale')}: {stale.map(field => t(`fields.${field}`)).join(', ')}</Span>}
    {opened && <Div id={panelId} role="region" aria-label={t('title')} className="admin-card space-y-3 mt-3 mb-4">
      <Span className="block font-medium">{t('title')}</Span>
      <Span className="block text-sm">{configuration?.configured ? t('configured') : t(configuration?.status === 'disabled' ? 'disabled' : configuration?.status === 'config_missing' ? 'config_missing' : 'not_configured')}</Span>
      <A href="/admin/ecommerce/settings?tab=language_currency" className="underline text-sm">{t('settings')}</A>
      {!configurationOnly && <>
        <Span className="block text-sm">{t('instructions')}</Span>
        {kind === 'product' && (value.seo_sync_title || value.seo_sync_description) && <Span className="block text-sm">{t('seo_sync')}</Span>}
        <Div className="flex flex-wrap gap-3">{['en', 'ja', 'zh-CN'].map(locale => <Label key={locale} className="flex items-center gap-1"><Checkbox checked={locales.includes(locale)} disabled={translating || busy} onChange={() => setLocales(values => values.includes(locale) ? values.filter(item => item !== locale) : [...values, locale])} />{locale}</Label>)}</Div>
        <Div className="flex flex-wrap gap-3">{allFields.map(field => <Label key={field} className="flex items-center gap-1"><Checkbox checked={fields.includes(field)} disabled={translating || busy} onChange={() => setFields(values => values.includes(field) ? values.filter(item => item !== field) : [...values, field])} />{t(`fields.${field}`)}</Label>)}</Div>
        <Label className="flex items-center gap-2"><Checkbox checked={overwrite} disabled={translating || busy} onChange={event => setOverwrite(event.target.checked)} />{t('overwrite')}</Label>
        <Label className="block text-sm">{t('terms')}<Textarea rows={2} value={terms} disabled={translating || busy} onChange={event => setTerms(event.target.value)} className="w-full" /></Label>
        <Div className="flex flex-wrap gap-2">
          <Button type="button" className="btn btn-primary" disabled={disabled || busy || translating || waitSeconds > 0 || configuration?.configured !== true} onClick={start}>{busy ? t('working') : t('translate')}</Button>
          {job && <Button type="button" className="btn btn-outline" disabled={busy} onClick={() => run(async () => { const epoch = generation.current; const next = await request('/' + job.id + '/cancel', 'POST'); if (epoch !== generation.current) return; generation.current++; setJob(next); setNotice(t('cancelled')); })}>{t('cancel')}</Button>}
          {job?.paused && <Button type="button" className="btn btn-outline" onClick={() => setJob((current: any) => ({ ...current, paused: false }))}>{t('resume')}</Button>}
          {review.some(item => item.status === 'failed') && <Button type="button" className="btn btn-outline" disabled={busy || translating || disabled || waitSeconds > 0} onClick={() => run(async () => { if (Date.now() < blockedUntil.current) return; const epoch = generation.current; const next = await request('/' + job.id + '/retry', 'POST'); if (epoch !== generation.current) return; setJob(next); setReview(next.items.map((item: TranslationItem) => ({ ...item, result: edited.current.get(item.id) ?? item.result }))); })}>{t('retry')}</Button>}
          {review.some(item => item.status === 'completed') && <Button type="button" className="btn btn-primary" disabled={disabled || busy || translating || job?.cancelled} onClick={apply}>{t('apply')}</Button>}
        </Div>
        {job && <Span role="status" className="block text-sm">{['completed', 'failed', 'skipped', 'pending', 'processing'].map(status => `${t(`status.${status}`)}: ${job.items.filter((item: TranslationItem) => item.status === status).length}`).join(' / ')}</Span>}
        {review.map(item => <Div key={item.id} className="border rounded p-3 min-w-0">
          <Span className="block text-sm">{t(`fields.${item.field}`)} · {item.locale} · {t(`status.${item.status}`)}</Span>
          <Span className="block text-xs whitespace-pre-wrap break-words">{item.source}</Span>
          {item.error && <Span className="block text-red-600">{t(`errors.${item.error}`)}</Span>}
          {item.status === 'completed' && <Textarea aria-label={`${t('review')} ${item.id}`} value={item.result ?? ''} rows={item.html ? 5 : 2} disabled={disabled} onChange={event => { const text = event.target.value; edited.current.set(item.id, text); setReview(values => values.map(value => value.id === item.id ? { ...value, result: text } : value)); }} className="w-full min-w-0" />}
        </Div>)}
      </>}
      {error && <Span role="alert" className="block text-red-600">{error}</Span>}
      {waitSeconds > 0 && <Span role="alert" className="block text-red-600">{t('rate_limited').replace('{seconds}', String(waitSeconds))}</Span>}
      {notice && <Span role="status" className="block text-sm">{notice}</Span>}
    </Div>}
  </Div>;
};
