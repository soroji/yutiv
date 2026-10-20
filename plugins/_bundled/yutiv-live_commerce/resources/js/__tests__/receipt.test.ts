import { beforeEach, describe, expect, it } from 'vitest';
import { initReceipts } from '../receipt';

function mount(options = []) {
    document.body.innerHTML = `<form data-receipt-form><select data-receipt-mode><option value="new">신규</option><option value="existing">추가 입고</option><option value="copy">복사</option></select><div data-receipt-basics></div><div data-combination-controls><input data-axis-name value="색상"><input data-axis-values><input data-axis-name value="사이즈"><input data-axis-values><button type="button" data-combinations>조합 생성</button></div><table><tbody data-receipt-rows></tbody></table><script type="application/json" data-receipt-options></script><p data-receipt-total></p></form>`;
    document.querySelector('[data-receipt-options]')!.textContent = JSON.stringify(options);
    initReceipts();
    return document.querySelector('form') as HTMLFormElement & { receiptItems: () => any[] };
}
describe('입고 표의 실제 입력 흐름', () => {
    beforeEach(() => document.body.replaceChildren());
    it('색상×사이즈를 만들고 제외한 조합은 수량과 제출에서 빠진다', () => {
        const form = mount();
        const axes = form.querySelectorAll<HTMLInputElement>('[data-axis-values]');
        axes[0].value = '검정, 흰색'; axes[1].value = 'S, M';
        form.querySelector<HTMLButtonElement>('[data-combinations]')!.click();
        expect(form.querySelectorAll('tbody tr')).toHaveLength(4);
        form.querySelectorAll<HTMLInputElement>('[data-receipt-quantity]').forEach(input => input.value = '2');
        const excluded = form.querySelector<HTMLInputElement>('[data-exclude]')!; excluded.checked = true;
        excluded.dispatchEvent(new Event('input', { bubbles: true }));
        expect(form.receiptItems()).toHaveLength(3);
        expect(form.querySelector('[data-receipt-total]')!.textContent).toContain('총 6개');
    });
    it('옵션 없는 상품은 기본 옵션으로 제출하고 조합 상한 초과 시 기존 입력을 보존한다', () => {
        const form = mount();
        expect(form.receiptItems()[0].name).toBe('기본');
        const axes = form.querySelectorAll<HTMLInputElement>('[data-axis-values]');
        axes[0].value = Array.from({ length: 51 }, (_, i) => String(i)).join(',');
        form.querySelector<HTMLButtonElement>('[data-combinations]')!.click();
        expect(form.receiptItems()[0].name).toBe('기본');
        expect(form.querySelector('[data-receipt-total]')!.textContent).toContain('최대 50개');
    });
    it('재입고는 기존 ID와 재고를 사용하고 복사는 ID·재고를 가져가지 않는다', () => {
        const form = mount([{ option_id: 7, name: '기본', price: 10000, discount: 1000, available: 3, reserved: 2 }]);
        const mode = form.querySelector<HTMLSelectElement>('[data-receipt-mode]')!;
        mode.value = 'existing'; mode.dispatchEvent(new Event('change'));
        expect(form.receiptItems()[0].option_id).toBe(7);
        expect(form.querySelector('[data-expected]')!.textContent).toBe('3');
        mode.value = 'copy'; mode.dispatchEvent(new Event('change'));
        expect(form.receiptItems()[0].option_id).toBeUndefined();
        expect(form.querySelector('[data-expected]')!.textContent).toBe('0');
    });
});
