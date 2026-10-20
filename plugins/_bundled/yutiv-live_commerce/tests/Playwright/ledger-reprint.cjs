const {chromium}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(!process.cwd().replaceAll('\\','/').includes('/live-workflow-browser'))throw Error('Isolated checkout required');
(async()=>{
 const out=path.resolve('ledger040-evidence'),prior=JSON.parse(fs.readFileSync(path.join(out,'browser.json'))),fixture=JSON.parse(fs.readFileSync('ledger040-fixture.json'));
 const browser=await chromium.launch({channel:'chrome',headless:true,args:['--host-resolver-rules=MAP teewide.test 127.0.0.1']});
 const context=await browser.newContext({locale:'ko-KR',viewport:{width:1440,height:1000}});
 await context.route('http://teewide.test/**',route=>{const u=new URL(route.request().url());u.port='18973';return route.fulfill({status:307,headers:{location:u.href}});});
 const page=await context.newPage(),base='http://teewide.test:18973';
 await page.goto(base+'/seller/login');await page.locator('form.tw-form').evaluate((f,b)=>f.action=b+'/login',base);
 await page.locator('[name=email]').fill('operator');await page.locator('[name=password]').fill('Synthetic-only-1234!');
 await Promise.all([page.waitForURL(u=>!u.pathname.includes('login')),page.locator('button[type=submit]').click()]);
 const seen=[];
 for(let part=1;part<=6;part++){
  await page.goto(prior.reportUrl+'?part='+part);assert.match(await page.locator('[data-saved-report]').innerText(),/기말 4200/);
  seen.push(...await page.locator('[data-report-sequence]').evaluateAll(nodes=>nodes.map(n=>Number(n.dataset.reportSequence))));
  await page.evaluate(()=>{window.print=()=>{};});await page.locator('[data-ledger-print]').click();
  await page.pdf({path:path.join(out,`saved-part-${part}.pdf`),preferCSSPageSize:true,printBackground:true});
 }
 assert.deepEqual(seen,Array.from({length:532},(_,i)=>i+1));
 await page.goto(prior.reportUrl);const original=await page.locator('[data-saved-report]').innerText();
 await page.evaluate(()=>document.body.classList.add('live-print-ledger'));await page.pdf({path:path.join(out,'saved-reprint.pdf'),preferCSSPageSize:true,printBackground:true});
 for(const width of [1440,390]){await page.setViewportSize({width,height:1000});await page.screenshot({path:path.join(out,`saved-${width}.png`),fullPage:true});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);}
 await page.setViewportSize({width:1440,height:1000});await page.goto(`${base}/seller/ledger?from=2026-10-01&to=2026-10-31&period=month&product_id=${fixture.product}`);
 assert.match(await page.locator('[data-ledger]').innerText(),/기말 4201/);
 assert.equal(await page.locator('.live-stock-ledger tfoot').count(),1);
 await page.screenshot({path:path.join(out,'live-month-final.png'),fullPage:true});
 await Promise.all([page.waitForURL(/\/seller\/reports\/[0-9a-f-]+$/),page.locator('[data-save-report]').click()]);
 const next=page.url();assert.notEqual(next,prior.reportUrl);assert.match(await page.locator('[data-saved-report]').innerText(),/기말 4201/);
 await page.goto(prior.reportUrl);assert.equal(await page.locator('[data-saved-report]').innerText(),original);
 fs.writeFileSync(path.join(out,'reprint-final.json'),JSON.stringify({original:prior.reportUrl,regenerated:next,oldClosing:4200,newClosing:4201,oldRows:532,immutable:true,allSequences:true},null,2));await browser.close();
})().catch(e=>{console.error(e);process.exit(1);});
