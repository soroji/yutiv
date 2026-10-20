const {chromium}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(!process.cwd().replaceAll('\\','/').includes('/live-workflow-browser'))throw Error('Isolated checkout required');
const out=path.resolve('ledger040-evidence');fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true,args:['--host-resolver-rules=MAP teewide.test 127.0.0.1']});
 const context=await browser.newContext({locale:'ko-KR',viewport:{width:1440,height:1000}});
 await context.route('http://teewide.test/**',route=>{const u=new URL(route.request().url());u.port='18973';return route.fulfill({status:307,headers:{location:u.href}});});
 const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const base='http://teewide.test:18973',fixture=JSON.parse(fs.readFileSync('ledger040-fixture.json','utf8'));
 await page.goto(base+'/seller/login');await page.locator('form.tw-form').evaluate((f,b)=>f.action=b+'/login',base);
 await page.locator('[name=email]').fill('operator');await page.locator('[name=password]').fill('Synthetic-only-1234!');
 await Promise.all([page.waitForURL(u=>!u.pathname.includes('login')),page.locator('button[type=submit]').click()]);
 const totals={};
 for(const [period,to] of [['day','2026-10-01'],['week','2026-10-07'],['month','2026-10-31']]){
  const response=await page.goto(`${base}/seller/ledger?from=2026-10-01&to=${to}&period=${period}&product_id=${fixture.product}`);assert.equal(response.status(),200);
  assert.equal(await page.locator('.live-stock-ledger tbody tr').count(),20);
  totals[period]=await page.locator('[data-period-total]').innerText();
  await page.screenshot({path:path.join(out,`live-${period}.png`),fullPage:true});
 }
 await page.locator('a',{hasText:'다음 페이지'}).click();assert.match(await page.locator('[data-ledger]').innerText(),/2\/26페이지/);
 assert.equal(await page.locator('[data-period-total]').innerText(),totals.month);
 await Promise.all([page.waitForURL(/\/seller\/reports\/[0-9a-f-]+$/),page.locator('[data-save-report]').click()]);
 const reportUrl=page.url(),before=await page.locator('[data-saved-report]').innerText();
 assert.match(before,/전체 532행/);assert.match(before,/상세 분할 1 \/ 6/);
 const sequences=[],pdfs=[];
 for(let part=1;part<=6;part++){
  await page.goto(reportUrl+'?part='+part);assert.equal(await page.locator('[data-period-total]').innerText(),totals.month);
  sequences.push(...await page.locator('[data-report-sequence]').evaluateAll(nodes=>nodes.map(n=>Number(n.dataset.reportSequence))));
  await page.evaluate(()=>{window.print=()=>{window.__print=true;};});await page.locator('[data-ledger-print]').click();assert.equal(await page.evaluate(()=>window.__print),true);
  const name=`saved-part-${part}.pdf`;await page.pdf({path:path.join(out,name),preferCSSPageSize:true,printBackground:true});pdfs.push(name);
 }
 assert.deepEqual(sequences,Array.from({length:532},(_,i)=>i+1));
 await page.goto(reportUrl);assert.equal(await page.locator('[data-saved-report]').innerText(),before);
 await page.evaluate(()=>document.body.classList.add('live-print-ledger'));await page.pdf({path:path.join(out,'saved-reprint.pdf'),preferCSSPageSize:true,printBackground:true});
 for(const width of [1440,390]){await page.setViewportSize({width,height:1000});await page.screenshot({path:path.join(out,`saved-${width}.png`),fullPage:true});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);}
 assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({reportUrl,rows:532,parts:6,sequences,totals,pdfs,errors},null,2));
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1);});
