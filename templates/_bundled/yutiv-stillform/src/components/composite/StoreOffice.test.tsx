import { beforeEach, afterEach, describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react';
import StoreOffice, { percentToBasisPoints } from './StoreOffice';
import { Select } from '../basic/Select';
import translations from '../../../lang/partial/ko/office.json';

const dashboard = (store: any = { slug: 'sample-store', status: 'active' }) => ({ store, wallet: { available: 50, debt: 0 }, orders: [], ledger: [], children: [], selected_category_ids: [], has_more: false });
const result = (data: any, status = 200) => ({ ok: status < 400, status, json: async () => ({ data, message: '합성 오류' }) });
describe('운영 화면의 상태와 선택 경계', () => {
  beforeEach(() => {
    (window as any).G7Core = { t: (key: string) => (translations as any)[key.split('.').pop()!] ?? key, api: { getToken: () => 'synthetic-token' } };
  });
  afterEach(() => { cleanup(); vi.unstubAllGlobals(); });
  it('미개설 회원에게 개설 폼만 보여준다', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(result(dashboard(null))));
    render(<StoreOffice />);
    expect(await screen.findByLabelText(translations.store_name)).toBeVisible();
    expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
    expect(screen.queryByText(translations.available)).not.toBeInTheDocument();
  });
  it('대기 상태에서 운영 폼을 숨긴다', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(result(dashboard({ slug: 'waiting', status: 'pending' }))));
    render(<StoreOffice />);
    expect(await screen.findByText(translations.pending_title)).toBeVisible();
    expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
    expect(screen.queryByRole('form')).not.toBeInTheDocument();
  });
  it('권한 실패를 빈 데이터로 표시하지 않고 재시도한다', async () => {
    const fetch = vi.fn().mockResolvedValueOnce(result(null, 403)).mockResolvedValue(result(dashboard()));
    vi.stubGlobal('fetch', fetch); render(<StoreOffice />);
    expect(await screen.findByRole('alert')).toHaveTextContent(translations.forbidden);
    expect(screen.queryByText(translations.no_sales)).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: translations.retry }));
    expect(await screen.findByRole('tablist')).toBeVisible();
    fireEvent.click(screen.getByRole('tab', { name: translations.sales }));
    expect(screen.getByText(translations.no_sales)).toBeVisible();
    expect(screen.queryByRole('navigation', { name: translations.pages })).not.toBeInTheDocument();
  });
  it('상품 코드를 검색하고 명시적 제외를 해당 상품에만 저장한다', async () => {
    const fetch = vi.fn(async (url: string, options: any) => {
      if (options.method === 'POST') return result(dashboard());
      if (url.includes('/catalog')) return result({ data: [{ id: 31, name: '긴 합성 상품 이름', product_code: 'CODE-31', selling_price_formatted: '10,000원', estimated_points: 100, selection: 'none' }] });
      if (url.includes('/categories')) return result({ data: [] });
      return result(dashboard());
    });
    vi.stubGlobal('fetch', fetch); render(<StoreOffice />);
    fireEvent.click(await screen.findByRole('tab', { name: translations.products }));
    expect(await screen.findByText('긴 합성 상품 이름')).toBeVisible();
    fireEvent.change(screen.getByLabelText(translations.product_search), { target: { value: 'CODE-31' } });
    fireEvent.submit(screen.getByLabelText(translations.product_search).closest('form')!);
    await waitFor(() => expect(fetch.mock.calls.some(([url]) => url.includes('search=CODE-31'))).toBe(true));
    fireEvent.click(await screen.findByRole('button', { name: translations.exclude }));
    await waitFor(() => expect(fetch.mock.calls.some(([, options]) => options.body === JSON.stringify({ action: 'products', product_id: 31, excluded: true }))).toBe(true));
    expect(await screen.findByText(translations.saved)).toBeVisible();
  });
  it('백분율은 UI 경계에서 기존 서버 단위로 변환한다', () => {
    expect(percentToBasisPoints('0')).toBe(0);
    expect(percentToBasisPoints('37.25')).toBe(3725);
    expect(percentToBasisPoints('100')).toBe(10000);
  });
  it('사용자 템플릿의 실제 선택 버튼에 접근성 이름을 전달한다', () => {
    render(<Select id="child" aria-label="직계 하위 스토어" value="1" options={[{ value: '1', label: 'sample-child' }]} />);
    expect(screen.getByRole('button', { name: '직계 하위 스토어' })).toHaveAttribute('id', 'child-control');
  });
  it('저장 후 다시 편집하면 이전 저장 성공 안내를 지운다', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(result(dashboard())));
    render(<StoreOffice />);
    fireEvent.click(await screen.findByRole('tab', { name: translations.profile }));
    fireEvent.click(screen.getByRole('button', { name: translations.save }));
    expect(await screen.findByText(translations.saved)).toBeVisible();
    fireEvent.change(await screen.findByLabelText(translations.introduction), { target: { value: '아직 저장하지 않은 새 소개' } });
    expect(screen.queryByText(translations.saved)).not.toBeInTheDocument();
  });
});
