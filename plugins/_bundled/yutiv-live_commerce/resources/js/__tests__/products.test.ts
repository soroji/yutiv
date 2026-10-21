import {describe,it,expect,vi} from 'vitest';
import {initIntake} from '../products';
function mount(original=[],mode='new'){
 document.body.innerHTML=`<form><input data-receipt-mode value="${mode}"><div data-axis-rows></div><button type="button" data-axis-add></button><button type="button" data-combinations></button><input name="price_mode" type="radio" value="same" checked><input name="price_mode" type="radio" value="individual"><div data-common-prices><input data-common="unit_cost"><input data-common="price"><input data-common="discount"><button type="button" data-prices-apply></button></div><table><tbody data-receipt-rows></tbody></table><script data-receipt-options type="application/json"></script><p data-receipt-total></p></form>`;
 document.querySelector('[data-receipt-options]').textContent=JSON.stringify(original);const form=document.querySelector('form');vi.spyOn(window,'confirm').mockReturnValue(true);initIntake(form);return form;
}
const input=(selector,value)=>{const e=document.querySelector(selector);e.value=value;e.dispatchEvent(new Event('input',{bubbles:true}));};
describe('입고 편집 실제 입력',()=>{
 it('기본 옵션은 단가 누락과 0원을 구분하며 큰 금액을 정수로 계산한다',()=>{
 const form=mount();expect(form.receiptItems()[0].unit_cost).toBe('');input('[data-item-field=unit_cost]','1000000000');input('[data-receipt-quantity]','200');expect(form.querySelector('[data-receipt-total]').textContent).toContain('200,000,000,000원');input('[data-item-field=unit_cost]','0');expect(form.receiptItems()[0].unit_cost).toBe('0');
 });
 it('세 종류 조합을 재생성해도 입력을 유지하고 제거 확인 취소가 가능하다',()=>{
 const form=mount();input('[data-axis-values]','검정,흰색');for(const [name,value]of[['크기','S,M'],['핏','정핏']]){form.querySelector('[data-axis-add]').click();const row=form.querySelector('[data-axis-rows]').lastElementChild;row.querySelector('[data-axis-name]').value=name;row.querySelector('[data-axis-values]').value=value;}form.querySelector('[data-combinations]').click();expect(form.receiptItems()).toHaveLength(4);input('[data-item-field=unit_cost]','7000');input('[data-receipt-quantity]','2');form.querySelector('[data-combinations]').click();expect(form.receiptItems()[0].unit_cost).toBe('7000');input('[data-axis-values]','흰색');vi.mocked(window.confirm).mockReturnValueOnce(false);form.querySelector('[data-combinations]').click();expect(form.receiptItems()).toHaveLength(4);
 });
 it('가격 모드는 값을 보존하고 공통 적용만 바꾸며 기존 판매가는 보호한다',()=>{
 const form=mount([{option_id:7,name:'기본',price:15000,discount:1000,available:3,reserved:2}],'existing');input('[data-receipt-quantity]','1');input('[data-item-field=unit_cost]','5000');const radio=form.querySelector('[value=individual]');radio.checked=true;radio.dispatchEvent(new Event('change'));expect(form.receiptItems()[0].unit_cost).toBe('5000');input('[data-common=unit_cost]','6000');input('[data-common=price]','1');form.querySelector('[data-prices-apply]').click();expect(form.receiptItems()[0].unit_cost).toBe('6000');expect(form.receiptItems()[0].price).toBe('15000');expect(form.receiptItems()[0].option_id).toBe(7);
 });
 it('종류 4개 및 조합 50개 상한 초과 시 기존 입력을 유지한다',()=>{
 const form=mount();for(let i=0;i<5;i++)form.querySelector('[data-axis-add]').click();expect(form.querySelector('[data-axis-rows]').children).toHaveLength(4);[...form.querySelector('[data-axis-rows]').children].forEach((r,i)=>{r.querySelector('[data-axis-name]').value='종류'+i;r.querySelector('[data-axis-values]').value='a,b,c';});form.querySelector('[data-combinations]').click();expect(form.receiptItems()).toHaveLength(1);expect(form.querySelector('[data-receipt-total]').textContent).toContain('최대50개');
 });
});
