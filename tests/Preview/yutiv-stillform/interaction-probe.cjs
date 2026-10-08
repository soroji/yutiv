// Real browser, real production components; synthetic state, no API/app/DB lifecycle.
const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process');
const out=path.join(__dirname,'output'),tpl=path.resolve(__dirname,'../../../templates/_bundled/yutiv-stillform');
const umd=process.env.G7_PREVIEW_UMD||path.resolve(__dirname,'../yutiv-commerce/.umd');
const browser=process.env.G7_PREVIEW_BROWSER||'C:/Program Files/Google/Chrome/Application/chrome.exe';
const fileUrl=p=>'file:///'+p.replace(/\\/g,'/');
const script=p=>'<script src="'+fileUrl(p)+'"></script>';
const html=`<!doctype html><html lang="en"><head><meta charset="utf-8"><style>${fs.readFileSync(path.join(tpl,'dist/css/components.css'),'utf8')}</style></head><body>
<div class="sf-root"><div id="mount"></div><div id="mobile_nav_drawer" hidden><button id="first">First</button><button id="last">Last</button></div></div><pre id="result">PENDING</pre>
${script(path.join(umd,'react.production.min.js'))}${script(path.join(umd,'react-dom.production.min.js'))}
<script>
window.ReactJSXRuntime={jsx:function(type,props,key){return React.createElement(type,Object.assign({},props,key===undefined?{}:{key:key}));},jsxs:function(type,props,key){return React.createElement(type,Object.assign({},props,key===undefined?{}:{key:key}));},Fragment:React.Fragment};
var state={locale:'en',theme:'light',preferredCurrency:'USD',defaultCurrency:'USD',modules:{'sirsoft-ecommerce':{basic_info:{route_path:'catalog',no_route:false}}},mobileMenuOpen:false};
var subscribers=[],actions=[],errors=[];
var originalError=console.error;console.error=function(){errors.push(Array.from(arguments).map(String).join(' '));originalError.apply(console,arguments);};
window.addEventListener('error',function(e){errors.push(e.message||'resource error');},true);
function update(values){Object.assign(state,values);document.getElementById('mobile_nav_drawer').hidden=!state.mobileMenuOpen;subscribers.forEach(function(fn){fn(state);});}
window.G7Core={t:function(k){return k;},createLogger:function(){return {log:function(){},warn:function(){},error:function(m){errors.push(m);}};},state:{get:function(){return state;},getLocal:function(){return {};},subscribe:function(fn){subscribers.push(fn);return function(){};}},dispatch:function(a){actions.push(a);if(a.handler==='setState')update({mobileMenuOpen:a.params.mobileMenuOpen});},getActionDispatcher:function(){return {registerHandler:function(){}};},identity:{setLauncher:function(){}},layoutEditor:{registerWidget:function(){}}};
</script>${script(path.join(tpl,'dist/js/components.iife.js'))}
<script>
var results={};function check(name,ok){results[name]=!!ok;if(!ok)throw new Error(name);}
ReactDOM.createRoot(document.getElementById('mount')).render(React.createElement(React.Fragment,null,React.createElement(YutivStillform.Header,{siteName:'YUTIV',onMobileMenuOpen:function(){update({mobileMenuOpen:true});}}),React.createElement(YutivStillform.ProductCard,{product:{id:17,product_code:'AUDIT-CODE',name_localized:'Audit object',selling_price:8,selling_price_formatted:'USD 8.00',sales_status:'sale'}})));
window.addEventListener('load',function(){setTimeout(function(){try{
var menu=document.querySelector('[aria-label="stillform.menu"]'),drawer=document.getElementById('mobile_nav_drawer');check('closed drawer inert',drawer.inert);menu.focus();menu.click();check('menu opens',!drawer.hidden&&!drawer.inert);check('focus enters drawer',document.activeElement.id==='first');
document.getElementById('last').focus();document.dispatchEvent(new KeyboardEvent('keydown',{key:'Tab',bubbles:true,cancelable:true}));check('Tab wraps',document.activeElement.id==='first');
document.dispatchEvent(new KeyboardEvent('keydown',{key:'Tab',shiftKey:true,bubbles:true,cancelable:true}));check('Shift Tab wraps',document.activeElement.id==='last');
document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));check('Escape closes',drawer.hidden&&drawer.inert);check('focus restores',document.activeElement===menu);
document.querySelector('.sf-product-card').click();check('product detail URL',actions.some(function(a){return a.handler==='navigate'&&a.params.path==='/catalog/products/AUDIT-CODE';}));
document.querySelector('[data-icon="shopping-cart"]').closest('button').click();check('cart URL',actions.some(function(a){return a.handler==='navigate'&&a.params.path==='/catalog/cart';}));
ReactDOM.flushSync(function(){document.querySelector('[aria-label="stillform.theme_toggle"]').click();});
ReactDOM.flushSync(function(){Array.from(document.querySelectorAll('button')).find(function(b){return b.textContent.trim()==='common.theme.dark';}).click();});check('dark theme applies',document.documentElement.classList.contains('dark'));
ReactDOM.flushSync(function(){document.querySelector('[aria-label="stillform.theme_toggle"]').click();});
ReactDOM.flushSync(function(){Array.from(document.querySelectorAll('button')).find(function(b){return b.textContent.trim()==='common.theme.light';}).click();});check('light theme applies',!document.documentElement.classList.contains('dark'));
}catch(e){errors.push(e.message);}document.getElementById('result').textContent=JSON.stringify({results:results,errors:errors});},500);});
</script></body></html>`;
fs.mkdirSync(path.join(out,'interaction'),{recursive:true});
fs.writeFileSync(path.join(out,'interaction/inner.html'),html);
fs.writeFileSync(path.join(out,'interaction/host.html'),`<iframe src="inner.html" style="width:390px;height:1500px;border:0"></iframe><pre id="result">PENDING</pre><script>window.addEventListener('message',function(e){document.getElementById('result').textContent=e.data;});</script>`);
// Read the result through postMessage to respect file:// opaque-origin boundaries.
fs.appendFileSync(path.join(out,'interaction/inner.html'),'<script>setTimeout(function(){parent.postMessage(document.getElementById("result").textContent,"*");},1500);</script>');
const dom=execFileSync(browser,['--headless=new','--disable-gpu','--no-sandbox','--allow-file-access-from-files','--virtual-time-budget=5000','--dump-dom',fileUrl(path.join(out,'interaction/host.html'))],{encoding:'utf8',maxBuffer:8*1024*1024,windowsHide:true,stdio:['ignore','pipe','ignore']});
const match=dom.match(/<pre id="result">([\s\S]*?)<\/pre>/);
if(!match||match[1]==='PENDING')throw new Error('No browser interaction result');
const report=JSON.parse(match[1].replace(/&quot;/g,'"').replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>'));
fs.writeFileSync(path.join(out,'interaction-report.json'),JSON.stringify(report,null,2));
console.log(JSON.stringify(report,null,2));
if(report.errors.length||Object.keys(report.results).length!==11||Object.values(report.results).some(x=>!x))process.exitCode=1;
