const fs=require('fs'),path=require('path'),crypto=require('crypto');
const tpl=path.resolve(__dirname,'..'),repo=path.resolve(tpl,'../../..');
const read=p=>JSON.parse(fs.readFileSync(p,'utf8').replace(/^\uFEFF/,''));
const walkFiles=d=>fs.readdirSync(d,{withFileTypes:true}).filter(e=>e.name!=='node_modules').flatMap(e=>e.isDirectory()?walkFiles(path.join(d,e.name)):[path.join(d,e.name)]);
const errors=[],counts={};const check=(ok,msg)=>{if(!ok)errors.push(msg);};
const manifest=read(path.join(tpl,'template.json')),routes=read(path.join(tpl,'routes.json')).routes;
check(manifest.identifier==='yutiv-stillform','identifier');check(manifest.version==='1.0.0','version');
check(JSON.stringify(manifest.locales)===JSON.stringify(['ko','en','ja','zh-CN']),'locales');
check((manifest.externals??[]).length===0,'external CDN');
check(read(path.join(tpl,'package.json')).version===manifest.version,'package version');
check(read(path.join(tpl,'package-lock.json')).version===manifest.version,'lock version');
const original=read(path.join(repo,'templates/_bundled/yutiv-commerce/routes.json')).routes;
for(const route of original)check(routes.some(r=>r.path===route.path&&r.layout===route.layout&&r.redirect===route.redirect&&r.auth_required===route.auth_required&&r.guest_only===route.guest_only),'route parity '+route.path);
counts.preservedRoutes=original.length;counts.routes=routes.length;
const definitions=read(path.join(tpl,'components.json'));check(definitions.templateId===manifest.identifier,'registry identifier');
const components=new Set(Object.values(definitions.components).flat().map(c=>c.name));
for(const c of Object.values(definitions.components).flat())check(fs.existsSync(path.join(tpl,c.path)),'component path '+c.path);
function localeBag(locale){const main=read(path.join(tpl,'lang',locale+'.json'));function expand(v){if(v?.$partial)return expand(read(path.join(tpl,'lang',v.$partial)));if(v&&typeof v==='object')return Object.fromEntries(Object.entries(v).map(([k,x])=>[k,expand(x)]));return v;}return expand(main);}
function flatten(v,p='',out={}){for(const [k,x]of Object.entries(v)){const key=p?p+'.'+k:k;if(x&&typeof x==='object')flatten(x,key,out);else out[key]=x;}return out;}
const bags=Object.fromEntries(manifest.locales.map(l=>[l,flatten(localeBag(l))]));const keys=Object.keys(bags.ko).sort();
for(const l of manifest.locales){check(JSON.stringify(Object.keys(bags[l]).sort())===JSON.stringify(keys),'locale key parity '+l);for(const [k,v]of Object.entries(bags[l])){if(l==='ja')check(!/[가-힣]/.test(v),'Korean in ja '+k);if(l==='zh-CN')check(!/[가-힣ぁ-ゔァ-ヴ]/.test(v),'locale contamination '+k);}}
counts.translationKeysPerLocale=keys.length;
const layouts=walkFiles(path.join(tpl,'layouts')).filter(p=>p.endsWith('.json'));counts.layouts=layouts.length;
const handlers=new Set();
function inspect(v,file){if(Array.isArray(v)){v.forEach(x=>inspect(x,file));return;}if(!v||typeof v!=='object')return;
 if(v.partial)check(fs.existsSync(path.join(tpl,'layouts',v.partial)) || fs.existsSync(path.join(tpl,path.dirname(file),v.partial)),file+' partial '+v.partial);
 if(v.extends)check(fs.existsSync(path.join(tpl,'layouts',v.extends+'.json')),file+' extends '+v.extends);
 if(['basic','composite','layout'].includes(v.type)&&v.name)check(components.has(v.name),file+' component '+v.name);
 if(v.handler)handlers.add(v.handler);
 for(const x of Object.values(v)){if(typeof x==='string'){for(const match of x.matchAll(/\$t:([\w.-]+)/g)){if(!match[1].endsWith('.')&&!match[1].startsWith('sirsoft-')&&!match[1].startsWith('core.'))check(keys.includes(match[1]),file+' translation '+match[1]);}check(!/(?:https?:)?\/\/(?:images\.unsplash|cdnjs|cdn\.jsdelivr|unpkg)/.test(x),file+' theme external');check(!/\b(?:SF-DEMO|superbify-commerce-compat|live_tenants)\b/i.test(x),file+' excluded dependency');}else inspect(x,file);}}
for(const file of layouts)inspect(read(file),path.relative(tpl,file));
for(const file of ['seo-config.json','editor-spec/componentPalette.json'])inspect(read(path.join(tpl,file)),file);
check(!fs.readFileSync(path.join(tpl,'editor-spec/componentPalette.json'),'utf8').includes('via.placeholder.com'),'external palette placeholder');
check(!fs.readFileSync(path.join(tpl,'src/components/composite/FileUploader/useFileUploader.ts'),'utf8').includes('useWebWorker: true'),'compression CDN worker');
check(!fs.existsSync(path.join(tpl,'dist/src/test-setup.d.ts')),'test declaration in runtime dist');
for(const route of routes)if(route.layout)check(fs.existsSync(path.join(tpl,'layouts',route.layout+'.json')),'route layout '+route.layout);
const source=walkFiles(path.join(tpl,'src')).filter(p=>/\.(tsx?|css)$/.test(p)&&!p.includes('__tests__')).map(p=>fs.readFileSync(p,'utf8')).join('\n');
for(const h of handlers)if(h.startsWith('yutiv-stillform.'))check(source.includes("'"+h+"'"),'handler '+h);
check(!/shopBase\s*=\s*['"]\/shop/.test(source),'hardcoded shop base');
const dist=fs.readFileSync(path.join(tpl,'dist/js/components.iife.js'),'utf8');
check(/\bYutivStillform\s*=/.test(dist),'IIFE global');check(!dist.includes('sourceMappingURL='),'production sourcemap');
check(dist.includes('sf-product-card')&&dist.includes('sf-footer'),'stale JS');
const css=fs.readFileSync(path.join(tpl,'dist/css/components.css'),'utf8');check(css.includes('.sf-hero')&&css.includes('--sf-paper'),'stale CSS');
for(const n of ['LICENSE','NOTICE.md','README.md','CHANGELOG.md'])check(fs.existsSync(path.join(tpl,n)),'missing '+n);
counts.customAndBuiltinHandlers=handlers.size;counts.jsonFiles=walkFiles(tpl).filter(p=>p.endsWith('.json')&&!p.includes('node_modules')&&!p.includes('/dist/')).length;
console.log(JSON.stringify({ok:errors.length===0,counts,errors},null,2));process.exitCode=errors.length?1:0;
