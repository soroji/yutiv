const fs=require('fs'),path=require('path'),crypto=require('crypto');
const tpl=path.resolve(__dirname,'..');
const hash=data=>crypto.createHash('sha256').update(data).digest('hex');
function files(dir){return fs.readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?files(path.join(dir,e.name)):[path.join(dir,e.name)]);}
function inputHash(){const inputs=['template.json','components.json','routes.json','package.json','package-lock.json','vite.config.ts',...files(path.join(tpl,'src')).filter(p=>!p.includes('__tests__')&&!p.endsWith('test-setup.ts')).map(p=>path.relative(tpl,p))];return hash(inputs.sort().map(p=>p.replaceAll('\\','/')+' '+hash(fs.readFileSync(path.join(tpl,p)))).join('\n'));}
const products=['css/components.css','js/components.iife.js'];
const metadata={identifier:'yutiv-stillform',global:'YutivStillform',source_sha256:inputHash(),outputs:Object.fromEntries(products.map(p=>[p,hash(fs.readFileSync(path.join(tpl,'dist',p)))]))};
if(process.argv.includes('--check')){
 const stored=JSON.parse(fs.readFileSync(path.join(tpl,'dist/build-manifest.json')));if(JSON.stringify(stored)!==JSON.stringify(metadata))throw new Error('Source/dist checksum mismatch: rebuild the template');console.log('PASS: source/dist SHA256');
}else fs.writeFileSync(path.join(tpl,'dist/build-manifest.json'),JSON.stringify(metadata,null,2)+'\n');
