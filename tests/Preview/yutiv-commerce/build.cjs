/**
 * yutiv-commerce 프리뷰 생성기.
 *
 * 산출물 3종:
 *   pages/<id>.html        크롬 없는 순수 페이지 (실제 dist CSS 인라인)
 *   clean/<id>-<vp>.html   지정 뷰포트 폭의 iframe 한 장만 — 사용자 검토·캡처용
 *   diag/<id>-<vp>.html    같은 iframe + 진단 막대/점선 경계 — 개발자용
 *
 * iframe 을 쓰는 이유: CSS 미디어쿼리(`sm:` `lg:`)는 **뷰포트** 폭을 본다.
 * 페이지를 1440px 창 안의 390px div 에 넣으면 모바일 스타일이 적용되지 않는다.
 * iframe 안에서는 iframe 폭이 곧 뷰포트라 실제 모바일 렌더가 재현된다.
 *
 * 사용:  node build.js [--umd <React18 UMD 디렉토리>] [--out <출력 디렉토리>]
 */
const fs = require('fs');
const path = require('path');

const { createRuntime, loadLocale } = require('./runtime.cjs');
const { makeResolver, buildTree, makeRenderer } = require('./engine.cjs');
const F = require('./fixtures.cjs');

// ---------------------------------------------------------------- 경로/인자

const REPO = path.resolve(__dirname, '..', '..', '..');
const TPL = path.join(REPO, 'templates', '_bundled', 'yutiv-commerce');
const LAYOUTS = path.join(TPL, 'layouts');

function arg(name, fallback) {
    const i = process.argv.indexOf(name);
    return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

const UMD = arg('--umd', process.env.G7_PREVIEW_UMD || path.join(__dirname, '.umd'));
const OUT = arg('--out', path.join(__dirname, 'output'));

// ---------------------------------------------------------------- 페이지 정의

const VIEWPORTS = {
    'desktop-1440': { label: '데스크톱 1440px', width: 1440, height: 3600 },
    'tablet-768': { label: '태블릿 768px', width: 768, height: 3600 },
    'mobile-390': { label: '모바일 390px', width: 390, height: 3400 },
};

const PAGES = [
    { id: 'home', title: '홈', layout: 'home', kind: 'home', locale: 'ko', viewports: ['desktop-1440', 'tablet-768', 'mobile-390'] },
    { id: 'home-loading', title: '홈 (로딩 스켈레톤)', layout: 'home', kind: 'home-loading', locale: 'ko', viewports: ['desktop-1440'] },
    { id: 'home-empty', title: '홈 (상품 0건)', layout: 'home', kind: 'home-empty', locale: 'ko', viewports: ['desktop-1440', 'mobile-390'] },
    { id: 'home-zhCN', title: '홈 (중국어 간체)', layout: 'home', kind: 'home', locale: 'zh-CN', viewports: ['desktop-1440', 'mobile-390'] },
    { id: 'home-ja', title: '홈 (일본어)', layout: 'home', kind: 'home', locale: 'ja', viewports: ['desktop-1440'] },
    { id: 'home-en', title: '홈 (영어)', layout: 'home', kind: 'home', locale: 'en', viewports: ['desktop-1440'] },
    { id: 'shop-list', title: '상품 목록', layout: 'shop/index', kind: 'shop-list', locale: 'ko', viewports: ['desktop-1440', 'mobile-390'] },
    { id: 'product', title: '상품 상세', layout: 'shop/show', kind: 'product', locale: 'ko', viewports: ['desktop-1440', 'mobile-390'] },
    {
        // 구매 영역까지 보이는 모바일 캡처 — 같은 페이지를 구매 카드 앵커로 열고 프레임 높이를 낮춘다.
        id: 'product-buy', title: '상품 상세 (구매 영역)', layout: 'shop/show', kind: 'product', locale: 'ko',
        viewports: ['mobile-390'], anchor: 'product_purchase', frameHeight: 900,
    },
];

/** 템플릿이 선언한 로케일 — 엔진의 `$locales` 특수 변수와 같은 값. */
const TEMPLATE_LOCALES = JSON.parse(fs.readFileSync(path.join(TPL, 'template.json'), 'utf8')).locales;

/**
 * `_user_base` 가 모든 화면에서 참조하는 데이터소스.
 * (boards / notifications / cart / current_user — layouts/_user_base.json 의 data_sources[].id)
 */
function baseScope() {
    return {
        boards: { data: [], loading: false },
        notifications: { data: { data: [], pagination: { has_more_pages: false } }, loading: false },
        notification_unread_count: { data: { count: 0 }, loading: false },
        cart: { data: { count: 2 }, loading: false },
        current_user: { data: null, loading: false },
        // 상품 상세가 참조하는 보조 소스
        qna: { data: { data: [], meta: { total: 0 }, pagination: { current_page: 1, last_page: 1, total: 0 } }, loading: false },
        productDownloadableCoupons: { data: { data: [] }, loading: false },
    };
}

/**
 * 데이터소스 스코프를 만든다. 키 이름은 각 레이아웃의 `data_sources[].id` 와 일치해야 한다.
 * 형태(`data` / `data.data` / `loading`)도 실제 엔진이 노출하는 모양 그대로다.
 */
function dataScope(kind, locale) {
    const all = F.products(locale, 12);
    const cats = F.categories(locale);
    const paged = (items) => ({
        data: { data: items, pagination: { current_page: 1, per_page: 12, last_page: 1, total: items.length, has_more_pages: false } },
        loading: false,
    });

    switch (kind) {
        case 'home':
            return {
                categories: { data: cats, loading: false },
                newProducts: { data: all.slice(0, 8), loading: false },
                popularProducts: { data: all.slice(4, 12), loading: false },
                products: paged(all.slice(0, 8)),
            };
        case 'home-loading':
            return {
                categories: { data: cats, loading: false },
                newProducts: { data: [], loading: true },
                popularProducts: { data: [], loading: true },
                products: { data: { data: [] }, loading: true },
            };
        case 'home-empty':
            return {
                categories: { data: [], loading: false },
                newProducts: { data: [], loading: false },
                popularProducts: { data: [], loading: false },
                products: { data: { data: [], pagination: { current_page: 1, per_page: 8, has_more_pages: false } }, loading: false },
            };
        case 'shop-list':
            return {
                categories: { data: cats, loading: false },
                products: paged(all),
                recentProducts: { data: [], loading: false },
                popularProducts: { data: all.slice(0, 8), loading: false },
                newProducts: { data: all.slice(4, 12), loading: false },
            };
        case 'product': {
            const detail = F.productDetail(locale);
            return {
                product: { data: detail, loading: false },
                reviews: { data: { data: [], rating_stats: {}, pagination: { current_page: 1, last_page: 1, total: 0 } }, loading: false },
                inquiries: { data: { data: [], pagination: { current_page: 1, last_page: 1, total: 0 } }, loading: false },
                relatedProducts: { data: F.products(locale, 12).slice(1, 5), loading: false },
                popularProducts: { data: F.products(locale, 12).slice(4, 12), loading: false },
                newProducts: { data: F.products(locale, 12).slice(0, 8), loading: false },
                recentProducts: { data: [], loading: false },
            };
        }
        default:
            return {};
    }
}

/** 레이아웃이 참조하는 route/query/_local 기본값. */
function routeScope(kind) {
    switch (kind) {
        case 'shop-list':
            return { route: { path: '/shop/products' }, query: {}, _local: {} };
        case 'product':
            return {
                route: { path: '/shop/products/YTVBEA0001', product_code: 'YTVBEA0001' },
                query: {},
                // 상품 상세는 수량/탭을 _local 로 들고 있다. 초기 마운트 시점의 값과 같게 준다.
                _local: { noOptionQuantity: 1, activeTab: 'detail', isOrdering: false, wishlistLoading: false },
            };
        default:
            return { route: { path: '/' }, query: {}, _local: {} };
    }
}

// ---------------------------------------------------------------- 렌더

const CSS = fs.readFileSync(path.join(TPL, 'dist/css/components.css'), 'utf8');

/**
 * 아이콘 폰트를 페이지에 붙인다.
 *
 * 템플릿의 `Icon` 컴포넌트는 Font Awesome 클래스(`fas fa-bars` 등)를 붙인 `<i>` 를 낸다.
 * FA 스타일시트는 **코어 호스트 페이지가 제공**하며 이 저장소에는 없다. 프리뷰에 이걸
 * 넣지 않으면 아이콘 전용 버튼(장바구니·햄버거)이 0×0 으로 접혀 "보이지 않는다" 는
 * 잘못된 측정 결과가 나온다 — 실제 운영 화면과 다른 상태다.
 * `.vendor/fontawesome` 에 받아 두고 output 으로 복사해 상대경로로 링크한다.
 */
const FA_SRC = path.join(__dirname, '.vendor', 'fontawesome');
const FA_AVAILABLE = fs.existsSync(path.join(FA_SRC, 'css', 'all.min.css'));

function purePage(html, locale, title) {
    const faLink = FA_AVAILABLE
        ? '<link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css">'
        : '<!-- Font Awesome 미설치: 아이콘이 0×0 으로 접힙니다. README 의 아이콘 폰트 항목 참조 -->';
    return `<!doctype html>
<html lang="${locale}"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${title}</title>
${faLink}
<style>${CSS}</style>
<style>html,body{margin:0;padding:0}</style>
</head><body>${html}</body></html>`;
}

/** 디렉토리 재귀 복사 (Node 16+ 의 cpSync). */
function copyDir(src, dest) {
    fs.cpSync(src, dest, { recursive: true });
}

function frameHost(pageFile, title, vp, locale, diagnostic, notes, opts) {
    // anchor: 페이지 안 특정 영역까지 스크롤된 상태로 캡처하기 위한 URL 프래그먼트
    const anchor = (opts && opts.anchor) ? '#' + opts.anchor : '';
    const frameHeight = (opts && opts.frameHeight) || vp.height;
    const chrome = diagnostic
        ? `<div class="bar">yutiv-commerce — <b>${title}</b> · ${vp.label} · locale=${locale} · 실제 dist/css + dist/js SSR</div>
<div class="note">진단용: 데이터는 픽스처, 클라이언트 effect/네트워크 없음.${notes ? ' ' + notes : ''}</div>`
        : '';
    const frameCss = diagnostic
        ? 'border:1px dashed #bbb;'
        : 'border:0;';
    return `<!doctype html>
<html lang="${locale}"><head><meta charset="utf-8"><title>${title} — ${vp.label}</title>
<style>
  html,body{margin:0;padding:0;background:#fff}
  .bar{background:#111;color:#fff;font:12px/1.6 ui-monospace,monospace;padding:8px 12px}
  .bar b{color:#8fd}
  .note{background:#2b2b2b;color:#ffd;font:11px/1.6 ui-monospace,monospace;padding:6px 12px}
  iframe{display:block;width:${vp.width}px;height:${frameHeight}px;margin:0 auto;${frameCss}}
</style></head><body>
${chrome}
<iframe src="../pages/${pageFile}${anchor}" title="${title}" loading="eager"></iframe>
</body></html>`;
}

function main() {
    fs.rmSync(OUT, { recursive: true, force: true });
    for (const d of ['pages', 'clean', 'diag']) fs.mkdirSync(path.join(OUT, d), { recursive: true });

    if (FA_AVAILABLE) {
        copyDir(FA_SRC, path.join(OUT, 'vendor', 'fontawesome'));
    } else {
        console.warn('경고: .vendor/fontawesome 가 없습니다 — 아이콘이 0×0 으로 접혀 헤더 가시성 측정이 틀어집니다.');
        console.warn('      README.md 의 "아이콘 폰트" 항목대로 받아 두세요.\n');
    }

    const report = [];
    const missingKeysAll = new Set();
    const missingComponentsAll = new Set();
    const exprErrorsAll = new Set();

    for (const p of PAGES) {
        const lang = loadLocale(TPL, p.locale);
        const globalState = F.globalState(p.locale);

        const missingKeys = [];
        const rt = createRuntime({ templateDir: TPL, umdDir: UMD, lang, globalState, missingKeys });
        const { React, ReactDOMServer, lib } = rt;

        for (const vpId of p.viewports) {
            const vp = VIEWPORTS[vpId];
            const exprErrors = [];
            const missingComponents = [];

            const resolve = makeResolver({
                t: (key, params) => rt.G7Core.t(key, params),
                onExprError: (src, err) => exprErrors.push(src + ' → ' + err.message),
            });
            const render = makeRenderer({
                React,
                lib,
                resolve,
                viewport: vp,
                onMissingComponent: (n) => missingComponents.push(n),
            });

            const scope = {
                // _user_base 가 모든 화면에서 참조하는 공통 데이터소스 — 페이지별 스코프보다 먼저 깔고
                // 페이지가 같은 id 를 쓰면 덮어쓴다.
                ...baseScope(),
                ...dataScope(p.kind, p.locale),
                ...routeScope(p.kind),
                _global: globalState,
                _computed: {},
                _isolated: { scrollIdx: 0 },
                // 엔진이 주입하는 로케일 특수 변수 (레이아웃의 언어 선택기가 참조한다)
                $locale: p.locale,
                $locales: TEMPLATE_LOCALES,
            };

            let html = '';
            let error = '';
            try {
                const tree = buildTree(LAYOUTS, p.layout);
                const els = tree.map((n) => render(n, scope)).filter(Boolean);
                html = ReactDOMServer.renderToStaticMarkup(React.createElement(React.Fragment, null, els));
            } catch (e) {
                error = e.message;
                html = '<pre style="color:#c00;padding:16px">' + String(e.stack || e.message) + '</pre>';
            }

            const pageFile = `${p.id}-${vpId}.html`;
            fs.writeFileSync(path.join(OUT, 'pages', pageFile), purePage(html, p.locale, `${p.title} — ${vp.label}`), 'utf8');
            const frameOpts = { anchor: p.anchor, frameHeight: p.frameHeight };
            fs.writeFileSync(path.join(OUT, 'clean', pageFile), frameHost(pageFile, p.title, vp, p.locale, false, '', frameOpts), 'utf8');
            fs.writeFileSync(path.join(OUT, 'diag', pageFile), frameHost(pageFile, p.title, vp, p.locale, true, error ? '렌더 오류: ' + error : '', frameOpts), 'utf8');

            missingComponents.forEach((x) => missingComponentsAll.add(x));
            exprErrors.forEach((x) => exprErrorsAll.add(x));

            report.push({
                id: p.id, file: pageFile, title: p.title, vp: vp.label, viewport: vpId, width: vp.width,
                locale: p.locale, kind: p.kind, bytes: html.length, error,
                missingComponents: [...new Set(missingComponents)],
                exprErrors: [...new Set(exprErrors)],
            });
            console.log(`${pageFile.padEnd(32)} ${String(html.length).padStart(7)} bytes${error ? '  ERROR: ' + error : ''}`);
        }

        missingKeys.forEach((k) => missingKeysAll.add(k));
    }

    // 검사기가 읽는 렌더 메타데이터
    fs.writeFileSync(
        path.join(OUT, 'render-report.json'),
        JSON.stringify({
            generatedAt: new Date().toISOString(),
            template: 'yutiv-commerce',
            pages: report,
            missingTranslationKeys: [...missingKeysAll].sort(),
            missingComponents: [...missingComponentsAll].sort(),
            expressionErrors: [...exprErrorsAll].sort(),
        }, null, 2),
        'utf8'
    );

    const link = (d, r) => `<li><a href="${d}/${r.file}">${r.title} — ${r.vp}</a> <code>${r.bytes.toLocaleString()} B</code></li>`;
    fs.writeFileSync(
        path.join(OUT, 'index.html'),
        `<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>yutiv-commerce 프리뷰</title>
<style>body{font:14px/1.7 system-ui;margin:40px auto;max-width:900px;padding:0 16px}a{color:#06c}
code{background:#f2f2f2;padding:1px 5px;border-radius:3px}h2{margin-top:2em}li{margin:.3em 0}</style></head><body>
<h1>yutiv-commerce 프리뷰</h1>
<p>템플릿의 실제 <code>dist/js/components.iife.js</code> 를 Node 안에서 실행하고 실제
<code>dist/css/components.css</code> 를 인라인했습니다. 문구는 런타임과 같은 경로
(<code>G7Core.t()</code> + 레이아웃 <code>$t:</code>)로 해석합니다.</p>
<p>각 캡처는 지정 폭의 iframe 안에서 렌더되므로 CSS 미디어쿼리가 실제 뷰포트처럼 동작합니다.</p>
<h2>클린 (검토·캡처용)</h2><ul>${report.map((r) => link('clean', r)).join('')}</ul>
<h2>진단용 (막대·경계 포함)</h2><ul>${report.map((r) => link('diag', r)).join('')}</ul>
<p><b>한계:</b> 데이터는 픽스처이고 actions·데이터소스 fetch·클라이언트 effect는 실행되지 않습니다.
실제 서버 E2E 검증의 대체물이 아닙니다.</p>
</body></html>`,
        'utf8'
    );

    console.log('\n미등록 번역키: ' + (missingKeysAll.size ? [...missingKeysAll].join(', ') : '없음'));
    console.log('미노출 컴포넌트: ' + (missingComponentsAll.size ? [...missingComponentsAll].join(', ') : '없음'));
    console.log('표현식 오류: ' + (exprErrorsAll.size ? exprErrorsAll.size + '건' : '없음'));
    console.log('\nindex: ' + path.join(OUT, 'index.html'));
}

main();
