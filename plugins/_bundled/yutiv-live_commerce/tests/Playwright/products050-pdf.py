import json
from pathlib import Path
import pymupdf
folder=Path('products050-evidence');result=json.loads((folder/'results.json').read_text(encoding='utf-8'));doc=pymupdf.open(folder/'labels-50x30.pdf');assert len(doc)==result['labels']
for i,page in enumerate(doc):
 assert abs(page.rect.width/72*25.4-50)<.5 and abs(page.rect.height/72*25.4-30)<.5
 assert result['uuids'][i] in ''.join(page.get_text().split())
 for word in page.get_text('words'):
  assert word[0]>=-.5 and word[1]>=-.5 and word[2]<=page.rect.width+.5 and word[3]<=page.rect.height+.5
 pix=page.get_pixmap(dpi=300,alpha=False);pix=pymupdf.Pixmap(pix,1);(folder/f'label-{i}.rgba').write_bytes(pix.samples);(folder/f'label-{i}.json').write_text(json.dumps({'width':pix.width,'height':pix.height,'uuid':result['uuids'][i]}))
 if i==0:page.get_pixmap(dpi=180).save(folder/'label-first.png')
(folder/'pdf-results.json').write_text(json.dumps({'pages':len(doc),'width_mm':doc[0].rect.width/72*25.4,'height_mm':doc[0].rect.height/72*25.4,'textInBounds':True},indent=2));print('PDF pages/size/text PASS')
