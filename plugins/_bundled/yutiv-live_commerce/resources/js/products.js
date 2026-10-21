import { convertedKrw } from './finance';
const normalized=value=>String(value).trim().toLocaleLowerCase();
const identity=item=>item.attributes&&Object.keys(item.attributes).length?JSON.stringify(Object.entries(item.attributes).map(([k,v])=>[normalized(k),normalized(v)]).sort((a,b)=>a[0].localeCompare(b[0]))):normalized(item.name);
const integer=value=>/^\d+$/.test(String(value))?BigInt(value):null;
const operationKey=()=>[...crypto.getRandomValues(new Uint8Array(16))].map(x=>x.toString(16).padStart(2,'0')).join('');

export function initIntake(form){
 if(form.dataset.intakeBound)return;form.dataset.intakeBound='1';
 const body=form.querySelector('[data-receipt-rows]'),total=form.querySelector('[data-receipt-total]'),mode=form.querySelector('[data-receipt-mode]').value,original=JSON.parse(form.querySelector('[data-receipt-options]').textContent||'[]'),axes=form.querySelector('[data-axis-rows]');let axisId=0;
 let appliedAxes=[];
 function axis(name='',values=''){
  if(!axes||axes.children.length>=4){total.textContent='옵션 종류는 최대4개입니다.';return;}
  const row=document.createElement('div');row.className='product-axis';
  for(const [kind,text,value] of [['Name','옵션명',name],['Values','옵션값 (쉼표 구분)',values]]){const label=document.createElement('label'),input=document.createElement('input');label.textContent=text;input.value=value;input.dataset['axis'+kind]='';input.id='axis-'+(++axisId);label.htmlFor=input.id;input.maxLength=kind==='Name'?30:1000;label.append(input);row.append(label);}
  const remove=document.createElement('button');remove.type='button';remove.textContent='삭제';remove.addEventListener('click',()=>row.remove());row.append(remove);axes.append(row);
 }
 function values(){return [...body.rows].map(row=>({name:row.querySelector('[data-item-field=name]').value,price:row.querySelector('[data-item-field=price]').value,discount:row.querySelector('[data-item-field=discount]').value,unit_cost:row.querySelector('[data-item-field=unit_cost]').value,quantity:row.querySelector('[data-receipt-quantity]').value,option_id:row.dataset.optionId?Number(row.dataset.optionId):undefined,attributes:JSON.parse(row.dataset.attributes||'null'),excluded:row.querySelector('[data-exclude]').checked}));}
 function update(){
  let quantity=0n,amount=0n,valid=true;const currency=form.querySelector('[name=currency]')?.value??'KRW',rate=form.querySelector('[name=exchange_rate]')?.value??'1';
  form.querySelectorAll('[data-item-field=unit_cost],[data-common=unit_cost]').forEach(input=>input.step=currency==='CNY'?'0.01':'1');
  for(const row of body.rows){
   const excluded=row.querySelector('[data-exclude]').checked,q=integer(row.querySelector('[data-receipt-quantity]').value),raw=row.querySelector('[data-item-field=unit_cost]').value;
   row.querySelectorAll('input:not([type=checkbox])').forEach(input=>input.required=!excluded&&(mode!=='existing'||q!==0n));
   let krw=null;if(q!==null&&/^(0|[1-9]\d{0,9})(\.\d{1,2})?$/.test(raw)&&(currency==='CNY'||integer(raw)!==null)){
    const [whole,fraction='']=raw.split('.'),minor=BigInt(whole+fraction.padEnd(2,'0'))*q;krw=convertedKrw((minor/100n).toString()+'.'+(minor%100n).toString().padStart(2,'0'),rate);
   }
   row.querySelector('[data-item-amount]').textContent=krw===null?'미입력':krw.toLocaleString('ko-KR')+'원';
   if(!excluded&&(mode!=='existing'||q!==0n)){if(q===null||krw===null)valid=false;else{quantity+=q;amount+=krw;}}
  }
  total.textContent=`이번 입고 총수량 ${quantity.toLocaleString('ko-KR')}개 · 총 매입금액 ${valid?amount.toLocaleString('ko-KR')+'원':'입고단가/수량/환율을 확인하세요'}`;
 }

 function render(options){const old=new Map(values().map(item=>[identity(item),item]));body.replaceChildren();
  for(const candidate of options){const item={...candidate,...old.get(identity(candidate))},source=original.find(o=>identity(o)===identity(item)||normalized(o.name)===normalized(item.name));if(mode==='existing'&&source)item.option_id=source.option_id;
   const row=body.insertRow();if(mode==='existing'&&item.option_id)row.dataset.optionId=item.option_id;row.dataset.attributes=JSON.stringify(item.attributes??null);
   const exclude=document.createElement('input');exclude.type='checkbox';exclude.dataset.exclude='';exclude.checked=!!item.excluded;exclude.setAttribute('aria-label',item.name+' 제외');row.insertCell().append(exclude);
   for(const field of ['name','unit_cost','price','discount']){const input=document.createElement('input');input.dataset.itemField=field;input.value=item[field]??(field==='unit_cost'?'':field==='name'?'기본':0);input.setAttribute('aria-label',item.name+' '+({name:'옵션명',unit_cost:'입고단가',price:'판매가격',discount:'할인금액'}[field]));if(field!=='name'){input.type='number';input.min='0';input.max='1000000000';input.step='1';}else input.maxLength=200;if(mode==='existing'&&item.option_id&&['name','price','discount'].includes(field))input.readOnly=true;row.insertCell().append(input);}
   row.insertCell().textContent=(mode==='existing'?source?.available??0:0)+' / '+(mode==='existing'?source?.reserved??0:0);
   const q=document.createElement('input');q.type='number';q.min='0';q.max='200';q.step='1';q.value=item.quantity??0;q.dataset.receiptQuantity='';q.setAttribute('aria-label',item.name+' 이번 입고 수량');row.insertCell().append(q);row.insertCell().dataset.itemAmount='';
  }update();
 }
 form.querySelector('[data-axis-add]')?.addEventListener('click',()=>axis());axis('색상','');
 form.receiptAxes=()=>appliedAxes;
 const readAxes=()=>[...(axes?.children??[])].map(row=>({name:row.querySelector('[data-axis-name]').value.trim(),values:row.querySelector('[data-axis-values]').value.split(',').map(v=>v.trim())})).filter(row=>row.name||row.values.some(Boolean));
 form.querySelector('[data-combinations]')?.addEventListener('click',()=>{const specs=readAxes();if(specs.some(a=>!a.name||a.values.length>20||a.values.some(v=>!v)||new Set(a.values.map(normalized)).size!==a.values.length)||new Set(specs.map(a=>normalized(a.name))).size!==specs.length){total.textContent='옵션명·값의 빈 칸/중복을 확인하세요. 종류별 최대20값입니다.';return;}let combinations=[{}];for(const a of specs){if(combinations.length*a.values.length>50){total.textContent='옵션 조합은 최대50개입니다.';return;}combinations=combinations.flatMap(previous=>a.values.map(value=>({...previous,[a.name]:value})));}const result=combinations.map(attributes=>({name:Object.entries(attributes).map(([n,v])=>n+': '+v).join(' / ')||'기본',attributes,price:0,discount:0})),keep=new Set(result.map(identity));if(values().some(r=>!keep.has(identity(r))&&(r.unit_cost!==''||r.quantity!=='0'))&&!window.confirm('제외되는 조합의 입력을 입고 표에서 제거할까요? 기존 저장 옵션·주문은 삭제하지 않습니다.'))return;appliedAxes=specs;render(result);});
 form.querySelectorAll('[name=price_mode]').forEach(radio=>radio.addEventListener('change',()=>form.querySelector('[data-common-prices]').hidden=radio.value!=='same'));
 form.querySelector('[data-prices-apply]')?.addEventListener('click',()=>{const fields=[...form.querySelectorAll('[data-common]')].filter(i=>i.value!=='');if(fields.some(input=>[...body.querySelectorAll(`[data-item-field=${input.dataset.common}]:not([readonly])`)].some(field=>field.value!==''&&field.value!==input.value))&&!window.confirm('서로 다른 옵션 가격을 입력한 공통 값으로 바꿀까요?'))return;fields.forEach(input=>body.querySelectorAll(`[data-item-field=${input.dataset.common}]:not([readonly])`).forEach(field=>field.value=input.value));update();});
 form.querySelector('[data-receipt-currency]')?.addEventListener('change',update);form.querySelector('[data-receipt-rate]')?.addEventListener('input',update);body.addEventListener('input',update);body.addEventListener('change',update);
 form.receiptItems=()=>values().filter(item=>!item.excluded&&(mode!=='existing'||integer(item.quantity)!==0n)).map(({excluded,...item})=>{if(mode!=='existing')delete item.option_id;return item;});render(original.length?original:[{name:'기본',price:0,discount:0}]);
}

export function initProducts(root=document){
 root.querySelector('[data-product-page-select]')?.addEventListener('change',e=>root.querySelectorAll('[name="products[]"]').forEach(i=>i.checked=e.target.checked));
 const select=root.querySelector('[data-product-qr-select]');if(select){const status=select.querySelector('[data-label-selection-total]');const update=()=>{const count=select.querySelectorAll('[name="qrs[]"]:checked').length,copies=Number(select.querySelector('[name=copies]').value);status.textContent=`선택 ${count}개 · 총 라벨 ${count*copies}장 (한 번에 최대200장)`;};select.addEventListener('change',update);select.querySelector('[data-unit-page-select]')?.addEventListener('change',e=>{select.querySelectorAll('[name="qrs[]"]').forEach(i=>i.checked=e.target.checked);update();});select.addEventListener('submit',e=>{const count=select.querySelectorAll('[name="qrs[]"]:checked').length;if(!count||count*Number(select.querySelector('[name=copies]').value)>200){e.preventDefault();status.textContent='물품을 선택하고 최대200장 이내로 나누어 출력하세요.';}});update();}
 const print=root.querySelector('[data-workspace-print]');if(print){const data=JSON.parse(root.querySelector('[data-workspace-print-data]').textContent),status=root.querySelector('[data-workspace-print-status]'),again=root.querySelector('[data-workspace-reprint]');let operation=operationKey(),nextOriginal=null;
  print.addEventListener('click',async()=>{if(print.disabled)return;print.disabled=true;try{for(const text of root.querySelectorAll('.live-label-text')){for(let size=8;size>=6;size-=.25){text.style.fontSize=size+'pt';if(text.scrollHeight<=text.clientHeight&&text.scrollWidth<=text.clientWidth)break;}if(text.scrollHeight>text.clientHeight||text.scrollWidth>text.clientWidth)throw Error('문구가 라벨을 초과합니다. 용지를 키워 미리보기를 다시 여세요.');}const response=await fetch('/seller/product-actions/print',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':root.querySelector('meta[name=csrf-token]')?.content||''},body:JSON.stringify({...data,operation_key:operation})});const body=await response.json();if(!response.ok)throw Error(Object.values(body.errors||{}).flat()[0]||'인쇄 요청 이력을 저장하지 못했습니다.');nextOriginal=body.data.request_id;status.textContent=`인쇄 요청 #${body.data.request_id} · ${body.data.total_labels}장 기록됨. 실제 인쇄 성공 확인은 아닙니다.`;again.hidden=false;window.print();}catch(error){status.textContent=error.message;status.setAttribute('role','alert');print.disabled=false;}});
  again.addEventListener('click',()=>{data.original_id=nextOriginal;operation=operationKey();again.hidden=true;print.disabled=false;status.textContent='의도적인 재출력입니다. 인쇄 요청 버튼을 누르세요.';});
 }
}
