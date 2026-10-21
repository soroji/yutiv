import { initIntake } from './products';
export function initReceipts(root = document) {
    root.querySelectorAll('[data-receipt-form]').forEach(form => {
        if(form.hasAttribute('data-workspace-intake')){initIntake(form);return;}
        if (form.dataset.receiptBound) return;
        form.dataset.receiptBound = '1';
        const mode = form.querySelector('[data-receipt-mode]');
        const body = form.querySelector('[data-receipt-rows]');
        const original = JSON.parse(form.querySelector('[data-receipt-options]').textContent);
        const total = form.querySelector('[data-receipt-total]');
        const update = () => {
            let quantity = 0;
            body.querySelectorAll('tr').forEach(row => {
                const input = row.querySelector('[data-receipt-quantity]');
                const selected = !row.querySelector('[data-exclude]').checked;
                const count = Number(input.value);
                quantity += selected ? count : 0;
                row.querySelector('[data-expected]').textContent = String(Number(input.dataset.available) + (selected ? count : 0));
            });
            total.textContent = `이번 입고 총 ${quantity}개 / 최대 200개`;
        };
        const render = options => {
            body.replaceChildren();
            (options.length ? options : [{name:'기본', price:0, discount:0}]).forEach(option => {
                const row = document.createElement('tr');
                const exclude = document.createElement('input'); exclude.type = 'checkbox'; exclude.dataset.exclude = ''; exclude.setAttribute('aria-label',option.name+' 제외');
                let cell = row.insertCell(); cell.append(exclude);
                for (const key of ['name','price','discount']) {
                    cell = row.insertCell(); const input = document.createElement('input');
                    input.dataset.itemField = key; input.value = String(option[key] ?? 0); input.type = key==='name' ? 'text':'number'; input.required = true;
                    input.setAttribute('aria-label',option.name+' '+({name:'옵션명',price:'가격',discount:'할인'}[key]));
                    if (key!=='name') {input.min='0';input.max='1000000000';input.step='1';}
                    input.readOnly = mode.value==='existing'; cell.append(input);
                }
                if (option.option_id) row.dataset.optionId = option.option_id;
                const available = mode.value==='existing' ? Number(option.available || 0):0;
                row.insertCell().textContent = `${available} / ${mode.value==='existing' ? option.reserved || 0 : 0}`;
                const quantity = document.createElement('input'); quantity.type='number';quantity.min='0';quantity.max='200';quantity.step='1';quantity.value='0';quantity.required=true;quantity.dataset.receiptQuantity='';quantity.dataset.available=available;quantity.setAttribute('aria-label',option.name+' 입고 수량');row.insertCell().append(quantity);
                row.insertCell().dataset.expected='';body.append(row);
            });
            update();
        };
        mode.addEventListener('change', () => {
            form.querySelector('[data-receipt-basics]').hidden = mode.value==='existing';
            form.querySelector('[data-combination-controls]').hidden = mode.value==='existing';
            render(original);
        });
        form.querySelector('[data-combinations]').addEventListener('click', () => {
            const names = [...form.querySelectorAll('[data-axis-name]')].map(input=>input.value.trim());
            const values = [...form.querySelectorAll('[data-axis-values]')].map(input=>[...new Set(input.value.split(',').map(value=>value.trim()).filter(Boolean))]);
            if (values.some((axis,i)=>axis.length && !names[i])) {total.textContent='옵션명을 입력하세요.';return;}
            const axes = values.map((axis,i)=>axis.map(value=>`${names[i]}: ${value}`)).filter(axis=>axis.length);
            const options = axes.reduce((rows,axis)=>rows.flatMap(row=>axis.map(value=>[...row,value])),[[]]).map(row=>({name:row.join(' / ') || '기본',price:0,discount:0}));
            if(options.length>50){total.textContent='옵션 조합은 최대 50개입니다.';return;}
            render(options);
        });
        body.addEventListener('input',update);
        form.receiptItems = () => [...body.querySelectorAll('tr')].filter(row=>!row.querySelector('[data-exclude]').checked).map(row=>{
            const item = Object.fromEntries([...row.querySelectorAll('[data-item-field]')].map(input=>[input.dataset.itemField,input.value]));
            item.quantity = row.querySelector('[data-receipt-quantity]').value;
            if(mode.value==='existing' && row.dataset.optionId) item.option_id=Number(row.dataset.optionId);
            return item;
        });
        render(original);
    });
}
