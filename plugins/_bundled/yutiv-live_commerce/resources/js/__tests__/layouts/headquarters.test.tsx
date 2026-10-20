import React from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { createLayoutTest, createMockComponentRegistryWithBasics, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import layout from '../../../layouts/admin/live_office.json';

let current: ReturnType<typeof createLayoutTest>;
afterEach(() => current?.cleanup());
async function render(local: any, accounts: any[] = []) {
    const registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Form', ({ children }) => <form>{children}</form>);
    registry.register('basic', 'Label', ({ children, text, htmlFor }) => <label htmlFor={htmlFor}>{children || text}</label>);
    const company = { id: 1, name: 'Golfif', slug: 'golfif', status: 'active', timezone: 'Asia/Seoul', reservation_minutes: 60, return_days: 7 };
    current = createLayoutTest({ ...layout, data_sources: [] } as any, {
        componentRegistry: registry as any, queryParams: { tenant: '1' },
        initialData: { office: { data: { tenants: [company], selected: company, accounts, legacy_users: [], staff: [], domains: [], audit: [] } } },
        initialState: { _local: { tenant: company, loadedTenant: '1', ...local } },
    });
    return (await current.render()).container;
}
describe('본사 G7 레이아웃 저장 가드', () => {
    it('선택한 업체 값을 표시하고 다른 업체/로딩 중 상태에서는 저장을 잠근다', async () => {
        const container = await render({ loadedTenant: '2' });
        expect(container.querySelector<HTMLInputElement>('#tenant-name')!.value).toBe('Golfif');
        expect(screen.getAllByRole('button', { name: '저장' })[0]).toBeDisabled();
    });
    it('불러온 업체와 일치하면 업체 저장이 가능하고 계정 없는 권한 변경은 잠긴다', async () => {
        await render({});
        const saves = screen.getAllByRole('button', { name: '저장' });
        expect(saves[0]).toBeEnabled();
        expect(saves[2]).toBeDisabled();
        expect(saves[3]).toBeDisabled();
        expect(screen.getByRole('button', { name: '선택 계정 비밀번호 재설정' })).toBeDisabled();
    });
    it('저장 중 중복 요청을 막고 신규 발급과 재설정 버튼을 구별한다', async () => {
        await render({ busy: true });
        expect(screen.getAllByRole('button', { name: '저장' })[0]).toBeDisabled();
        expect(screen.getByRole('button', { name: '신규 계정 발급' })).toBeDisabled();
        expect(screen.getByRole('button', { name: '선택 계정 비밀번호 재설정' })).toBeDisabled();
    });
});
