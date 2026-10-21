export function rate(value) {
    if (!/^(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/.test(String(value))) return null;
    const [whole, fraction='']=String(value).split('.');
    return Number(whole)*100+Number(fraction.padEnd(2,'0'));
}
export function equalShares(ids) {
    const ordered=[...ids].sort((a,b)=>Number(a)-Number(b)), base=Math.floor(10000/ids.length), rest=10000%ids.length;
    return Object.fromEntries(ordered.map((id,index)=>[id,((base+(index<rest?1:0))/100).toFixed(2)]));
}
export function estimate(channel, host, participants, basis=100000) {
    const channelAmount=Math.floor(basis*channel/10000), pool=Math.floor(basis*host/10000);
    const rows=participants.map(p=>({...p,amount:Math.floor(pool*p.rate/10000),remainder:pool*p.rate%10000}));
    let left=pool-rows.reduce((a,p)=>a+p.amount,0);
    [...rows].sort((a,b)=>b.remainder-a.remainder || Number(a.id)-Number(b.id)).forEach(p=>{if(left-->0)p.amount++;});
    return {channel:channelAmount,pool,total:channelAmount+pool,rows};
}
export function generatedName(start) {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(start)) return '';
    return start.slice(0,10).replaceAll('-','')+' '+(Number(start.slice(11,13))<12?'오전방송':'오후방송');
}
export function initBroadcasts(root=document) {
    root.querySelectorAll('[data-broadcast-form]').forEach(form=>{
        if(form.dataset.broadcastBound)return;form.dataset.broadcastBound='1';
        const find=s=>form.querySelector(s), name=find('[name=name]'),start=find('[name=starts_at]'),end=find('[name=ends_at]');
        let automatic=form.dataset.new==='1', hosts=JSON.parse(find('[data-selected-hosts]').textContent);
        name.addEventListener('input',()=>{automatic=false;});
        const setName=()=>{const value=generatedName(start.value);if(value)name.value=value;};
        find('[data-broadcast-name]').addEventListener('click',()=>{automatic=true;setName();});
        start.addEventListener('change',()=>{if(automatic)setName();});
        const separate=find('input[type=checkbox][name=separate_sales]'),sales=find('[data-sales-times]');
        const syncSales=()=>{if(!separate.checked){find('[name=sale_starts_at]').value=start.value;find('[name=sale_ends_at]').value=end.value;}};
        start.addEventListener('input',syncSales);end.addEventListener('input',syncSales);
        separate.addEventListener('change',()=>{sales.hidden=!separate.checked;sales.disabled=!separate.checked;syncSales();});
        const channel=find('[name=channel_id]'),info=find('[data-channel-info]');
        const showChannel=()=>{info.replaceChildren();const option=channel.selectedOptions[0];if(!option?.value)return;const title=document.createElement('strong');title.textContent=option.dataset.channelName;const link=document.createElement('a');link.textContent=option.dataset.channelUrl;link.href=option.dataset.channelUrl;link.target='_blank';link.rel='noopener noreferrer';info.append(title,document.createElement('br'),link);};
        // 저장된 채널의 이름/주소만 표시합니다. 작성한 이름·회차 URL·시간·요율은 덮어쓰지 않습니다.
        channel.addEventListener('change',showChannel);showChannel();
        const channelCheck=find('input[type=checkbox][name=channel_payment]'),channelRate=find('[name=channel_percent]'),hostRate=find('[name=host_percent]');
        const estimateNode=find('[data-commission-estimate]');
        function update() {
            const c=channelCheck.checked?rate(channelRate.value):0,h=hosts.length?rate(hostRate.value):0;
            const shares=hosts.map(p=>({...p,rate:rate(p.share)})),sum=shares.reduce((a,p)=>a+(p.rate??0),0);
            const valid=c!==null&&h!==null&&c+h<=10000&&shares.every(p=>p.rate!==null)&&(!hosts.length||sum===10000);
            hostRate.setCustomValidity(valid?'':'수수료 합계는 100% 이하, 쇼호스트 지급 지분 합계는 100%여야 합니다.');
            find('[data-share-total]').textContent='지급 지분 합계 '+(sum/100).toFixed(2)+'%';
            estimateNode.replaceChildren();
            if(!valid){estimateNode.textContent='요율과 지급 지분을 확인하세요. 예상액을 확정할 수 없습니다.';return;}
            const result=estimate(c,h,shares),money=n=>n.toLocaleString('ko-KR')+'원';
            const p=document.createElement('p');p.textContent='계산 대상 매출 100,000원 기준 · 채널 '+money(result.channel)+' · 쇼호스트 전체 '+money(result.pool)+' · 총 예상 '+money(result.total);estimateNode.append(p);
            result.rows.forEach(row=>{const el=document.createElement('p');el.textContent=row.name+' 예상 '+money(row.amount);estimateNode.append(el);});
        }
        function render() {
            const area=find('[data-selected-host-list]');area.replaceChildren();
            find('[data-no-hosts]').hidden=hosts.length>0;find('[data-host-rate]').hidden=!hosts.length;hostRate.disabled=!hosts.length;
            find('[data-multiple-hosts]').hidden=hosts.length<2;
            hosts.forEach(p=>{
                const row=document.createElement('div');row.className='broadcast-participant';row.dataset.participant=p.id;
                const title=document.createElement('strong');title.textContent=p.name;row.append(title);
                const label=document.createElement('label'),input=document.createElement('input');input.name='host_shares['+p.id+']';input.value=p.share;input.inputMode='decimal';input.className='broadcast-rate';input.required=true;input.pattern='(?:100(?:\\.0{1,2})?|[0-9]{1,2}(?:\\.[0-9]{1,2})?)';
                if(hosts.length===1){input.type='hidden';label.textContent='지급 지분 100%';}else label.textContent='지급 지분 (%)';
                input.addEventListener('input',()=>{p.share=input.value;update();});label.append(input);row.append(label);
                const remove=document.createElement('button');remove.type='button';remove.textContent=p.name+' 삭제';remove.addEventListener('click',()=>{hosts=hosts.filter(x=>x.id!==p.id);if(hosts.length===1)hosts[0].share='100.00';render();});row.append(remove);area.append(row);
            });
            form.querySelectorAll('[data-add-host]').forEach(button=>{button.disabled=hosts.some(p=>p.id===button.dataset.addHost);});update();
        }
        form.querySelectorAll('[data-add-host]').forEach(button=>button.addEventListener('click',()=>{if(hosts.some(p=>p.id===button.dataset.addHost))return;hosts.push({id:button.dataset.addHost,name:button.dataset.hostName,share:hosts.length?'0.00':'100.00'});render();}));
        find('[data-host-search]').addEventListener('input',event=>{const term=event.target.value.trim().toLocaleLowerCase();form.querySelectorAll('[data-add-host]').forEach(button=>{button.hidden=!button.dataset.hostName.toLocaleLowerCase().includes(term);});});
        find('[data-equal-shares]').addEventListener('click',()=>{const shares=equalShares(hosts.map(p=>p.id));hosts.forEach(p=>{p.share=shares[p.id];});render();});
        const toggleChannel=()=>{find('[data-channel-rate]').hidden=!channelCheck.checked;channelRate.disabled=!channelCheck.checked;update();};
        channelCheck.addEventListener('change',toggleChannel);channelRate.addEventListener('input',update);hostRate.addEventListener('input',update);render();toggleChannel();
        form.addEventListener('submit',event=>{
            update();end.setCustomValidity(end.value<=start.value?'종료 예정은 시작 예정 이후여야 합니다.':'');
            if(!form.reportValidity()){event.preventDefault();event.stopImmediatePropagation();}
        },true);
        end.addEventListener('input',()=>end.setCustomValidity(''));
        start.addEventListener('input',()=>end.setCustomValidity(''));
    });
}
