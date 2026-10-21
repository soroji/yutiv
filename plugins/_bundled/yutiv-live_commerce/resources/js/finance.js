// BigInt preview uses the same six-place rate and transaction half-up rule as PHP.
export function convertedKrw(amount, rate) {
    if (!/^(0|[1-9]\d{0,11})(\.\d{1,2})?$/.test(amount) || !/^(0|[1-9]\d{0,4})(\.\d{1,6})?$/.test(rate)) return null;
    const fixed=(value,scale)=>{const [whole,part='']=value.split('.');return BigInt(whole+part.padEnd(scale,'0'));};
    return (fixed(amount,2)*fixed(rate,6)+50000000n)/100000000n;
}
export function initFinance(root=document) {
    root.querySelectorAll('[data-finance-scroll]').forEach(wrap=>{
        if(wrap.dataset.scrollBound)return;wrap.dataset.scrollBound='1';
        const area=wrap.querySelector('.finance-ledger-scroll');
        const update=()=>{const max=area.scrollWidth-area.clientWidth;wrap.dataset.overflow=String(max>1);wrap.dataset.atStart=String(area.scrollLeft<=1);wrap.dataset.atEnd=String(area.scrollLeft>=max-1);};
        area.addEventListener('scroll',update,{passive:true});
        if(typeof ResizeObserver!=='undefined'){const observer=new ResizeObserver(update);observer.observe(area);observer.observe(area.querySelector('table'));}else window.addEventListener('resize',update);
        update();
    });
    const printExpanded=new Set();
    window.addEventListener('beforeprint',()=>root.querySelectorAll('.finance-detail-analysis,.finance-metadata').forEach(details=>{if(details.closest('.finance-dashboard'))return;if(!details.open){printExpanded.add(details);details.open=true;}}));
    window.addEventListener('afterprint',()=>{printExpanded.forEach(details=>details.open=false);printExpanded.clear();});
    root.querySelectorAll('[data-finance-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
    root.querySelectorAll('.finance-print-controls [data-live-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
    root.querySelectorAll('[data-finance-open]').forEach(button=>{if(button.dataset.financeBound)return;button.dataset.financeBound='1';button.addEventListener('click',()=>{const dialog=document.getElementById(button.dataset.financeOpen);if(dialog){dialog.showModal();dialog.querySelector('input:not([type=hidden]),select,textarea,button')?.focus();}});});
    root.querySelectorAll('[data-finance-close]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog')?.close()));
    root.querySelectorAll('dialog.finance-dialog').forEach(dialog=>dialog.addEventListener('click',event=>{if(event.target===dialog){const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)dialog.close();}}));
    root.querySelectorAll('[data-finance-form]').forEach(form=>{
        const preview=()=>{const output=form.querySelector('[data-finance-conversion]');const value=convertedKrw(form.elements.namedItem('amount_original')?.value??'',form.elements.namedItem('exchange_rate')?.value??'');output.textContent=value===null?'':`거래 환산액 ${value.toLocaleString('ko-KR')} KRW · 분할 지급은 누적 배분으로 반올림 차액을 조정합니다.`;};
        form.addEventListener('input',preview);preview();
    });
}
