const {chromium}=require('@playwright/test'),fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
if(!process.cwd().replaceAll('\\','/').endsWith('/live-accounting060-browser'))throw Error('isolated copy required');
const out=path.resolve('admin080-evidence');fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true,args:['--host-resolver-rules=MAP teewide.test 127.0.0.1']});const ctx=await browser.newContext({locale:'ko-KR'}),base='http://teewide.test:18974';
 await ctx.route('http://teewide.test/**',r=>{const u=new URL(r.request().url());u.port='18974';return r.fulfill({status:307,headers:{location:u.href}})});
 await ctx.route('**/seller/accounting/actions/**',async route=>{const u=new URL(route.request().url());u.hostname='127.0.0.1';const response=await route.fetch({url:u.href,headers:{...route.request().headers(),host:'teewide.test:18974'}});await route.fulfill({response});});
 const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/seller/login');await page.locator('form.tw-form').evaluate((f,b)=>f.action=b+'/login',base);await page.locator('[name=email]').fill(process.env.UI_USER||'operator');await page.locator('[name=password]').fill('Synthetic-only-1234!');await Promise.all([page.waitForURL(u=>!u.pathname.includes('login')),page.locator('button[type=submit]').click()]);
 const before=process.argv.includes('--before'),measure=[];let expectedMenu;
 for(const section of ['broadcasts','accounting/overview','accounting/sales','accounting/expenses','accounting/accounts','accounting/assets','accounting/reports']){
  assert.equal((await page.goto(base+'/seller/'+section)).status(),200);const menu=await page.locator('.live-nav a').evaluateAll(a=>a.map(x=>({text:x.textContent.trim(),href:x.getAttribute('href')})));
  if(!before){expectedMenu??=menu;assert.deepEqual(menu,expectedMenu);assert.equal(await page.locator('.live-nav [aria-current=page]').count(),1);if(section.startsWith('accounting'))assert((await page.locator('.live-nav [aria-current=page]').getAttribute('href')).includes('accounting'));}
  if(!before){const active=page.locator('.broadcast-tabs a[aria-current=page],.finance-tabs a[aria-current=page]');assert.equal(await active.count(),1);assert.equal(await active.evaluate(x=>getComputedStyle(x).backgroundColor),'rgb(19, 92, 66)');assert.equal(await active.evaluate(x=>getComputedStyle(x).color),'rgb(255, 255, 255)');for(const link of await page.locator('.broadcast-tabs a,.finance-tabs a').all())assert.equal(await link.evaluate(x=>getComputedStyle(x).textDecorationLine),'none');}
  for(const width of [1440,1920,390]){await page.setViewportSize({width,height:1000});if(!before)assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:path.join(out,(before?'before-':'after-')+section.replace('/','-')+'-'+width+'.png'),fullPage:true});}
  const tab=page.locator('.broadcast-tabs a,.finance-tabs a').first();const css=await tab.count()?await tab.evaluate(x=>{const c=getComputedStyle(x);return {background:c.backgroundColor,color:c.color,height:x.getBoundingClientRect().height,decoration:c.textDecorationLine}}):null;measure.push({section,menu,tab:css});
 }
 fs.writeFileSync(path.join(out,before?'before.json':'after.json'),JSON.stringify({chrome:browser.version(),measure,errors},null,2));assert.deepEqual(errors,[]);await browser.close();console.log('PASS '+(before?'baseline':'final')+' renderer and styles');
})().catch(e=>{console.error(e);process.exit(1)});
