/**
 * 프리뷰 내용 감사 — 검사(check.cjs)가 통과한 뒤 "무엇이 실제로 보이는가" 를 사람이 읽기 위한 요약.
 * 실패를 판정하지 않는다. 판정은 check.cjs 가 한다.
 */
const fs = require('fs');
const path = require('path');

const OUT = path.join(__dirname, 'output');
const report = JSON.parse(fs.readFileSync(path.join(OUT, 'render-report.json'), 'utf8'));

for (const p of report.pages) {
    const raw = fs.readFileSync(path.join(OUT, 'pages', p.file), 'utf8');
    const body = raw.replace(/<style[\s\S]*?<\/style>/g, '');

    const heads = [];
    for (const tag of ['h1', 'h2']) {
        for (const m of body.matchAll(new RegExp(`<${tag}[^>]*>([\\s\\S]*?)</${tag}>`, 'g'))) {
            const t = m[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            if (t) heads.push(tag.toUpperCase() + ' ' + t);
        }
    }

    const cards = (body.match(/aspect-ratio:3 \/ 4/g) || []).length;
    const names = [...body.matchAll(/<h3[^>]*line-clamp-2[^>]*>([\s\S]*?)<\/h3>/g)].map((m) => m[1].trim());
    const strike = (body.match(/line-through/g) || []).length;
    const pct = (body.match(/>\d+%<\/span>/g) || []).length;
    const brands = (body.match(/uppercase tracking-widest text-gray-400[^"]*truncate/g) || []).length;
    const skeleton = (body.match(/animate-pulse/g) || []).length;
    const imgs = (body.match(/<img\b/g) || []).length;
    const dataImgs = (body.match(/src="data:image/g) || []).length;

    console.log('\n===== ' + p.file + '  (' + p.locale + ', ' + p.width + 'px)');
    heads.slice(0, 8).forEach((h) => console.log('   ' + h));
    if (heads.length > 8) console.log('   ... (+' + (heads.length - 8) + ')');
    console.log(`   상품카드=${cards} 상품명=${names.length} 브랜드=${brands} 정상가(취소선)=${strike} 할인율=${pct}`);
    console.log(`   img=${imgs} (data URI ${dataImgs}) 스켈레톤=${skeleton}`);
    if (names.length) console.log('   예시 상품명: ' + names.slice(0, 3).join(' / '));
}
