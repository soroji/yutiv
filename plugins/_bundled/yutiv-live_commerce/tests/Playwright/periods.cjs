const {chromium}=require('@playwright/test');const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
if(!process.cwd().replaceAll('\\','/').includes('/live-workflow-browser'))throw Error('격리 사본 필요');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true,args:['--host-resolver-rules=MAP teewide.test 127.0.0.1']});const context=await browser.newContext({locale:'ko-KR',viewport:{width:1440,height:1000}});const base='http://teewide.test:18973';
 await context.route('http://teewide.test/**',route=>route.fulfill({status:307,headers:{location:route.request().url().replace('http://teewide.test/','http://teewide.test:18973/')}}));
 const page=await context.newPage();await page.goto(base+'/seller/login');await page.locator('form.tw-form').evaluate((form,base)=>form.action=base+'/login',base);await page.locator('[name=email]').fill('operator');await page.locator('[name=password]').fill('Synthetic-only-1234!');await Promise.all([page.waitForURL(url=>!url.pathname.includes('login')),page.locator('button[type=submit]').click()]);
 const results={};for(const period of ['day','week','month']){
  await page.goto(base+`/seller/ledger?from=2026-10-01&to=2026-10-31&period=${period}`);await page.waitForSelector('[data-ledger]');
  assert.match(await page.locator('[data-ledger]').innerText(),/27,000/);assert.match(await page.locator('[data-ledger]').innerText(),/18,000/);
  await page.evaluate(()=>window.print=()=>window.__printed=true);await page.locator('[data-ledger-print]').click();assert.equal(await page.evaluate(()=>window.__printed),true);
  await page.pdf({path:path.resolve('workflow-evidence/ledger.pdf'),preferCSSPageSize:true,printBackground:true});results[period]={printed:true,nonzeroSalesVerified:true};
 }
 fs.writeFileSync(path.resolve('workflow-evidence/period-results.json'),JSON.stringify(results,null,2));await browser.close();
})().catch(error=>{console.error(error);process.exit(1)});
