import { useEffect, useRef, useState } from 'react';
import { A, Button, Div, Form, H1, H2, H3, Img, Input, Label, Nav, P, Select, Span, Textarea } from '../basic';
import { FileUploader } from './FileUploader';

// 기본 Input의 label prop은 렌더되지 않으므로 운영 화면에서 명시적으로 연결합니다.
function OfficeInput({ label, ...props }: React.ComponentProps<typeof Input>) {
  return <Div className="so-field"><Label htmlFor={props.id}>{label}</Label><Input {...props} /></Div>;
}

type Row = Record<string, any>;
const base = '/api/modules/yutiv-personal_store/operator';
const core = () => (window as any).G7Core;
export const percentToBasisPoints = (value: string) => Math.round(Number(value) * 100);
const t = (key: string) => core()?.t?.(`office.${key}`) ?? key;
const points = (value: unknown) => `${Number(value ?? 0).toLocaleString()} P`;
const tabs = ['overview', 'profile', 'products', 'sales', 'children', 'ledger'];
const statusText = (status: string) => t(`status_${status}`);

async function request(path = '', body?: Row, signal?: AbortSignal) {
  const token = core()?.api?.getToken?.();
  const response = await fetch(base + path, { method: body ? 'POST' : 'GET', signal,
    headers: { Accept: 'application/json', ...(body && { 'Content-Type': 'application/json' }), ...(token && { Authorization: `Bearer ${token}` }) },
    credentials: 'same-origin', ...(body && { body: JSON.stringify(body) }) });
  const result = await response.json();
  if (!response.ok) {
    const error = new Error(response.status === 401 ? t('login_required') : response.status === 403 ? t('forbidden') : result.message || t('load_error'));
    Object.assign(error, { errors: result.errors ?? {}, status: response.status });
    throw error;
  }
  return result.data;
}

function useData(path: string, enabled = true) {
  const [state, setState] = useState<{ data: Row | null; loading: boolean; error: string }>({ data: null, loading: true, error: '' });
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    if (!enabled) return;
    const controller = new AbortController();
    setState({ data: null, loading: true, error: '' });
    request(path, undefined, controller.signal).then(data => setState({ data, loading: false, error: '' })).catch(error => {
      if (!controller.signal.aborted) setState({ data: null, loading: false, error: error.message });
    });
    return () => controller.abort();
  }, [path, enabled, revision]);
  return { ...state, reload: () => setRevision(value => value + 1) };
}

function Feedback({ state }: { state: ReturnType<typeof useData> }) {
  return state.loading ? <P className="so-notice" role="status">{t('loading')}</P> : state.error ?
    <Div className="so-notice so-error" role="alert"><P>{state.error}</P><Button type="button" onClick={state.reload}>{t('retry')}</Button></Div> : null;
}
function Pager({ page, more, count, change }: { page: number; more: boolean; count: number; change: (page: number) => void }) {
  return (count > 0 && more) || page > 1 ? <Nav className="so-pager" aria-label={t('pages')}>
    {page > 1 && <Button type="button" variant="secondary" onClick={() => change(page - 1)}>{t('previous')}</Button>}
    <Span>{page}</Span>{more && <Button type="button" variant="secondary" onClick={() => change(page + 1)}>{t('next')}</Button>}
  </Nav> : null;
}

export default function StoreOffice() {
  const [tab, setTab] = useState('overview');
  const [pages, setPages] = useState<Row>({ page: 1, ledger_page: 1, children_page: 1 });
  const [dates, setDates] = useState({ from: '', to: '' });
  const [appliedDates, setAppliedDates] = useState(dates);
  const dashboard = useData('?' + new URLSearchParams({ ...pages, ...appliedDates }).toString());
  const data = dashboard.data;
  const store = data?.store;
  const active = store?.status === 'active';
  const [profile, setProfile] = useState<Row>({ slug: '', introduction: '', logo_url: '', banner_url: '' });
  const [slug, setSlug] = useState('');
  const [saving, setSaving] = useState('');
  const [feedback, setFeedback] = useState<Row>({});
  const [selectedCategories, setSelectedCategories] = useState<number[]>([]);
  const saveSequence = useRef(0);
  useEffect(() => {
    if (store) setProfile({ slug: store.slug, introduction: store.introduction ?? '', logo_url: store.logo_url ?? '', banner_url: store.banner_url ?? '' });
    if (data) setSelectedCategories(data.selected_category_ids ?? []);
  }, [data]);
  const [search, setSearch] = useState('');
  const [productSearch, setProductSearch] = useState('');
  const [catalogPage, setCatalogPage] = useState(1);
  const catalog = useData('/catalog?' + new URLSearchParams({ search: productSearch, page: String(catalogPage) }), active && tab === 'products');
  const [categorySearch, setCategorySearch] = useState('');
  const [categoryQuery, setCategoryQuery] = useState('');
  const [categoryPage, setCategoryPage] = useState(1);
  const categories = useData('/categories?' + new URLSearchParams({ search: categoryQuery, page: String(categoryPage) }), active && tab === 'products');
  const [parentSearch, setParentSearch] = useState('');
  const [parentQuery, setParentQuery] = useState('');
  const [parentPage, setParentPage] = useState(1);
  const parents = useData('/parents?' + new URLSearchParams({ search: parentQuery, page: String(parentPage) }), active && tab === 'children');
  const [parent, setParent] = useState<Row | null>(null);
  const [child, setChild] = useState('');
  const [rate, setRate] = useState('0');

  function editProfile(changes: Row) {
    setProfile(current => ({ ...current, ...changes }));
    setFeedback(current => ({ ...current, profile: undefined }));
  }

  async function save(section: string, body: Row) {
    if (saving) return;
    const sequence = ++saveSequence.current;
    setSaving(section); setFeedback(current => ({ ...current, [section]: { message: t('saving') } }));
    try {
      await request('', body);
      if (sequence === saveSequence.current) {
        setFeedback(current => ({ ...current, [section]: { message: t('saved'), success: true } }));
        dashboard.reload();
        if (section.startsWith('product-')) catalog.reload();
      }
    } catch (error: any) {
      setFeedback(current => ({ ...current, [section]: { message: error.message, errors: error.errors, failed: true } }));
    } finally { if (sequence === saveSequence.current) setSaving(''); }
  }
  const notice = (section: string) => feedback[section] && <Div role={feedback[section].failed ? 'alert' : 'status'} className={`so-notice ${feedback[section].failed ? 'so-error' : ''}`}>
    <P>{feedback[section].message}</P>{Object.entries(feedback[section].errors ?? {}).map(([field, messages]) => <P key={field}>{String(messages)}</P>)}</Div>;
  const empty = (key: string) => <Div className="so-empty"><Span className="so-empty-mark" aria-hidden="true">—</Span><P>{t(key)}</P></Div>;
  const summary = <Div className="so-metrics">
    {[['available', points(data?.wallet?.available)], ['pending', points(data?.pending_estimate)], ['debt', points(data?.wallet?.debt)], ['network', String(data?.descendant_count ?? 0)]].map(([label, value]) =>
      <Div className="so-metric" key={label}><P>{t(label)}</P><H2>{value}</H2><P className="so-muted">{t(label + '_help')}</P></Div>)}
  </Div>;

  return <Div className="so-office sf-container">
    <Div className="so-heading"><Div><Span className="sf-eyebrow">{t('eyebrow')}</Span><H1>{t('title')}</H1><P className="so-muted">{t('intro')}</P></Div><A href="/" className="so-home">{t('shop_home')} ↗</A></Div>
    <Feedback state={dashboard} />
    {!dashboard.loading && !dashboard.error && data && (!store || !active) && <Div className="so-opening">
      <Div className="so-opening-art" aria-hidden="true"><Span>Y</Span><P>YOUR STORE</P></Div>
      <Div><Span className="sf-eyebrow">{t('start')}</Span><H2>{!store ? t('open_title') : t(data.application_status === 'rejected' ? 'rejected_title' : store.status === 'pending' ? 'pending_title' : 'suspended_title')}</H2>
        <P className="so-muted">{!store ? t('open_help') : t(data.application_status === 'rejected' ? 'rejected_help' : store.status === 'pending' ? 'application_pending_help' : 'suspended_help')}</P>
        {store && <P className="so-status">{store.slug} · {statusText(store.status)}</P>}
        {!store && <Form onSubmit={event => { event.preventDefault(); void save('apply', { action: 'apply', slug }); }}>
          <OfficeInput label={t('store_name')} id="so-apply-slug" value={slug} required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxLength={80} onChange={event => setSlug(event.target.value)} />
          <P className="so-muted">{t('slug_help')}</P><Button className="so-button" type="submit" disabled={!!saving}>{t('apply')}</Button>{notice('apply')}
        </Form>}
      </Div>
    </Div>}
    {!dashboard.loading && !dashboard.error && data && active && <>
      <Div className="so-store-heading"><Div><H2>{store.slug}</H2><Span className="so-status">{statusText(store.status)}</Span></Div><A className="so-button" href={`/store/${encodeURIComponent(store.slug)}`} target="_blank" rel="noopener noreferrer">{t('view_store')} ↗</A></Div>
      <Nav className="so-tabs" role="tablist" aria-label={t('navigation')}>{tabs.map(name => <Button key={name} id={`so-tab-${name}`} type="button" role="tab" aria-selected={tab === name} aria-controls={`so-panel-${name}`} className={tab === name ? 'so-tab is-active' : 'so-tab'} variant="secondary" onClick={() => setTab(name)}>{t(name)}</Button>)}</Nav>
      <Div className="so-panel" id={`so-panel-${tab}`} role="tabpanel" aria-labelledby={`so-tab-${tab}`}>
        {tab === 'overview' && <>{summary}<Div className="so-card"><H2>{t('next_step')}</H2><P>{t('overview_help')}</P><Button type="button" className="so-button" onClick={() => setTab('products')}>{t('choose_products')} →</Button></Div><P className="so-muted so-policy">{t('policy')}</P></>}
        {tab === 'profile' && <Form className="so-card" onSubmit={event => { event.preventDefault(); void save('profile', { action: 'profile', ...profile }); }}>
          <H2>{t('profile')}</H2><P className="so-muted">{t('profile_help')}</P>
          <OfficeInput label={t('store_name')} id="so-profile-slug" value={profile.slug} required pattern="[a-z0-9]+(-[a-z0-9]+)*" onChange={event => editProfile({ slug: event.target.value })} />
          <Label htmlFor="so-introduction">{t('introduction')}</Label><Textarea id="so-introduction" maxLength={2000} value={profile.introduction} onChange={event => editProfile({ introduction: event.target.value })} />
          <Div className="so-media-grid">{['logo', 'banner'].map(kind => <Div key={kind} className="so-media"><H3>{t(kind)}</H3>{profile[kind + '_url'] ? <Img src={profile[kind + '_url']} alt={t(kind)} className={`so-preview so-${kind}`} /> : empty('no_image')}
            <FileUploader key={profile[kind + '_url'] || kind} collection={kind} accept=".jpg,.jpeg,.png,.webp" maxFiles={1} maxSize={2} autoUpload apiEndpoints={{ upload: base + '/media' }} onUploadComplete={files => {
              const url = files[0]?.download_url; if (url) editProfile({ [kind + '_url']: new URL(url, window.location.origin).href });
            }} onUploadError={message => setFeedback(current => ({ ...current, profile: { message, failed: true } }))} />
            <P className="so-muted">{t('media_help')}</P>{profile[kind + '_url'] && <Button type="button" variant="secondary" onClick={() => editProfile({ [kind + '_url']: '' })}>{t('remove_image')}</Button>}
          </Div>)}</Div><Button type="submit" className="so-button" disabled={!!saving}>{t('save')}</Button>{notice('profile')}
        </Form>}
        {tab === 'products' && <>
          <Div className="so-card"><H2>{t('individual_products')}</H2><P className="so-muted">{t('selection_help')}</P><Form className="so-search" onSubmit={event => { event.preventDefault(); setProductSearch(search); setCatalogPage(1); }}><OfficeInput id="so-product-search" label={t('product_search')} value={search} onChange={event => setSearch(event.target.value)} /><Button type="submit" className="so-button">{t('search')}</Button></Form>
            <Feedback state={catalog} />{!catalog.loading && !catalog.error && <>{!catalog.data?.data?.length && empty('no_products')}<Div className="so-products">{(catalog.data?.data ?? []).map((item: Row) => <Div className="so-product" key={item.id}>
              {item.thumbnail_url ? <Img src={item.thumbnail_url} alt="" className="so-product-image" /> : <Div className="so-product-image so-image-empty" aria-hidden="true">Y</Div>}
              <Div><H3>{item.name}</H3><P className="so-muted">{item.product_code}</P><P>{item.selling_price_formatted}</P><P className="so-muted">{t('estimated')} {points(item.estimated_points)}</P><Span className="so-status">{t(item.selection === 'exclude' ? 'excluded' : item.selection === 'include' ? 'included' : item.selected ? 'category_selected' : 'unselected')}</Span>
                <Div className="so-row-actions"><Button type="button" variant="secondary" disabled={!!saving} onClick={() => void save(`product-${item.id}`, { action: 'products', product_id: item.id, excluded: false })}>{t('include')}</Button><Button type="button" variant="secondary" disabled={!!saving} onClick={() => void save(`product-${item.id}`, { action: 'products', product_id: item.id, excluded: true })}>{t('exclude')}</Button></Div>{notice(`product-${item.id}`)}</Div>
            </Div>)}</Div><Pager page={catalogPage} more={!!catalog.data?.has_more} count={catalog.data?.data?.length ?? 0} change={setCatalogPage} /></>}
          </Div>
          <Div className="so-card"><H2>{t('categories')}</H2><P className="so-muted">{t('categories_help')}</P><Form className="so-search" onSubmit={event => { event.preventDefault(); setCategoryQuery(categorySearch); setCategoryPage(1); }}><OfficeInput label={t('category_search')} id="so-category-search" value={categorySearch} onChange={event => setCategorySearch(event.target.value)} /><Button type="submit" className="so-button">{t('search')}</Button></Form>
            <Feedback state={categories} />{!categories.loading && !categories.error && <>{!categories.data?.data?.length && empty('no_categories')}{(categories.data?.data ?? []).map((category: Row) => <Label key={category.id} className="so-choice"><Input type="checkbox" checked={selectedCategories.includes(category.id)} onChange={event => setSelectedCategories(current => event.target.checked ? [...current, category.id] : current.filter(id => id !== category.id))} /><Span>{category.path ?? category.name}</Span></Label>)}<Pager page={categoryPage} more={!!categories.data?.has_more} count={categories.data?.data?.length ?? 0} change={setCategoryPage} /><Button type="button" className="so-button" disabled={!!saving} onClick={() => void save('categories', { action: 'categories', category_ids: selectedCategories })}>{t('save_categories')} ({selectedCategories.length})</Button>{notice('categories')}</>}
          </Div>
        </>}
        {tab === 'sales' && <Div className="so-card"><H2>{t('sales')}</H2><Form className="so-search" onSubmit={event => { event.preventDefault(); setAppliedDates(dates); setPages(current => ({ ...current, page: 1 })); }}>
          <OfficeInput id="so-from" label={t('from')} type="date" value={dates.from} onChange={event => setDates(current => ({ ...current, from: event.target.value }))} /><OfficeInput id="so-to" label={t('to')} type="date" min={dates.from} value={dates.to} onChange={event => setDates(current => ({ ...current, to: event.target.value }))} /><Button type="submit" className="so-button">{t('filter')}</Button></Form>
          {!data.orders?.length ? empty('no_sales') : data.orders.map((order: Row, index: number) => <Div key={`${order.reference}-${index}`} className="so-entry"><H3>{order.product}</H3><P className="so-muted">{new Date(order.ordered_at).toLocaleDateString()} · {order.quantity} {t('quantity')}</P><Div className="so-entry-values"><P>{Number(order.payment_amount).toLocaleString()} {order.currency}</P><P>{points(order.reward)} · {statusText(order.point_status)}</P></Div></Div>)}
          <Pager page={Number(pages.page)} more={!!data.has_more} count={data.orders?.length ?? 0} change={page => setPages(current => ({ ...current, page }))} />
        </Div>}
        {tab === 'children' && <>
          <Div className="so-card"><H2>{t('children')}</H2><P className="so-muted">{t('allocation_help')}</P>{!data.children?.length ? empty('no_children') : <Form onSubmit={event => { event.preventDefault(); void save('allocation', { action: 'allocation', child_id: Number(child), rate_bps: percentToBasisPoints(rate) }); }}>
            <Label id="so-child-label" htmlFor="so-child-control">{t('child_name')}</Label><Select id="so-child" aria-labelledby="so-child-label" className="so-child-select" value={child} options={data.children.map((item: Row) => ({ value: String(item.id), label: item.slug }))} searchable onChange={event => { const id = String(event.target.value); setChild(id); setRate(String((data.children.find((item: Row) => String(item.id) === id)?.rate_bps ?? 0) / 100)); }} />
            <OfficeInput id="so-rate" label={t('percentage')} type="number" min={0} max={100} step={0.01} required value={rate} onChange={event => setRate(event.target.value)} /><Button type="submit" className="so-button" disabled={!!saving || !child}>{t('save_allocation')}</Button>{notice('allocation')}
          </Form>}<Pager page={Number(pages.children_page)} more={!!data.children_has_more} count={data.children?.length ?? 0} change={children_page => { setChild(''); setPages(current => ({ ...current, children_page })); }} /></Div>
          <Div className="so-card"><H2>{t('move')}</H2><P className="so-muted">{t('move_help')}</P><Form className="so-search" onSubmit={event => { event.preventDefault(); setParentQuery(parentSearch); setParentPage(1); }}><OfficeInput id="so-parent-search" label={t('store_search')} value={parentSearch} onChange={event => setParentSearch(event.target.value)} /><Button type="submit" className="so-button">{t('search')}</Button></Form>
            <Feedback state={parents} />{!parents.loading && !parents.error && <>{!parents.data?.data?.length && empty('no_stores')}<Div className="so-parent-options">{(parents.data?.data ?? []).map((item: Row) => <Button type="button" variant="secondary" key={item.id} aria-pressed={parent?.id === item.id} onClick={() => setParent(item)}>{item.name}</Button>)}</Div><Pager page={parentPage} more={!!parents.data?.has_more} count={parents.data?.data?.length ?? 0} change={setParentPage} />{parent && <P>{t('selected_store')}: {parent.name}</P>}<Button type="button" className="so-button" disabled={!!saving || !parent} onClick={() => void save('move', { action: 'request_move', parent_id: parent?.id })}>{t('request_move')}</Button>{notice('move')}</>}
          </Div>
        </>}
        {tab === 'ledger' && <Div className="so-card"><H2>{t('ledger')}</H2><P className="so-muted">{t('ledger_help')}</P>{!data.ledger?.length ? empty('no_ledger') : data.ledger.map((entry: Row) => <Div className="so-entry" key={entry.id}><Div><H3>{t(`ledger_${entry.kind}`)}</H3><P className="so-muted">{new Date(entry.created_at).toLocaleDateString()}</P></Div><Div className="so-entry-values"><P>{points(entry.amount)}</P><P className="so-muted">{t('available_change')} {points(entry.available_delta)} · {t('debt_change')} {points(entry.debt_delta)}</P></Div></Div>)}<Pager page={Number(pages.ledger_page)} more={!!data.ledger_has_more} count={data.ledger?.length ?? 0} change={ledger_page => setPages(current => ({ ...current, ledger_page }))} /></Div>}
      </Div>
    </>}
  </Div>;
}
