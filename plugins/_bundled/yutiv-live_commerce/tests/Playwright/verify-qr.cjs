const fs=require('fs'),path=require('path'),jsQR=require('jsqr'),assert=require('node:assert/strict');
const folder=path.resolve('workflow-evidence');const result=JSON.parse(fs.readFileSync(path.join(folder,'results.json')));
for(let index=0;index<result.labels;index++){
 const info=JSON.parse(fs.readFileSync(path.join(folder,`label-${index}.json`)));
 const pixels=fs.readFileSync(path.join(folder,`label-${index}.rgba`));
 const code=jsQR(new Uint8ClampedArray(pixels),info.width,info.height);
 assert.equal(code?.data,'U:'+info.uuid);
}
fs.writeFileSync(path.join(folder,'qr-results.json'),JSON.stringify({decodedPdfLabels:result.labels,allMatchSavedUUID:true},null,2));
console.log(`PDF 물품 QR ${result.labels}개가 저장된 UUID와 일치합니다.`);
