import qrcode from 'qrcode-generator';
import '../css/live.css';
import '../css/products.css';
import '../css/finance.css';
import { initFinance } from './finance';
import { initReceipts } from './receipt';
import { initProducts } from './products';

export function qrSvg(value) {
    const qr = qrcode(0, 'M'); qr.addData(value); qr.make();
    return qr.createSvgTag({ cellSize: 4, margin: 16, scalable: true });
}
function operationKey() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    return [...bytes].map(byte=>byte.toString(16).padStart(2,'0')).join('');
}
function message(form, text, error = false) {
    const node = form.querySelector('[data-live-message]');
    if (node) { node.textContent = text; node.setAttribute('role', error ? 'alert' : 'status'); node.dataset.state = error ? 'error' : 'success'; }
}
function payload(form) {
    const result = {};
    for (const [name, value] of new FormData(form)) {
        if (name === '_token') continue;
        if (name.endsWith('[]')) (result[name.slice(0,-2)] ||= []).push(value);
        else if (name.startsWith('host_shares[')) { (result.host_shares ||= {})[name.slice(12,-1)] = value; }
        else result[name] = value;
    }
    if (typeof result.qrs === 'string') result.qrs = result.qrs.split(/[\s,]+/).filter(Boolean);
    for (const key of ['active','primary','default','published','sellable','locked','customer_visible','acknowledge_incomplete']) if (key in result) result[key] = result[key] === '1';
    if (form.receiptItems) result.items = form.receiptItems();
    if (form.receiptAxes) result.axes = form.receiptAxes();
    return result;
}
function attachForm(form) {
    if (form.dataset.liveBound) return; form.dataset.liveBound = '1';
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (form.dataset.busy) return;
        if (!navigator.onLine) { message(form, '오프라인 상태입니다. 연결 후 다시 시도해 주세요.', true); return; }
        const buttons = [...form.querySelectorAll('button[type=submit]')]; form.dataset.busy = '1'; buttons.forEach(b=>b.disabled=true); message(form,'저장 중입니다…');
        try {
            const upload=form.hasAttribute('data-live-upload');
            const headers={'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content || ''};
            if (!upload) headers['Content-Type']='application/json';
            const response = await fetch(form.action, {method:'POST',credentials:'same-origin',headers,body:upload ? new FormData(form) : JSON.stringify(payload(form))});
            const body = await response.json();
            if (!response.ok) { const first = Object.values(body.errors || {}).flat()[0]; throw new Error(first || body.message || (response.status===403?'이 작업의 권한이 없습니다.':'입력과 현재 업무 상태를 확인하고 다시 시도해 주세요.')); }
            const data = body.data || {}; message(form, data.message || (data.state==='review'?'금액/재고 검토가 필요합니다. 출고할 수 없습니다.':'저장했습니다. 새로고침하면 최신 내용을 확인할 수 있습니다.'));
            if (data.temporary_password) { message(form, `임시 비밀번호: ${data.temporary_password} — 지금 복사하여 안전하게 전달하세요. 30초 후 표시를 닫습니다.`); setTimeout(()=>message(form,'임시 비밀번호 표시를 닫았습니다.'),30000); }
            if (data.order) { const link = document.createElement('a'); link.href='/orders/'+encodeURIComponent(data.order); link.textContent='주문 및 입금 안내 보기'; form.querySelector('[data-live-message]')?.append(link); }
            if (data.units) { const link=document.createElement('a');link.href='/seller/labels?receipt='+encodeURIComponent(data.units[0]);link.textContent='입고 QR 라벨 출력';form.querySelector('[data-live-message]')?.append(link); }
            if (data.receipt_id) { window.location.assign(data.return_url || '/seller/receipts?batch='+encodeURIComponent(data.receipt_id)); return; }
            if(form.hasAttribute('data-stock-count')&&data.return_url){window.location.assign(data.return_url);return;}
            if(form.hasAttribute('data-workspace-refresh'))window.location.reload();
            if (data.code && form.dataset.customs) { message(form, '통관부호: '+data.code+' (형식 등록, 외부 유효성 미확인)'); setTimeout(()=>message(form,'조회 표시를 닫았습니다.'),30000); }
            if(data.image_url){let preview=form.querySelector('[data-image-preview]');if(!preview){preview=document.createElement('img');preview.dataset.imagePreview='1';preview.alt='저장한 이미지 미리보기';preview.style.maxWidth='200px';form.append(preview);}preview.src=data.image_url+'?v='+Date.now();}
        } catch (error) { form.querySelectorAll('[data-product-status]').forEach(input=>input.checked=input.defaultChecked);message(form, error instanceof Error ? error.message : '처리하지 못했습니다. 다시 시도해 주세요.', true); }
        finally { delete form.dataset.busy; buttons.forEach(b=>b.disabled=false); }
    });
}
async function camera(button) {
    const area=button.closest('[data-live-scanner]'), status=area.querySelector('[data-scan-status]'), video=area.querySelector('video'), input=area.querySelector('[data-qr-input]');
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia || !window.BarcodeDetector) { status.textContent='카메라 QR 스캔을 지원하지 않습니다. QR 식별번호를 직접 입력해 주세요.'; return; }
    try {
        const detector=new window.BarcodeDetector({formats:['qr_code']});
        const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});video.srcObject=stream;video.hidden=false;await video.play();
        status.textContent='QR을 화면 안에 맞춰 주세요. 스캔은 출고 확정이 아닙니다.';
        const stop=()=>{stream.getTracks().forEach(t=>t.stop());video.hidden=true};
        const tick=async()=>{if(!video.isConnected){stop();return;}try{const codes=await detector.detect(video);if(codes.length){const value=codes[0].rawValue;if(input.value.split(/\s+/).includes(value)){status.textContent='이미 스캔한 QR입니다.'}else{input.value+=(input.value?'\n':'')+value;status.textContent='스캔했습니다. 주문과 수량을 확인한 뒤 확정해 주세요.'}stop();return;}requestAnimationFrame(tick)}catch{stop();status.textContent='스캔하지 못했습니다. 직접 입력해 주세요.'}};tick();window.addEventListener('pagehide',stop,{once:true});
    } catch { status.textContent='카메라를 사용할 수 없습니다. 권한을 확인하거나 QR 식별번호를 직접 입력해 주세요.'; }
}
export function initLive(root=document) {
    initReceipts(root);
    initProducts(root);
    root.querySelectorAll('[data-ledger-print]').forEach(button=>button.addEventListener('click',()=>{document.body.classList.add('live-print-ledger');window.print();}));
    window.addEventListener('afterprint',()=>document.body.classList.remove('live-print-ledger'));
    root.querySelectorAll('[data-live-menu]').forEach(button=>{if(!button.dataset.bound){button.dataset.bound='1';button.addEventListener('click',()=>{const open=button.getAttribute('aria-expanded')!=='true';button.setAttribute('aria-expanded',String(open));button.closest('header').toggleAttribute('data-menu-open',open);});}});
    root.querySelectorAll('form[data-live-form]').forEach(attachForm);
    root.querySelectorAll('[data-qr]').forEach(el=>{if(!el.dataset.qrRendered){el.innerHTML=qrSvg(el.dataset.qr);el.dataset.qrRendered='1';}});
    root.querySelectorAll('[data-camera]').forEach(b=>{if(!b.dataset.bound){b.dataset.bound='1';b.addEventListener('click',()=>camera(b));}});
    root.querySelectorAll('[data-scan-preview]').forEach(button=>button.addEventListener('click',async()=>{
        const form=button.closest('form'),area=form.querySelector('[data-scan-results]'),data=payload(form);
        const qrs=(data.qrs || String(data.qr || '').split(/[\s,]+/).filter(Boolean));
        button.disabled=true;area.textContent='스캔 목록을 확인하고 있습니다…';
        try {
            if (!navigator.onLine) throw new Error('오프라인 상태입니다. 연결 후 다시 확인해 주세요.');
            const response=await fetch('/seller/tasks/scan',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content||''},body:JSON.stringify({qrs,order_id:data.order_id || null})});
            const body=await response.json();if(!response.ok)throw new Error(Object.values(body.errors || {}).flat()[0] || body.message || '주문과 물품 상태를 확인해 주세요.');
            const result=body.data;area.textContent=`확인 ${result.scanned}개${result.remaining===null ? '' : ' / 출고 대기 '+result.remaining+'개'}${result.partial ? ' · 부분 출고입니다.' : ''} · 상태는 아직 변경하지 않았습니다.`;
            for(const item of result.items){const p=document.createElement('p');p.textContent=`${item.product} · ${item.option} · ${item.uuid.slice(0,8)} · ${item.state} · ${item.location}`;area.append(p);}
        } catch(error){area.textContent=error.message;area.setAttribute('role','alert');}finally{button.disabled=false;}
    }));
    root.querySelectorAll('[data-print]').forEach(b=>b.addEventListener('click',()=>window.print()));
    root.querySelectorAll('[data-label-print]').forEach(button=>button.addEventListener('click',async()=>{
        const status=root.querySelector('[data-label-status]');
        const labels=[...root.querySelectorAll('[data-label-unit]')];
        const selected=labels.filter(label=>label.querySelector('[data-label-select]').checked);
        if (!selected.length) { status.textContent='출력할 물품을 선택해 주세요.'; return; }
        for (const label of selected) {
            const text=label.querySelector('.live-label-text');
            for(let size=8;size>=6;size-=.25){text.style.fontSize=size+'pt';if(text.scrollHeight<=text.clientHeight && text.scrollWidth<=text.clientWidth)break;}
            if(text.scrollHeight>text.clientHeight || text.scrollWidth>text.clientWidth){status.textContent='상품명·옵션이 라벨 규격을 초과합니다. 용지 크기를 늘린 뒤 다시 출력하세요.';return;}
        }
        button.disabled=true;
        try {
            const response=await fetch('/seller/tasks/label-print',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content||''},body:JSON.stringify({operation_key:operationKey(),qrs:selected.map(label=>label.dataset.labelUnit),width:Number(button.dataset.width),height:Number(button.dataset.height),...(button.dataset.batch ? {batch:Number(button.dataset.batch)} : {})})});
            if (!response.ok) throw new Error('인쇄 요청 이력을 저장하지 못했습니다. 권한과 연결을 확인하고 다시 시도해 주세요.');
            labels.forEach(label=>label.toggleAttribute('data-unselected',!selected.includes(label)));
            status.textContent=`선택 ${selected.length}개 / 라벨 ${selected.length}장. 인쇄 요청을 기록했습니다. 대화상자에서 프린터·용지·배율을 확인해 주세요.`;
            window.print();
        } catch(error) {status.textContent=error.message;} finally{button.disabled=false;}
    }));
    root.querySelectorAll('[data-copy]').forEach(b=>b.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(b.dataset.copy);b.textContent='복사했습니다'}catch{b.textContent='복사하지 못했습니다. 주소를 직접 선택해 주세요.'}}));
}
if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',()=>initFinance(document)); else initFinance(document);
if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',()=>initLive()); else initLive();

// Keep File outside layout expression serialization; use G7's authenticated API client.
// Delegation supports admin SPA navigation after the plugin asset has loaded.
document.addEventListener('click', async event => {
    const button = event.target.closest?.('[data-live-hq-logo-save]');
    if (!button || button.disabled) return;
    const input = document.getElementById('live-hq-logo');
    let status = document.getElementById('live-hq-logo-status');
    if (!status) {
        status = document.createElement('p'); status.id = 'live-hq-logo-status';
        status.setAttribute('role', 'status'); button.after(status);
    }
    if (!input?.files?.[0]) { status.textContent = '로고 파일을 선택해 주세요.'; return; }
    if (!navigator.onLine) { status.textContent = '오프라인 상태입니다. 연결 후 다시 시도해 주세요.'; return; }
    button.disabled = true; status.textContent = '로고를 저장하고 있습니다…';
    try {
        const body = new FormData(); body.append('image', input.files[0]);
        await window.G7Core.api.post('/api/plugins/yutiv-live_commerce/admin/' + encodeURIComponent(button.dataset.liveHqLogoSave) + '/logo', body, {
            headers: { 'Content-Type': 'multipart/form-data' }
        });
        status.textContent = '로고를 저장했습니다. 새로고침하면 적용된 로고를 확인할 수 있습니다.';
        input.value = '';
    } catch {
        status.textContent = '로고를 저장하지 못했습니다. 파일 형식·용량·권한과 연결을 확인해 주세요.';
        status.setAttribute('role', 'alert');
    } finally { button.disabled = false; }
});
// 임시 비밀번호는 G7 로컬 상태에만 두고 30초 후 제거합니다.
let passwordTimer, shownPassword='';
new MutationObserver(()=>{
    if(!document.querySelector('.live-admin-office'))return;
    const password=window.G7Core?.state?.getLocal?.()?.temporaryPassword || '';
    if(password===shownPassword)return;
    shownPassword=password;clearTimeout(passwordTimer);
    if(password)passwordTimer=setTimeout(()=>{window.G7Core?.state?.setLocal({temporaryPassword:''});shownPassword='';},30000);
}).observe(document.documentElement,{childList:true,subtree:true,characterData:true});
