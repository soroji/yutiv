import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CatalogTranslationPanel } from '../CatalogTranslationPanel';

const response = (data: any, ok = true) => Promise.resolve({ ok, json: async () => ({ data, message: 'failure' }) } as Response);
describe('review-first AI catalog translation', () => {
  let fetcher: ReturnType<typeof vi.fn>;
  beforeEach(() => {
    (window as any).G7Core = { t: (key: string) => key.split('.').pop(), api: { getToken: () => 'test-only-token' } };
    fetcher = vi.fn((url: string, options: RequestInit = {}) => {
      if (url.endsWith('/configuration')) return response({ configured: true });
      if (url.endsWith('/cancel')) return response({ id: 'test-job', cancelled: true, items: [] });
      const body = JSON.parse(options.body as string);
      return response({ id: 'test-job', kind: body.kind, items: body.items.map((item: any) => ({ ...item, status: item.source && (item.overwrite || !item.current) ? 'completed' : 'skipped', result: `Translation ${item.source}` })) });
    });
    vi.stubGlobal('fetch', fetcher);
  });
  afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals(); });
  const open = async () => { fireEvent.click(screen.getByRole('button', { name: 'title' })); await screen.findByText('configured'); };
  it.each(['product', 'category'] as const)('supports an unsaved %s, requires review/apply, and never saves a catalog record', async kind => {
    const onChange = vi.fn(); render(<CatalogTranslationPanel kind={kind} value={{ name: { ko: '한국어' } }} onChange={onChange} />);
    expect(fetcher).not.toHaveBeenCalled(); await open();
    fireEvent.click(screen.getByRole('button', { name: 'translate' }));
    await screen.findByRole('button', { name: 'apply' });
    expect(onChange).not.toHaveBeenCalled();
    const reviews = screen.getAllByRole('textbox').filter(element => element.getAttribute('aria-label')?.startsWith('review'));
    fireEvent.change(reviews[0], { target: { value: 'Reviewed translation' } });
    fireEvent.click(screen.getByRole('button', { name: 'apply' }));
    expect(onChange.mock.calls[0][0].target.value.form.name.en).toBe('Reviewed translation');
    const posts = fetcher.mock.calls.filter(([, options]) => options?.method === 'POST' && !String((options as any).body).includes('cancel'));
    expect(posts).toHaveLength(1); expect(JSON.parse(posts[0][1]!.body as string).entity_id).toBeNull();
    expect(fetcher.mock.calls.every(([url]) => String(url).includes('/catalog-translations'))).toBe(true);
  });
  it('does not call AI while typing, preserves manual translations, and protects changes before apply', async () => {
    const onChange = vi.fn(); const { rerender } = render(<CatalogTranslationPanel value={{ name: { ko: '원문', en: 'Manual' } }} onChange={onChange} />);
    await open(); const calls = fetcher.mock.calls.length;
    rerender(<CatalogTranslationPanel value={{ name: { ko: '새 원문', en: 'Manual' } }} onChange={onChange} />);
    expect(fetcher).toHaveBeenCalledTimes(calls);
    fireEvent.click(screen.getByRole('button', { name: 'translate' })); await screen.findByRole('button', { name: 'apply' });
    rerender(<CatalogTranslationPanel value={{ name: { ko: '더 새 원문', en: 'Manual', ja: 'Manual Japanese' } }} onChange={onChange} />);
    fireEvent.click(screen.getByRole('button', { name: 'apply' }));
    expect(onChange.mock.calls[0][0].target.value.form.name).toEqual({ ko: '더 새 원문', en: 'Manual', ja: 'Manual Japanese' });
    expect(screen.getByText(/conflicts: 2/)).toBeInTheDocument();
  });
  it('blocks a duplicate click until the first request completes', async () => {
    let resolve!: (value: Response) => void;
    fetcher.mockImplementation((url: string) => url.endsWith('/configuration') ? response({ configured: true }) : new Promise<Response>(finish => { resolve = finish; }));
    render(<CatalogTranslationPanel value={{ name: { ko: '원문' } }} />); await open();
    const button = screen.getByRole('button', { name: 'translate' }); fireEvent.click(button); fireEvent.click(button);
    expect(fetcher.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(1);
    await act(async () => { resolve({ ok: true, json: async () => ({ data: { id: 'test-job', items: [] } }) } as Response); });
  });
  it('waits for Retry-After without automatic POSTs, preserves inputs and reuses request_id on manual retry', async () => {
    (window as any).G7Core.t = (key: string) => key.endsWith('.rate_limited') ? '요청이 많습니다. {seconds}초 후 다시 시도해 주세요.' : key.split('.').pop();
    fetcher.mockImplementation((url: string) => url.endsWith('/configuration') ? response({ configured: true }) : Promise.resolve({ ok: false, status: 429, headers: new Headers({ 'Retry-After': '3' }), json: async () => ({ errors: { code: 'catalog_translation_rate_limited', retry_after: 99 } }) }));
    const onChange = vi.fn(); render(<CatalogTranslationPanel value={{ name: { ko: '원문', en: 'Manual' } }} onChange={onChange} />); await open();
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'YUTIV' } });
    vi.useFakeTimers();
    await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'translate' })); });
    expect(screen.getByRole('alert')).toHaveTextContent('3초');
    expect(screen.getByRole('button', { name: 'translate' })).toBeDisabled();
    const first = JSON.parse(fetcher.mock.calls.find(([, options]) => options?.method === 'POST')![1].body as string);
    await act(async () => { vi.advanceTimersByTime(3000); });
    expect(screen.getByRole('button', { name: 'translate' })).not.toBeDisabled();
    expect(fetcher.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(1);
    expect(screen.getByRole('textbox')).toHaveValue('YUTIV'); expect(onChange).not.toHaveBeenCalled();
    await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'translate' })); });
    const posts = fetcher.mock.calls.filter(([, options]) => options?.method === 'POST');
    expect(posts).toHaveLength(2); expect(JSON.parse(posts[1][1].body as string).request_id).toBe(first.request_id);
  });
  it('preserves completed review during a failed-item retry cooldown and sends no automatic retry', async () => {
    fetcher.mockImplementation((url: string, options: RequestInit = {}) => {
      if (url.endsWith('/configuration')) return response({ configured: true });
      if (url.endsWith('/retry')) return Promise.resolve({ ok: false, status: 429, headers: new Headers({ 'Retry-After': '2' }), json: async () => ({ errors: { code: 'catalog_translation_rate_limited' } }) });
      const items = JSON.parse(options.body as string).items;
      return response({ id: 'test-job', items: items.map((item: any, i: number) => ({ ...item, status: i ? 'failed' : 'completed', result: i ? null : 'Reviewed' })) });
    });
    render(<CatalogTranslationPanel value={{ name: { ko: '원문' } }} />); await open();
    fireEvent.click(screen.getByRole('button', { name: 'translate' })); await screen.findByRole('button', { name: 'retry' });
    const review = screen.getByLabelText(/review/); fireEvent.change(review, { target: { value: 'Manual review' } });
    vi.useFakeTimers(); await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'retry' })); });
    expect(screen.getByRole('button', { name: 'retry' })).toBeDisabled(); expect(review).toHaveValue('Manual review');
    await act(async () => { vi.advanceTimersByTime(2000); });
    expect(screen.getByRole('button', { name: 'retry' })).not.toBeDisabled();
    expect(fetcher.mock.calls.filter(([url]) => url.endsWith('/retry'))).toHaveLength(1);
  });
  it('does not misclassify a provider or generic HTTP 429 as the YUTIV limiter', async () => {
    fetcher.mockImplementation((url: string) => url.endsWith('/configuration') ? response({ configured: true }) : Promise.resolve({ ok: false, status: 429, json: async () => ({ message: 'Provider unavailable' }) }));
    render(<CatalogTranslationPanel value={{ name: { ko: '원문' } }} />); await open();
    fireEvent.click(screen.getByRole('button', { name: 'translate' })); await screen.findByRole('alert');
    expect(screen.getByRole('alert')).toHaveTextContent('Provider unavailable');
    expect(screen.getByRole('button', { name: 'translate' })).not.toBeDisabled();
    expect(fetcher.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(1);
  });
  it('uses GET for configuration and polling, with one POST across click, submit and rerender', async () => {
    let items: any[] = [];
    fetcher.mockImplementation((url: string, options: RequestInit = {}) => {
      if (url.endsWith('/configuration')) return response({ configured: true });
      if (options.method === 'POST') { items = JSON.parse(options.body as string).items; return response({ id: 'poll-job', items: items.map(item => ({ ...item, status: 'pending' })) }); }
      return response({ id: 'poll-job', items: items.map(item => ({ ...item, status: 'completed', result: 'Done' })) });
    });
    const value = { name: { ko: '원문' } };
    const { rerender } = render(<CatalogTranslationPanel value={value} />); await open();
    vi.useFakeTimers(); const button = screen.getByRole('button', { name: 'translate' });
    expect(button).toHaveAttribute('type', 'button');
    await act(async () => { fireEvent.click(button); fireEvent.click(button); fireEvent.submit(button); });
    rerender(<CatalogTranslationPanel value={value} />);
    await act(async () => { vi.advanceTimersByTime(1500); });
    expect(fetcher.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(1);
    expect(fetcher.mock.calls.some(([url, options]) => url.endsWith('/poll-job') && options?.method === 'GET')).toBe(true);
  });
  it('shows missing connection guidance and disables translation without exposing secrets', async () => {
    fetcher.mockImplementation(() => response({ configured: false }));
    render(<CatalogTranslationPanel value={{ name: { ko: '원문' } }} />);
    fireEvent.click(screen.getByRole('button', { name: 'title' })); await screen.findByText('not_configured');
    expect(screen.getByRole('button', { name: 'translate' })).toBeDisabled();
    expect(screen.getByRole('link', { name: 'settings' })).toHaveAttribute('href', '/admin/ecommerce/settings?tab=language_currency');
  });
  it.each(['disabled', 'config_missing', 'not_configured'])('shows safe connection status %s and keeps translation disabled', async (status) => {
    fetcher.mockImplementation(() => response({ configured: false, status }));
    render(<CatalogTranslationPanel configurationOnly />);
    await screen.findByText(status);
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(document.body.textContent).not.toContain('fake-only-key');
  });
  it('shows request failure without clearing the original form', async () => {
    fetcher.mockImplementation((url: string) => url.endsWith('/configuration') ? response({ configured: true }) : response(null, false));
    const onChange = vi.fn(); render(<CatalogTranslationPanel value={{ name: { ko: '원문', en: 'Manual' } }} onChange={onChange} />); await open();
    fireEvent.click(screen.getByRole('button', { name: 'translate' })); await screen.findByRole('alert'); expect(onChange).not.toHaveBeenCalled();
  });
  it('uses the latest engine state instead of debounced form props when applying', async () => {
    let current = { form: { name: { ko: '원문' } } };
    (window as any).G7Core.state = { getLocal: () => current };
    const onChange = vi.fn(); render(<CatalogTranslationPanel value={current.form} onChange={onChange} />); await open();
    fireEvent.click(screen.getByRole('button', { name: 'translate' })); await screen.findByRole('button', { name: 'apply' });
    current = { form: { name: { ko: '수정된 최신 원문' } } };
    fireEvent.click(screen.getByRole('button', { name: 'apply' }));
    expect(onChange.mock.calls[0][0].target.value.form.name).toEqual(current.form.name);
    expect(screen.getByText(/conflicts: 3/)).toBeInTheDocument();
  });
  it('flushes pending engine input before requesting and applying without overwriting a last-moment edit', async () => {
    let current = { form: { name: { ko: '원문' } } };
    let pending = '';
    (window as any).G7Core.state = { getLocal: () => current };
    const onSnapshot = vi.fn(() => { if (pending) current = { form: { name: { ko: pending } } }; });
    const onChange = vi.fn();
    render(<CatalogTranslationPanel value={current.form} __componentContext={{ stateRef: { current } }} onSnapshot={onSnapshot} onChange={onChange} />); await open();
    pending = '번역 시작 직전 원문';
    fireEvent.click(screen.getByRole('button', { name: 'translate' })); await screen.findByRole('button', { name: 'apply' });
    const body = JSON.parse(fetcher.mock.calls.find(([, options]) => options?.method === 'POST')![1].body);
    expect(body.items.find((item: any) => item.field === 'name').source).toBe(pending);
    pending = '적용 직전 수정';
    fireEvent.click(screen.getByRole('button', { name: 'apply' }));
    expect(onChange.mock.calls[0][0].target.value.form.name).toEqual({ ko: pending });
    expect(onSnapshot).toHaveBeenCalledTimes(2);
  });
  it('discards old entity results when switching category forms', async () => {
    const onChange = vi.fn(); const { rerender } = render(<CatalogTranslationPanel kind="category" entityId={1} value={{ name: { ko: '같은 이름' } }} onChange={onChange} />); await open();
    fireEvent.click(screen.getByRole('button', { name: 'translate' })); await screen.findByRole('button', { name: 'apply' });
    rerender(<CatalogTranslationPanel kind="category" entityId={2} value={{ name: { ko: '같은 이름' } }} onChange={onChange} />);
    await waitFor(() => expect(screen.queryByRole('button', { name: 'apply' })).not.toBeInTheDocument()); expect(onChange).not.toHaveBeenCalled();
  });
});
