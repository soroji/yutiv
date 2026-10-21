// BigInt preview uses the same six-place rate and transaction half-up rule as PHP.
export function convertedKrw(amount, rate) {
    if (!/^(0|[1-9]\d{0,11})(\.\d{1,2})?$/.test(amount) || !/^(0|[1-9]\d{0,4})(\.\d{1,6})?$/.test(rate)) return null;
    const fixed=(value,scale)=>{const [whole,part='']=value.split('.');return BigInt(whole+part.padEnd(scale,'0'));};
    return (fixed(amount,2)*fixed(rate,6)+50000000n)/100000000n;
}
export function initFinance(root=document) {
    root.querySelectorAll('.finance-print-controls [data-live-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
    root.querySelectorAll('[data-finance-open]').forEach(button=>button.addEventListener('click',()=>document.getElementById(button.dataset.financeOpen)?.showModal()));
    root.querySelectorAll('[data-finance-close]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog')?.close()));
    root.querySelectorAll('[data-finance-form]').forEach(form=>{
        const preview=()=>{const output=form.querySelector('[data-finance-conversion]');const value=convertedKrw(form.elements.namedItem('amount_original')?.value??'',form.elements.namedItem('exchange_rate')?.value??'');output.textContent=value===null?'':`거래 환산액 ${value.toLocaleString('ko-KR')} KRW · 분할 지급은 누적 배분으로 반올림 차액을 조정합니다.`;};
        form.addEventListener('input',preview);preview();
    });
}
