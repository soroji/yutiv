import { beforeEach, expect, test, vi } from 'vitest';

let local: any;
let handlers: Record<string, any>;
beforeEach(async () => {
    vi.resetModules(); vi.useFakeTimers();
    local = { importRun: { id: 'job-id', rows: [{ price: 1 }] } }; handlers = {};
    vi.stubGlobal('window', { G7Core: {
        api: { getToken: () => 'test-token' },
        state: { getLocal: () => local, setLocal: (v: any) => Object.assign(local, v) },
        getActionDispatcher: () => ({ registerHandler: (n: string, h: any) => handlers[n] = h }),
    } });
    vi.stubGlobal('document', { getElementById: () => null });
    await import('../../resources/js/index');
});

test('confirmation posts only job ID and uses the bearer token', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: 'job-id', counts: { queued: 1, processing: 0 } } })));
    vi.stubGlobal('fetch', fetch);
    await handlers['yutiv-product_import.confirm']();
    expect(fetch.mock.calls[0][0]).toMatch(/job-id\/confirm$/);
    expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'POST', headers: { Authorization: 'Bearer test-token' } });
    expect(fetch.mock.calls[0][1].body).toBeUndefined();
    expect(local.importBusy).toBe(false);
});

test('server validation errors are displayed and busy state is cleared', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ errors: { file: ['상품 2행 관리코드 중복'] } }), { status: 422 })));
    await handlers['yutiv-product_import.confirm']();
    expect(local.importError).toBe('상품 2행 관리코드 중복');
    expect(local.importBusy).toBe(false);
});

test('leaving the page stops progress requests', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: 'job-id', counts: { queued: 1, processing: 0 } } })));
    vi.stubGlobal('fetch', fetch);
    await handlers['yutiv-product_import.confirm']();
    await vi.advanceTimersByTimeAsync(5000);
    expect(fetch).toHaveBeenCalledTimes(1);
});
