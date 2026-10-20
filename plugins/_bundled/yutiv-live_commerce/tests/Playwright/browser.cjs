const {chromium}=require('@playwright/test');
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
// 별도 사본에서 실행하며 외부 호스트·운영 서버를 호출하지 않습니다.
if(!process.cwd().replaceAll('\\','/').includes('/live-workflow-browser'))throw Error('격리 사본에서 실행하세요.');
const out=path.resolve('workflow-evidence');fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true,args:['--host-resolver-rules=MAP teewide.test 127.0.0.1, MAP live.teewide.test 127.0.0.1']});
 const context=await browser.newContext({locale:'ko-KR',viewport:{width:1440,height:1000}});
 // 기존 절대 링크가 생략하는 로컬 개발 포트만 보완합니다. 응답 본문/API는 실제 서버를 사용합니다.
 await context.route('http://teewide.test/**',route=>{
  const url=new URL(route.request().url());url.port='18973';
  return route.fulfill({status:307,headers:{location:url.href}});
 });
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const base='http://teewide.test:18973';
 page.on('response',response=>{if(response.request().method()==='POST'&&response.url().includes('/login'))console.log('로그인 응답',response.status());});
 await page.goto(base+'/seller/login');
 console.log('세션 쿠키', (await context.cookies()).map(cookie=>({name:cookie.name,secure:cookie.secure,domain:cookie.domain})));
 await page.locator('form.tw-form').evaluate((form,base)=>form.action=base+'/login',base);
 await page.locator('[name=email]').fill('operator');await page.locator('[name=password]').fill('Synthetic-only-1234!');
 await Promise.all([page.waitForURL(url=>!url.pathname.includes('login')),page.locator('button[type=submit]').click()]).catch(async error=>{console.error(await page.locator('body').innerText());throw error;});
 await page.goto(base+'/seller/product-receive');
 await page.locator('[name=name]').first().fill('브라우저 합성 상품 색상 사이즈');await page.locator('[name=code]').fill('BROWSER-'+Date.now());
 const axes=page.locator('[data-axis-values]');await axes.nth(0).fill('검정, 흰색');await axes.nth(1).fill('S, M');await page.locator('[data-combinations]').click();
 assert.equal(await page.locator('[data-receipt-rows] tr').count(),4);
 const rows=page.locator('[data-receipt-rows] tr');
 for(let i=0;i<4;i++){await rows.nth(i).locator('[data-item-field=price]').fill('10000');await rows.nth(i).locator('[data-receipt-quantity]').fill(String(i+1));}
 await page.locator('[name=location]').fill('브라우저 격리 창고');await page.locator('[name=memo]').fill('합성 입고');
 await page.screenshot({path:path.join(out,'receive-1440.png'),fullPage:true});
 await Promise.all([page.waitForURL(/\/seller\/receipts\?batch=\d+/),page.locator('[data-receipt-form] button[type=submit]').click()]).catch(async error=>{console.error('입고 결과',await page.locator('[data-live-message]').allTextContents());throw error;});
 const receiptUrl=page.url();assert.match(await page.locator('main').innerText(),/총 10개/);
 await page.reload();assert.equal(page.url(),receiptUrl);assert.equal(await page.locator('h2').filter({hasText:'입고 완료:'}).count(),1);
 await page.getByRole('link',{name:/이 입고분 QR/}).click();
 assert.equal(await page.locator('[data-label-unit]').count(),10);
 const qrs=await page.locator('[data-label-unit]').evaluateAll(nodes=>nodes.map(n=>n.dataset.labelUnit));assert.equal(new Set(qrs).size,10);
 await page.reload();assert.deepEqual(await page.locator('[data-label-unit]').evaluateAll(nodes=>nodes.map(n=>n.dataset.labelUnit)),qrs);
 await page.evaluate(()=>{window.print=()=>{window.__printCalled=true;};});await page.locator('[data-label-print]').click();await page.waitForFunction(()=>window.__printCalled===true).catch(async error=>{console.error('인쇄 결과',await page.locator('[data-label-status]').innerText());throw error;});
 await page.pdf({path:path.join(out,'labels.pdf'),preferCSSPageSize:true,printBackground:true});
 for(const width of [1440,1920,390]){
  await page.setViewportSize({width,height:1000});await page.screenshot({path:path.join(out,`labels-${width}.png`),fullPage:true});
  await page.goto(base+'/seller/product-receive');await page.screenshot({path:path.join(out,`products-${width}.png`),fullPage:true});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  await page.goto(base+'/seller/ledger?from=2026-10-01&to=2026-10-31&period=day');
  await page.waitForSelector('[data-ledger]');await page.screenshot({path:path.join(out,`ledger-${width}.png`),fullPage:true});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
 }
 await Promise.all([page.waitForURL(/\/seller\/reports\/[0-9a-f-]+$/),page.locator('[data-save-report]').click()]);
 await page.evaluate(()=>{window.print=()=>{window.__ledgerPrinted=true;};});await page.locator('[data-ledger-print]').click();
 assert.equal(await page.evaluate(()=>window.__ledgerPrinted),true);
 await page.pdf({path:path.join(out,'ledger.pdf'),preferCSSPageSize:true,printBackground:true});
 fs.writeFileSync(path.join(out,'results.json'),JSON.stringify({receiptUrl,labels:qrs.length,qrs,qrUnique:true,refreshStable:true,widths:[1440,1920,390],errors},null,2));
 assert.deepEqual(errors,[]);await browser.close();
})().catch(e=>{console.error(e);process.exit(1);});
