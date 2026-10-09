const identifier = 'yutiv-product_import';
const base = '/api/plugins/yutiv-product_import/admin/product-imports';
const core = () => (window as any).G7Core;
const state = (values: Record<string, unknown>) => core().state.setLocal(values, { scope: 'root' });
let generation = 0;

export async function request(path: string, method = 'GET', body?: BodyInit): Promise<Response> {
    const token = core()?.api?.getToken?.();
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (token) headers.Authorization = `Bearer ${token}`;
    const response = await fetch(base + path, { method, headers, body, credentials: 'same-origin' });
    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        const messages = Object.values(error.errors ?? {}).flat().join(' ');
        throw new Error(messages || error.message || '요청을 처리하지 못했습니다. 다시 확인해주세요.');
    }
    return response;
}

async function run(action: () => Promise<void>): Promise<void> {
    state({ importBusy: true, importError: '' });
    try { await action(); }
    catch (error) { state({ importError: error instanceof Error ? error.message : '요청이 실패했습니다.' }); }
    finally { state({ importBusy: false }); }
}

async function load(id: string): Promise<any> {
    const data = (await (await request('/' + encodeURIComponent(id))).json()).data;
    state({ importRun: data, importJobId: data.id });
    return data;
}

function poll(id: string): void {
    const mine = ++generation;
    const next = async () => {
        if (mine !== generation || !document.getElementById('yutiv_import_page')) return;
        try {
            const data = await load(id);
            if (data.counts.queued + data.counts.processing > 0) setTimeout(next, 2500);
        } catch (error) { state({ importError: (error as Error).message }); }
    };
    setTimeout(next, 2500);
}

async function download(path: string, filename: string): Promise<void> {
    const blob = await (await request(path)).blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a'); link.href = url; link.download = filename;
    link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
}

const handlers: Record<string, (action?: any) => Promise<void>> = {
    template: () => run(() => download('/template', 'yutiv-products-template.xlsx')),
    upload: () => run(async () => {
        const input = document.getElementById('yutiv_import_file') as HTMLInputElement | null;
        const file = input?.files?.[0];
        if (!file || !/\.xlsx$/i.test(file.name) || file.size > 10 * 1024 * 1024) throw new Error('10MB 이하의 .xlsx 파일을 선택하세요.');
        ++generation; state({ importRun: null, importJobId: '' });
        const form = new FormData(); form.append('file', file);
        const data = (await (await request('', 'POST', form)).json()).data;
        state({ importRun: data, importJobId: data.id });
        await handlers.history();
    }),
    confirm: () => run(async () => {
        const id = core().state.getLocal()?.importRun?.id;
        if (!id) return;
        const data = (await (await request('/' + encodeURIComponent(id) + '/confirm', 'POST')).json()).data;
        state({ importRun: data }); poll(id);
    }),
    retry: () => run(async () => {
        const id = core().state.getLocal()?.importRun?.id;
        if (!id) return;
        const data = (await (await request('/' + encodeURIComponent(id) + '/retry', 'POST')).json()).data;
        state({ importRun: data }); poll(id);
    }),
    refresh: () => run(async () => {
        const id = core().state.getLocal()?.importJobId;
        if (id) { await load(id); poll(id); }
    }),
    select: (action) => run(async () => { const id = action.params.id; await load(id); poll(id); }),
    history: () => run(async () => { state({ importHistory: (await (await request('')).json()).data.items }); }),
    result: () => run(async () => {
        const id = core().state.getLocal()?.importRun?.id;
        if (id) await download('/' + encodeURIComponent(id) + '/result', 'yutiv-import-result.xlsx');
    }),
};

let tries = 0;
function register(): void {
    const dispatcher = core()?.getActionDispatcher?.();
    if (!dispatcher) { if (++tries <= 50) setTimeout(register, 100); return; }
    for (const [name, handler] of Object.entries(handlers)) dispatcher.registerHandler(`${identifier}.${name}`, handler, { category: 'plugin', source: identifier });
}
export function initPlugin(): void { register(); }
if (typeof window !== 'undefined') { (window as any).YutivProductImport = { initPlugin }; register(); }
