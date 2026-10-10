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
  afterEach(() => { vi.unstubAllGlobals(); });
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
  it('shows missing connection guidance and disables translation without exposing secrets', async () => {
    fetcher.mockImplementation(() => response({ configured: false }));
    render(<CatalogTranslationPanel value={{ name: { ko: '원문' } }} />);
    fireEvent.click(screen.getByRole('button', { name: 'title' })); await screen.findByText('not_configured');
    expect(screen.getByRole('button', { name: 'translate' })).toBeDisabled();
    expect(screen.getByRole('link', { name: 'settings' })).toHaveAttribute('href', '/admin/ecommerce/settings?tab=language_currency');
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
