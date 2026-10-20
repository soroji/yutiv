import json
from pathlib import Path
import pymupdf

folder = Path('ledger040-evidence')
sequences = []
metrics = []
for part in range(1, 7):
    doc = pymupdf.open(folder / f'saved-part-{part}.pdf')
    metrics.append({'part': part, 'pages': len(doc), 'width_mm': round(doc[0].rect.width / 72 * 25.4, 2), 'height_mm': round(doc[0].rect.height / 72 * 25.4, 2)})
    for page in doc:
        for word in page.get_text('words'):
            assert word[0] >= -0.5 and word[1] >= -0.5
            assert word[2] <= page.rect.width + 0.5 and word[3] <= page.rect.height + 0.5
            if 30 <= word[0] <= 42 and word[4].isdigit():
                sequences.append(int(word[4]))
    if part == 1:
        text = ''.join(page.get_text() for page in doc)
        reprint = ''.join(page.get_text() for page in pymupdf.open(folder / 'saved-reprint.pdf'))
        assert text == reprint
        for index in [0, 1, len(doc)-1]:
            doc[index].get_pixmap(matrix=pymupdf.Matrix(1.3, 1.3)).save(folder / f'final-render-{index+1}.png')
    if part == 6:
        doc[-1].get_pixmap(matrix=pymupdf.Matrix(1.3, 1.3)).save(folder / 'final-render-financial.png')
assert sequences == list(range(1, 533)), sequences
result = {'parts': metrics, 'all_pdf_detail_sequences': True, 'detail_rows': len(sequences), 'reprint_text_identical': True, 'text_inside_page_bounds': True}
(folder / 'pdf-final.json').write_text(json.dumps(result, indent=2), encoding='utf-8')
print(json.dumps(result))
