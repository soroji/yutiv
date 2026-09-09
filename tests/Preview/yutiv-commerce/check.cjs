/**
 * yutiv-commerce 프리뷰 렌더 결과 검사기.
 *
 * `build.cjs` 가 만든 `output/pages/*.html` 와 `output/render-report.json` 을 읽어
 * **렌더된 결과**를 검사한다. 정적 JSON 검사(tests/Translations/yutiv-commerce-template-check.php)가
 * 잡지 못하는 종류 — 런타임 번역 누락, 표현식 미해석, 깨진 이미지 — 를 담당한다.
 *
 * 검사 항목:
 *   1. 미해석 번역키 0        ($t: 잔존 + G7Core.t 미등록 키 + 화면에 노출된 dot-path 키)
 *   2. 미해석 표현식 0        ({{ ... }} 잔존)
 *   3. 깨진/외부 이미지 0     (빈 src, 외부 http(s) src)
 *   4. 모바일 가로 넘침 0     (정적 휴리스틱 — 실제 레이아웃 측정이 아님)
 *   5. 필수 요소 존재         (헤더 로고·검색·장바구니 / 상품 카드 / 구매 CTA)
 *   6. 로케일별 핵심 문구     (각 locale 의 lang 값이 실제로 렌더됐는지)
 *
 * 사용:  node check.cjs [--out <build 출력 디렉토리>]
 * 종료코드: 위반이 있으면 1
 */
const fs = require('fs');
const path = require('path');
const { loadLocale } = require('./runtime.cjs');

const REPO = path.resolve(__dirname, '..', '..', '..');
const TPL = path.join(REPO, 'templates', '_bundled', 'yutiv-commerce');

function arg(name, fallback) {
    const i = process.argv.indexOf(name);
    return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}
const OUT = arg('--out', path.join(__dirname, 'output'));
const PAGES_DIR = path.join(OUT, 'pages');

const violations = {};
const counts = {};
const fail = (cat, msg) => ((violations[cat] ||= []).push(msg));
const bump = (k, n = 1) => (counts[k] = (counts[k] || 0) + n);

if (!fs.existsSync(path.join(OUT, 'render-report.json'))) {
    console.error(`FATAL: ${OUT}/render-report.json 이 없습니다. 먼저 'node build.cjs' 를 실행하세요.`);
    process.exit(1);
}
const report = JSON.parse(fs.readFileSync(path.join(OUT, 'render-report.json'), 'utf8'));

/** 페이지 HTML 에서 인라인 <style> 을 걷어낸 본문만 돌려준다 (CSS 셀렉터 오탐 방지). */
function bodyOf(file) {
    const raw = fs.readFileSync(path.join(PAGES_DIR, file), 'utf8');
    return raw.replace(/<style[\s\S]*?<\/style>/g, '');
}

/** 태그를 지우고 텍스트 노드만 남긴다. */
function textOf(body) {
    return body
        .replace(/<[^>]+>/g, '\n')
        .replace(/&nbsp;/g, ' ')
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>');
}

// ── 0. IIFE 전역 계약 (필수 관문) ───────────────────────────────────────────
//
// 프리뷰 렌더러는 번들을 자기 샌드박스에서 읽기 때문에, 운영에서 터지는 "전역 이름 불일치"
// 를 그대로 통과시킬 수 있다. 실제로 그 결함(`SirsoftBasic` 잔존)으로 운영 활성화가
// 실패했다. 그래서 운영과 **같은 계약**을 재는 검사를 이 파이프라인의 필수 단계로 돌린다.
{
    const { spawnSync } = require('child_process');
    const iife = spawnSync(process.execPath, [path.join(__dirname, 'iife-global-check.cjs')], {
        encoding: 'utf8',
    });
    const out = (iife.stdout || '') + (iife.stderr || '');
    if (iife.status !== 0) {
        const lines = out.split('\n').filter((l) => l.trim().startsWith('- ') || l.includes('RESULT'));
        fail('iife', 'IIFE 전역 계약 검사 실패 — ' + (lines.join(' / ') || out.slice(0, 300)));
    }
    const typeofLine = out.split('\n').find((l) => l.includes('typeof window.'));
    if (typeofLine) counts.iifeGlobal = typeofLine.trim();
}

// ── 빌드 단계에서 수집된 결과 ────────────────────────────────────────────────
for (const k of report.missingTranslationKeys || []) {
    fail('i18n', `런타임 번역 미등록 키(G7Core.t): ${k}`);
}
for (const c of report.missingComponents || []) {
    fail('components', `번들에 없는 컴포넌트: ${c}`);
}
for (const e of report.expressionErrors || []) {
    fail('expr', `표현식 평가 실패: ${e}`);
}
for (const p of report.pages || []) {
    if (p.error) fail('render', `${p.file} 렌더 오류: ${p.error}`);
}

// ── 템플릿의 번역 네임스페이스 (노출된 dot-path 키 탐지에 쓴다) ──────────────
const LOCALES = JSON.parse(fs.readFileSync(path.join(TPL, 'template.json'), 'utf8')).locales;
const langByLocale = Object.fromEntries(LOCALES.map((l) => [l, loadLocale(TPL, l)]));
const NAMESPACES = Object.keys(langByLocale[LOCALES[0]]);
// 화면에 `nav.home` 처럼 네임스페이스로 시작하는 dot-path 가 그대로 보이면 번역 실패다.
const RAW_KEY = new RegExp(`(?:^|[\\s>(])((?:${NAMESPACES.join('|')})(?:\\.[a-z0-9_]+){1,4})(?=[\\s<)]|$)`, 'gm');

const pages = report.pages || [];
bump('pages', pages.length);

for (const p of pages) {
    const body = bodyOf(p.file);
    const text = textOf(body);

    // 1. 미해석 번역키
    for (const m of body.matchAll(/\$t:[A-Za-z0-9_.\-]+/g)) {
        fail('i18n', `${p.file}: 렌더 결과에 $t: 원문 노출 — ${m[0]}`);
    }
    for (const m of text.matchAll(RAW_KEY)) {
        fail('i18n', `${p.file}: 번역키가 그대로 화면에 노출 — ${m[1]}`);
    }
    // 속성값(placeholder/alt/aria-label/title)에 남은 키도 잡는다
    for (const m of body.matchAll(/(?:placeholder|alt|aria-label|title)="([^"]*)"/g)) {
        const v = m[1];
        if (!v) continue;
        if (/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,4}$/.test(v) && NAMESPACES.includes(v.split('.')[0])) {
            fail('i18n', `${p.file}: 속성값이 번역키 그대로 — ${v}`);
        }
    }

    // 2. 미해석 표현식
    for (const m of body.matchAll(/\{\{[^{}]{0,120}\}\}/g)) {
        fail('expr', `${p.file}: 렌더 결과에 표현식 원문 노출 — ${m[0]}`);
    }

    // 3. 깨진 / 외부 이미지
    const imgs = [...body.matchAll(/<img\b[^>]*>/g)].map((m) => m[0]);
    bump('images', imgs.length);
    for (const tag of imgs) {
        const src = (tag.match(/\ssrc="([^"]*)"/) || [, ''])[1];
        if (!src.trim()) {
            fail('image', `${p.file}: src 가 빈 <img> — 브라우저가 깨진 이미지 아이콘을 그린다`);
            continue;
        }
        if (/^https?:\/\//i.test(src)) {
            fail('image', `${p.file}: 외부 이미지 요청 — ${src.slice(0, 80)}`);
        }
        if (!/\salt="/.test(tag)) {
            fail('image', `${p.file}: alt 속성 없는 <img>`);
        }
    }
    // CSS 배경 이미지의 외부 요청
    for (const m of body.matchAll(/url\((['"]?)(https?:\/\/[^)'"]+)\1\)/g)) {
        fail('image', `${p.file}: 외부 배경 이미지 요청 — ${m[2].slice(0, 80)}`);
    }

    // 4. 모바일 가로 넘침 (정적 휴리스틱)
    if (p.width <= 767) {
        for (const m of body.matchAll(/style="[^"]*?(?:min-)?width:\s*(\d+)px/g)) {
            if (+m[1] > p.width) {
                fail('overflow', `${p.file}: 인라인 고정 폭 ${m[1]}px > 뷰포트 ${p.width}px`);
            }
        }
        for (const m of body.matchAll(/class="[^"]*\b(?:min-)?w-\[(\d+)px\]/g)) {
            if (+m[1] > p.width) {
                fail('overflow', `${p.file}: 고정 폭 클래스 ${m[1]}px > 뷰포트 ${p.width}px`);
            }
        }
        if (/\bw-screen\b/.test(body)) {
            fail('overflow', `${p.file}: w-screen 사용 — 스크롤바 폭만큼 가로 넘침이 생긴다`);
        }
    }

    // 5. 필수 요소
    const has = (re) => re.test(body);
    // 헤더 — 모든 화면 공통
    if (!has(/YUTIV/)) fail('required', `${p.file}: 헤더 로고(사이트명)가 없다`);
    if (!has(/<input[^>]*placeholder="[^"]+"/)) fail('required', `${p.file}: 검색 입력이 없다`);
    if (!has(/fa-shopping-cart/)) fail('required', `${p.file}: 장바구니 진입점이 없다`);
    if (p.width <= 767 && !has(/fa-bars/)) fail('required', `${p.file}: 모바일 메뉴(햄버거)가 없다`);

    // 상품 카드 — 상품이 있는 화면
    const cards = (body.match(/aspect-ratio:3 \/ 4/g) || []).length;
    if (['home', 'shop-list'].includes(p.kind)) {
        if (cards < 8) fail('required', `${p.file}: 상품 카드가 ${cards}개뿐 (8개 이상이어야 한다)`);
        // 카드가 가격을 실제로 표시하는지
        if (!has(/₩[\d,]+/)) fail('required', `${p.file}: 가격 표기가 없다`);
    }
    if (p.kind === 'home-empty') {
        if (cards !== 0) fail('required', `${p.file}: 상품 0건 화면에 카드가 ${cards}개 있다`);
        const emptyTitle = langByLocale[p.locale].home.empty.title;
        if (!body.includes(emptyTitle)) fail('required', `${p.file}: 0건 안내 문구가 없다`);
    }
    if (p.kind === 'home-loading') {
        const skeletons = (body.match(/animate-pulse/g) || []).length;
        if (skeletons < 8) fail('required', `${p.file}: 로딩 스켈레톤이 ${skeletons}개뿐`);
    }
    // 구매 CTA — 상품 상세
    if (p.kind === 'product') {
        // 상세의 구매 버튼은 shop.product.* 네임스페이스를 쓴다
        // (partials/shop/detail/_purchase_card.json — $t:shop.product.add_to_cart / buy_now)
        const sp = langByLocale[p.locale].shop.product || {};
        if (sp.add_to_cart && !body.includes(sp.add_to_cart)) fail('required', `${p.file}: 장바구니 담기 CTA 가 없다`);
        if (sp.buy_now && !body.includes(sp.buy_now)) fail('required', `${p.file}: 바로구매 CTA 가 없다`);
        const galleryImgs = (body.match(/<img\b/g) || []).length;
        if (galleryImgs < 2) fail('required', `${p.file}: 이미지 갤러리가 없다 (img ${galleryImgs}개)`);
    }

    // 6. 로케일별 핵심 문구
    const L = langByLocale[p.locale];
    if (!L) {
        fail('locale', `${p.file}: 알 수 없는 로케일 ${p.locale}`);
    } else if (p.layout !== undefined || p.kind.startsWith('home')) {
        // 홈 계열은 히어로/브랜드 문구가 반드시 그 로케일 값으로 나와야 한다
        const heroTitleFirstLine = String(L.home.hero.title).split('\n')[0];
        if (!body.includes(heroTitleFirstLine)) {
            fail('locale', `${p.file}(${p.locale}): 히어로 제목이 해당 로케일 값이 아니다 — "${heroTitleFirstLine}"`);
        }
        if (!body.includes(L.home.brand.title)) {
            fail('locale', `${p.file}(${p.locale}): 브랜드 문구가 해당 로케일 값이 아니다`);
        }
        if (p.kind === 'home' && !body.includes(L.home.sections.new_products)) {
            fail('locale', `${p.file}(${p.locale}): 신상품 섹션 제목이 해당 로케일 값이 아니다`);
        }
    }
}

// ── 브라우저 실측 검사 ──────────────────────────────────────────────────────
// 정적 HTML 로는 "실제로 보이는가 / 이미지가 실제로 로드됐는가" 를 알 수 없다.
// browser-probe.cjs 가 헤드리스 브라우저로 잰 값을 여기서 판정한다.
// 측정값이 없으면 **실패**다 — 실측 없이 PASS 로 넘기지 않는다.
const probePath = path.join(OUT, 'browser-probe.json');
if (!fs.existsSync(probePath)) {
    fail('browser', '브라우저 실측 결과(browser-probe.json)가 없습니다. `node browser-probe.cjs` 를 먼저 실행하세요. 실측 없이 이미지 로드·가시성·가로 넘침을 PASS 로 볼 수 없습니다.');
} else {
    const probe = JSON.parse(fs.readFileSync(probePath, 'utf8'));
    if (!probe.browserAvailable) {
        fail('browser', '헤드리스 브라우저를 찾지 못해 실측하지 못했습니다: ' + (probe.reason || ''));
    } else {
        bump('probed', Object.keys(probe.results || {}).length);

        /** 두 사각형이 겹치는가. */
        const overlaps = (a, b) =>
            a && b && a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

        for (const p of pages) {
            const r = (probe.results || {})[p.file];
            if (!r) {
                fail('browser', `${p.file}: 실측 결과 없음`);
                continue;
            }
            if (r.ok === false) {
                fail('browser', `${p.file}: 실측 실패 — ${r.error}`);
                continue;
            }

            // 뷰포트 폭이 의도대로 잡혔는지 (iframe 폭 = 뷰포트)
            if (r.innerWidth !== p.width) {
                fail('browser', `${p.file}: 실측 뷰포트 ${r.innerWidth}px ≠ 의도 ${p.width}px`);
            }

            // 7. 이미지 — load 완료 + naturalWidth > 0
            const im = r.images || {};
            if ((im.total || 0) > 0) {
                if (im.loaded !== im.total) {
                    fail('browser-image', `${p.file}: 이미지 ${im.total}개 중 ${im.total - im.loaded}개가 로드되지 않음 — ${JSON.stringify((im.broken || []).slice(0, 3))}`);
                }
                if ((im.zeroSize || []).length) {
                    fail('browser-image', `${p.file}: 화면 크기 0인 이미지 ${im.zeroSize.length}개`);
                }
            }

            // 가로 넘침 — 실제 scrollWidth 기준
            if ((r.horizontalOverflow || 0) > 0) {
                fail('browser-overflow', `${p.file}: 가로 넘침 ${r.horizontalOverflow}px (scrollWidth ${r.scrollWidth} > viewport ${r.innerWidth})`);
            }

            // 4. 모바일 헤더 — DOM 존재가 아니라 "실제로 보이고 뷰포트 안에 있는가"
            if (p.width <= 767) {
                const need = { logo: '로고', search: '검색 진입점', cart: '장바구니', menu: '메뉴(햄버거)' };
                const rects = {};
                for (const [key, label] of Object.entries(need)) {
                    const h = (r.header || {})[key];
                    const info = h && h.info;
                    if (!h || !h.found || !info || !info.visible) {
                        const why = info ? (info.reason || 'not-visible') : 'not-found';
                        fail('browser-header', `${p.file}: ${label}가 화면에 보이지 않음 (${why})`);
                        continue;
                    }
                    if (info.insideViewportX === false) {
                        fail('browser-header', `${p.file}: ${label}가 뷰포트 밖에 있음 — x=${info.rect.x} w=${info.rect.w} (viewport ${r.innerWidth})`);
                        continue;
                    }
                    rects[key] = info.rect;
                }
                // 서로 겹치지 않는지
                const keys = Object.keys(rects);
                for (let i = 0; i < keys.length; i++) {
                    for (let j = i + 1; j < keys.length; j++) {
                        if (overlaps(rects[keys[i]], rects[keys[j]])) {
                            fail('browser-header', `${p.file}: 헤더 요소가 겹침 — ${need[keys[i]]} ↔ ${need[keys[j]]}`);
                        }
                    }
                }

                // 6. 모바일 상품 그리드 — 실제 열 수 2열, 카드가 읽을 수 있는 크기
                for (const g of r.grids || []) {
                    if (/grid-cols-2/.test(g.cls) && g.columns > 2) {
                        fail('browser-grid', `${p.file}: 상품 그리드가 ${g.columns}열로 렌더됨 (모바일은 2열이어야 한다) — ${g.cls}`);
                    }
                    if (g.columns >= 4) {
                        fail('browser-grid', `${p.file}: 모바일에서 ${g.columns}열 렌더 — 금지 (${g.cls})`);
                    }
                }
                for (const c of (r.cards || []).slice(0, 2)) {
                    if (c.cardWidth < 130) {
                        fail('browser-grid', `${p.file}: 상품 카드 폭 ${c.cardWidth}px — 상품명·가격을 읽기 어렵다`);
                    }
                    if (c.nameFontPx < 13) {
                        fail('browser-grid', `${p.file}: 상품명 글자 크기 ${c.nameFontPx}px — 너무 작다`);
                    }
                }

                // (상품 카드 구조 검사는 뷰포트와 무관하게 아래에서 모든 페이지에 적용한다)

                // 5. 가로 스크롤 스트립 — 스크롤바는 감추되 스크롤은 살아 있어야 한다
                for (const s of r.strips || []) {
                    if (s.scrollable && s.scrollbarPx > 0) {
                        fail('browser-strip', `${p.file}: 가로 스크롤 스트립에 기본 스크롤바가 보인다 (${s.scrollbarPx}px)`);
                    }
                    if (!s.scrollable && s.scrollWidth > s.clientWidth) {
                        fail('browser-strip', `${p.file}: 스트립 내용이 넘치는데 스크롤되지 않는다`);
                    }
                }
            }

            // ── 상품 카드 DOM 계약 (뷰포트 무관, 모든 상품 그리드) ──────────────
            //
            // 한 상품 = grid 의 direct child 1개여야 한다. 이미지와 정보가 형제 셀로
            // 나뉘면 한 상품이 두 칸을 차지해 데스크톱에서는 이미지 옆에 이름이 서고
            // 모바일에서는 이웃 카드와 글자가 겹친다. 실제로 그 결함이 있었고
            // 종전 검사는 그것을 통과시켰다.
            const grids = r.productGrids || [];
            if (['home', 'shop-list', 'product'].includes(p.kind) && grids.length === 0) {
                fail('card-dom', `${p.file}: 상품 그리드를 찾지 못했다 (data-product-card 가 하나도 없다)`);
            }

            // 카드 껍데기 없이 이미지/정보만 반복되는 자리를 잡는다.
            // 카드 래퍼가 통째로 빠지면 그 컨테이너에는 카드가 없어 "카드의 부모" 역추적으로는
            // 검사 대상에서 사라진다 — 부속 요소 쪽에서도 세야 그 결함이 red 가 된다.
            const parts = r.productParts;
            if (parts) {
                for (const [key, label] of [['orphanImages', '이미지'], ['orphanNames', '상품명'], ['orphanInfos', '상품 정보']]) {
                    const orphans = parts[key] || [];
                    if (orphans.length) {
                        fail('card-dom', `${p.file}: 카드(data-product-card) 밖에 있는 ${label} ${orphans.length}개 — 이미지/정보가 카드로 묶이지 않고 그리드 셀로 새고 있다 (부모: ${orphans[0].parentDisplay} "${orphans[0].parentCls}")`);
                    }
                }
                for (const [key, label] of [['images', '이미지'], ['names', '상품명'], ['infos', '상품 정보']]) {
                    if (parts[key] !== parts.cards) {
                        fail('card-dom', `${p.file}: 카드 ${parts.cards}개인데 ${label}는 ${parts[key]}개 — 1:1 이 아니다`);
                    }
                }
            }
            for (const [gi, g] of grids.entries()) {
                const where = `${p.file} 그리드#${gi + 1}`;

                if (g.nonCardDirectChildren > 0) {
                    fail('card-dom', `${where}: grid 의 direct child ${g.nonCardDirectChildren}개가 data-product-card 가 아니다 — 이미지/정보가 형제 셀로 새고 있다`);
                }
                if (g.directChildren !== g.totalCardsInside) {
                    fail('card-dom', `${where}: direct child ${g.directChildren}개 ≠ 카드 ${g.totalCardsInside}개 — 한 상품이 여러 셀을 차지한다`);
                }
                if (g.cardsAmongDirectChildren !== g.totalCardsInside) {
                    fail('card-dom', `${where}: 카드 ${g.totalCardsInside}개 중 ${g.cardsAmongDirectChildren}개만 direct child 다 (카드가 중첩돼 있다)`);
                }
                if (g.cardOverlaps.length) {
                    fail('card-dom', `${where}: 카드 박스끼리 겹침 ${g.cardOverlaps.length}건 — 예: ${JSON.stringify(g.cardOverlaps[0])}`);
                }
                if (g.textOverlaps.length) {
                    fail('card-dom', `${where}: 서로 다른 카드의 텍스트끼리 겹침 ${g.textOverlaps.length}건 — 예: ${JSON.stringify(g.textOverlaps[0])}`);
                }

                // 반응형 열 수 — 캐러셀(가로 스크롤 스트립)은 열 수 계약이 다르므로 제외한다
                if (!g.isCarousel && g.columns !== null) {
                    if (p.width <= 480 && g.columns !== 2) {
                        fail('card-dom', `${where}: 모바일 ${p.width}px 에서 ${g.columns}열 (2열이어야 한다)`);
                    }
                    if (p.width >= 1280 && g.columns !== 4) {
                        fail('card-dom', `${where}: 데스크톱 ${p.width}px 에서 ${g.columns}열 (4열이어야 한다)`);
                    }
                    if (p.width >= 700 && p.width <= 900 && g.columns !== 3) {
                        fail('card-dom', `${where}: 태블릿 ${p.width}px 에서 ${g.columns}열 (3열이어야 한다)`);
                    }
                }

                // 카드별 계약 — 마지막 상품까지 전부 본다
                for (const [ci, c] of g.cards.entries()) {
                    const at = `${where} 카드#${ci + 1}`;
                    if (!c.isCard) continue; // 위에서 이미 실패로 기록됨

                    if (c.display !== 'flex' || c.flexDirection !== 'column') {
                        fail('card-dom', `${at}: 세로형이 아니다 (display=${c.display}, flex-direction=${c.flexDirection})`);
                    }
                    if (c.imageCount !== 1) {
                        fail('card-dom', `${at}: 이미지 영역이 ${c.imageCount}개 (정확히 1개여야 한다)`);
                    }
                    if (c.nameCount !== 1) {
                        fail('card-dom', `${at}: 상품명이 ${c.nameCount}개 (정확히 1개여야 한다)`);
                    }
                    if (c.sameOwner === false) {
                        fail('card-dom', `${at}: 이미지와 상품명의 가장 가까운 카드 조상이 다르다`);
                    }
                    if (!c.hasInfo) fail('card-dom', `${at}: data-product-info 가 없다`);
                    if (!c.hasBrand) fail('card-dom', `${at}: 브랜드/카테고리 표기가 없다`);
                    if (!c.hasPrice) fail('card-dom', `${at}: 가격 표기가 없다`);

                    if (c.infoBelowImage === false) {
                        fail('card-dom', `${at}: 상품 정보가 이미지 아래가 아니라 옆에 있다 (별도 셀처럼 배치됨)`);
                    }
                    if (c.infoLeftAligned === false) {
                        fail('card-dom', `${at}: 상품 정보의 왼쪽 정렬이 이미지와 어긋난다`);
                    }
                    for (const [key, label] of [['imageInsideCard', '이미지'], ['infoInsideCard', '상품 정보'], ['nameInsideCard', '상품명']]) {
                        if (c[key] === false) {
                            fail('card-dom', `${at}: ${label}가 카드 박스를 벗어난다`);
                        }
                    }
                }
            }
        }
    }
}

// ── 출력 ────────────────────────────────────────────────────────────────────
const CATEGORIES = {
    iife: 'IIFE 전역 계약 (운영 로더와 동일)',
    render: '렌더 오류',
    browser: '브라우저 실측 수행 여부',
    'browser-image': '이미지 실제 로드 (naturalWidth>0)',
    'browser-overflow': '가로 넘침 (브라우저 실측)',
    'browser-header': '모바일 헤더 실제 가시성',
    'browser-grid': '모바일 그리드 열 수·가독성',
    'browser-strip': '가로 스크롤 스트립',
    'card-dom': '상품 카드 DOM 계약 (실측)',
    i18n: '미해석 번역키',
    expr: '미해석 표현식',
    components: '미노출 컴포넌트',
    image: '깨진/외부 이미지',
    overflow: '모바일 가로 넘침(정적 휴리스틱)',
    required: '필수 요소 존재',
    locale: '로케일별 핵심 문구',
};

console.log('=== yutiv-commerce 프리뷰 렌더 검사 ===\n');
console.log(`페이지 ${counts.pages || 0}개 · <img> ${counts.images || 0}개 · 번역 네임스페이스 ${NAMESPACES.length}종 · 로케일 ${LOCALES.join(', ')}`);
console.log((counts.iifeGlobal || 'IIFE 전역: 확인 실패') + '\n');

let total = 0;
for (const [key, label] of Object.entries(CATEGORIES)) {
    const list = violations[key] || [];
    total += list.length;
    console.log(`${label.padEnd(34)} ${list.length ? 'FAIL' : 'OK  '} (${list.length})`);
    for (const m of list.slice(0, 8)) console.log('    - ' + m);
    if (list.length > 8) console.log(`    ... 외 ${list.length - 8}건`);
}

console.log(`\nRESULT: ${total ? 'FAIL' : 'PASS'} — ${total ? `총 ${total}건` : '위반 0건'}`);
console.log('\n브라우저 실측 포함: 이미지 로드(naturalWidth), 가시성/좌표, 가로 넘침, 그리드 열 수는');
console.log('헤드리스 Chrome 으로 실제 렌더해 잰 값이다 (output/browser-probe.json).');
console.log('포함되지 않는 것: actions 실행·데이터소스 fetch·클라이언트 effect·라우팅·주문/결제 흐름');
console.log('— 데이터는 픽스처이며 서버 E2E 의 대체물이 아니다.');
process.exit(total ? 1 : 0);
