import json
from pathlib import Path
import pymupdf

folder = Path('workflow-evidence')
results = json.loads((folder / 'results.json').read_text(encoding='utf-8'))
metrics = {}
for name in ['labels.pdf', 'ledger.pdf']:
    doc = pymupdf.open(folder / name)
    assert len(doc) > 0
    for page in doc:
        for word in page.get_text('words'):
            assert word[0] >= -0.5 and word[1] >= -0.5
            assert word[2] <= page.rect.width + 0.5 and word[3] <= page.rect.height + 0.5
    metrics[name] = {'pages': len(doc), 'width_mm': round(doc[0].rect.width / 72 * 25.4, 2), 'height_mm': round(doc[0].rect.height / 72 * 25.4, 2)}
    doc[0].get_pixmap(matrix=pymupdf.Matrix(1.5, 1.5)).save(folder / (name + '.png'))
    if name == 'labels.pdf':
        assert len(doc) == results['labels']
        for index, page in enumerate(doc):
            assert abs(page.rect.width / 72 * 25.4 - 50) < 0.5
            assert abs(page.rect.height / 72 * 25.4 - 30) < 0.5
            assert results['qrs'][index] in ''.join(page.get_text().split())
            pix = page.get_pixmap(dpi=300, alpha=False)
            pix = pymupdf.Pixmap(pix, 1)
            (folder / f'label-{index}.rgba').write_bytes(pix.samples)
            (folder / f'label-{index}.json').write_text(json.dumps({'width': pix.width, 'height': pix.height, 'uuid': results['qrs'][index]}))
    else:
        assert len(doc) > 1
        assert doc[0].rect.width > doc[0].rect.height
        text = ''.join(page.get_text() for page in doc)
        assert 'operational-events-2' in text and '9,000' in text
        assert '저장운영보고서' in ''.join(text.split())
        for index, page in enumerate(doc):
            page.get_pixmap(matrix=pymupdf.Matrix(0.8, 0.8)).save(folder / f'ledger-page-{index + 1}.png')
(folder / 'pdf-results.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=2), encoding='utf-8')
print(json.dumps(metrics, ensure_ascii=False))
