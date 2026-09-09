/**
 * 브라우저 실측 프로브 — 헤드리스 Chrome/Edge 로 **실제 렌더 결과**를 잰다.
 *
 * 정적 HTML 검사(check.cjs)로는 알 수 없는 것들을 여기서 확인한다:
 *   - 이미지가 실제로 로드됐는가 (`img.complete && img.naturalWidth > 0`)
 *   - 모바일 헤더가 실제로 보이는가 (computed display/visibility + 화면 안 좌표)
 *   - 가로 넘침 (documentElement.scrollWidth > innerWidth)
 *   - 상품 그리드의 실제 열 수 (getComputedStyle(...).gridTemplateColumns)
 *   - 요소 간 겹침
 *
 * 동작 방식: 헤드리스 Chrome 의 `--window-size` 가 `--dump-dom` 모드에서 무시되므로,
 * 지정 폭 iframe 을 가진 호스트 페이지를 만들고 iframe 안의 페이지가 측정값을
 * `postMessage` 로 부모에 보낸다. 부모가 그 값을 DOM 에 적으면 `--dump-dom` 으로 회수한다.
 * (file:// 은 서로 opaque origin 이라 직접 접근은 막히지만 postMessage 는 통한다.)
 *
 * 브라우저를 못 찾으면 **실패로 보고한다** — 실측 없이 PASS 로 넘기지 않는다.
 *
 * 사용: node browser-probe.cjs [--out <build 출력 디렉토리>] [--browser <실행 파일 경로>]
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

function arg(name, fallback) {
    const i = process.argv.indexOf(name);
    return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

const OUT = arg('--out', path.join(__dirname, 'output'));
const PAGES = path.join(OUT, 'pages');
const PROBE = path.join(OUT, 'probe');

const BROWSER_CANDIDATES = [
    process.env.G7_PREVIEW_BROWSER,
    arg('--browser', null),
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
].filter(Boolean);

function findBrowser() {
    for (const c of BROWSER_CANDIDATES) {
        try {
            if (fs.existsSync(c)) return c;
        } catch { /* 무시 */ }
    }
    return null;
}

/** iframe 안에서 실행되어 측정값을 부모로 보내는 스크립트. */
const PROBE_SCRIPT = `
<script>
(function () {
  function measure() {
    var out = { ok: true };
    out.innerWidth = window.innerWidth;
    out.scrollWidth = document.documentElement.scrollWidth;
    out.bodyScrollWidth = document.body ? document.body.scrollWidth : 0;
    out.horizontalOverflow = out.scrollWidth - out.innerWidth;

    // 1. 이미지 — 로드 완료 + 실제 픽셀 크기
    var imgs = Array.prototype.slice.call(document.images);
    out.images = { total: imgs.length, loaded: 0, broken: [], zeroSize: [] };
    imgs.forEach(function (img, i) {
      var okLoad = img.complete && img.naturalWidth > 0 && img.naturalHeight > 0;
      if (okLoad) out.images.loaded++;
      else out.images.broken.push({ i: i, src: String(img.currentSrc || img.src).slice(0, 60), complete: img.complete, nw: img.naturalWidth });
      var r = img.getBoundingClientRect();
      if (r.width < 1 || r.height < 1) out.images.zeroSize.push({ i: i, w: Math.round(r.width), h: Math.round(r.height) });
    });

    // 2. 실제로 보이는가 — computed style + 화면 안 좌표
    function visible(el) {
      if (!el) return null;
      var node = el, cs;
      while (node && node.nodeType === 1) {
        cs = getComputedStyle(node);
        if (cs.display === 'none') return { visible: false, reason: 'display:none', at: node.className || node.tagName };
        if (cs.visibility === 'hidden') return { visible: false, reason: 'visibility:hidden', at: node.className || node.tagName };
        if (parseFloat(cs.opacity) === 0) return { visible: false, reason: 'opacity:0', at: node.className || node.tagName };
        node = node.parentElement;
      }
      var r = el.getBoundingClientRect();
      if (r.width < 1 || r.height < 1) return { visible: false, reason: 'zero-size', rect: [r.width, r.height] };
      var inViewX = r.left >= -1 && r.right <= window.innerWidth + 1;
      return {
        visible: true,
        rect: { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height) },
        insideViewportX: inViewX
      };
    }

    // 후보 중 "보이면서 뷰포트 안" 인 것을 우선한다.
    // 닫힌 오프캔버스 드로어의 요소도 display 상으로는 보이지만 화면 밖에 있으므로,
    // 그것을 먼저 집으면 로케일에 따라 판정이 흔들린다(문구 기반 선택자의 함정).
    function pick(selector) {
      var list = Array.prototype.slice.call(document.querySelectorAll(selector));
      var firstVisible = null;
      for (var i = 0; i < list.length; i++) {
        var v = visible(list[i]);
        if (!v || !v.visible) continue;
        if (v.insideViewportX) return { found: true, count: list.length, info: v };
        if (!firstVisible) firstVisible = v;
      }
      if (firstVisible) return { found: true, count: list.length, info: firstVisible };
      return { found: false, count: list.length, info: list.length ? visible(list[0]) : null };
    }

    // 헤더 구성요소 — 각각 "화면에 실제로 보이는 것이 하나라도 있는가"
    out.header = {
      logo: (function () {
        var els = Array.prototype.slice.call(document.querySelectorAll('button, a, span, div'));
        for (var i = 0; i < els.length; i++) {
          if (els[i].children.length === 0 && /YUTIV/.test(els[i].textContent || '')) {
            var v = visible(els[i]);
            if (v && v.visible) return { found: true, info: v };
          }
        }
        return { found: false, info: null };
      })(),
      search: pick('[data-testid="mobile-search"], input[type="text"], input[type="search"], [data-testid*="search"]'),
      searchIcon: pick('.fa-search, .fa-magnifying-glass'),
      cart: pick('.fa-shopping-cart, .fa-cart-shopping'),
      menu: pick('.fa-bars'),
      user: pick('.fa-user')
    };

    // 3. 상품 그리드 실제 열 수
    out.grids = Array.prototype.slice.call(document.querySelectorAll('div[class*="grid-cols-"]'))
      .map(function (g) {
        var cs = getComputedStyle(g);
        if (cs.display !== 'grid') return null;
        var cols = (cs.gridTemplateColumns || '').trim().split(/\\s+/).filter(Boolean);
        var r = g.getBoundingClientRect();
        if (r.width < 1) return null;
        return { columns: cols.length, colWidth: Math.round(parseFloat(cols[0]) || 0), width: Math.round(r.width), cls: String(g.className).slice(0, 90) };
      })
      .filter(Boolean);

    // 4. 상품 카드 — 이름/가격 글자 크기와 카드 폭
    var cards = Array.prototype.slice.call(document.querySelectorAll('h3'))
      .filter(function (h) { return /line-clamp-2/.test(h.className); });
    out.cards = cards.slice(0, 4).map(function (h) {
      var box = h.closest('button') || h.parentElement;
      var r = box.getBoundingClientRect();
      var cs = getComputedStyle(h);
      return { cardWidth: Math.round(r.width), nameFontPx: Math.round(parseFloat(cs.fontSize)), lineHeight: Math.round(parseFloat(cs.lineHeight)) };
    });
    out.cardCount = cards.length;

    // 4-b. 상품 카드 구조 — 한 상품 = grid 의 direct child 1개인가.
    //      이미지와 정보가 형제 셀로 나뉘면 한 상품이 두 칸을 차지해 데스크톱에서는
    //      이미지 옆에 이름이 서고 모바일에서는 이웃 카드와 글자가 겹친다.
    function rectOf(el) {
      var r = el.getBoundingClientRect();
      return { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height),
               right: Math.round(r.right), bottom: Math.round(r.bottom) };
    }
    function boxOverlap(a, b) {
      var ox = Math.min(a.right, b.right) - Math.max(a.x, b.x);
      var oy = Math.min(a.bottom, b.bottom) - Math.max(a.y, b.y);
      return (ox > 1 && oy > 1) ? { x: ox, y: oy } : null;
    }
    function contains(outer, inner) {
      return inner.x >= outer.x - 1 && inner.right <= outer.right + 1 &&
             inner.y >= outer.y - 1 && inner.bottom <= outer.bottom + 1;
    }

    // 카드 껍데기 없이 이미지/정보만 반복되는 경우를 잡는다.
    // 카드 래퍼가 통째로 빠지면 그 컨테이너에는 [data-product-card] 가 하나도 없어
    // "카드의 부모" 역추적으로는 검사 대상에서 아예 사라진다 — 그래서 부속 요소 쪽에서도 센다.
    function orphansOf(sel) {
      return Array.prototype.slice.call(document.querySelectorAll(sel))
        .filter(function (el) { return el.getBoundingClientRect().width >= 1; })
        .filter(function (el) { return !el.closest('[data-product-card]'); })
        .map(function (el) {
          var par = el.parentElement;
          return {
            sel: sel,
            parentCls: par ? String(par.className).slice(0, 70) : null,
            parentDisplay: par ? getComputedStyle(par).display : null
          };
        });
    }
    var visibleCount = function (sel) {
      return Array.prototype.slice.call(document.querySelectorAll(sel))
        .filter(function (el) { return el.getBoundingClientRect().width >= 1; }).length;
    };
    out.productParts = {
      cards: visibleCount('[data-product-card]'),
      images: visibleCount('[data-product-image]'),
      names: visibleCount('[data-product-name]'),
      infos: visibleCount('[data-product-info]'),
      orphanImages: orphansOf('[data-product-image]'),
      orphanNames: orphansOf('[data-product-name]'),
      orphanInfos: orphansOf('[data-product-info]')
    };

    // 상품이 반복되는 컨테이너를 카드의 부모로부터 역으로 찾는다.
    // (그리드뿐 아니라 캐러셀(grid-flow-col + auto-cols) 도 포함해야 한다 —
    //  클래스 이름으로 찾으면 캐러셀이 검사 대상에서 통째로 빠진다.)
    var cardParents = [];
    Array.prototype.slice.call(document.querySelectorAll('[data-product-card]')).forEach(function (c) {
      if (c.parentElement && cardParents.indexOf(c.parentElement) === -1) cardParents.push(c.parentElement);
    });

    out.productGrids = cardParents
      .map(function (g) {
        var gr = g.getBoundingClientRect();
        if (gr.width < 1) return null; // 화면에 없는 컨테이너(닫힌 드로어 등)는 건너뛴다
        var inside = Array.prototype.slice.call(g.querySelectorAll('[data-product-card]'));
        if (!inside.length) return null;

        var gcs = getComputedStyle(g);
        var kids = Array.prototype.slice.call(g.children);
        var isGrid = gcs.display === 'grid';
        var cols = isGrid
          ? (gcs.gridTemplateColumns || '').trim().split(/\\s+/).filter(Boolean).length
          : null;
        var isCarousel = /grid-flow-col|auto-cols-/.test(String(g.className));

        var cards = kids.map(function (k) {
          var isCard = k.hasAttribute && k.hasAttribute('data-product-card');
          var cr = rectOf(k);
          var imgs = k.querySelectorAll('[data-product-image]');
          var names = k.querySelectorAll('[data-product-name]');
          var info = k.querySelector('[data-product-info]');
          var brand = k.querySelector('[data-product-brand]');
          var price = k.querySelector('[data-product-price]');
          var cs = getComputedStyle(k);

          var imgRect = imgs.length ? rectOf(imgs[0]) : null;
          var infoRect = info ? rectOf(info) : null;
          var nameRect = names.length ? rectOf(names[0]) : null;

          // 이미지와 상품명이 같은 카드에 속하는가
          var sameOwner = null;
          if (imgs.length && names.length) {
            sameOwner = imgs[0].closest('[data-product-card]') === names[0].closest('[data-product-card]');
          }

          return {
            isCard: !!isCard,
            productId: isCard ? k.getAttribute('data-product-id') : null,
            display: cs.display,
            flexDirection: cs.flexDirection,
            rect: cr,
            imageCount: imgs.length,
            nameCount: names.length,
            hasInfo: !!info,
            hasBrand: !!brand,
            hasPrice: !!price,
            sameOwner: sameOwner,
            // 정보가 이미지 "아래" 인가 (오른쪽 별도 칸이면 실패)
            infoBelowImage: (imgRect && infoRect) ? (infoRect.y >= imgRect.bottom - 2) : null,
            infoLeftAligned: (imgRect && infoRect) ? (Math.abs(infoRect.x - imgRect.x) <= 2) : null,
            // 카드 안 요소가 카드 박스를 벗어나지 않는가
            imageInsideCard: imgRect ? contains(cr, imgRect) : null,
            infoInsideCard: infoRect ? contains(cr, infoRect) : null,
            nameInsideCard: nameRect ? contains(cr, nameRect) : null,
            textRects: [brand, names[0], price].filter(Boolean).map(rectOf)
          };
        });

        // 카드 박스끼리 겹침
        var cardOverlaps = [];
        for (var a = 0; a < cards.length; a++) {
          for (var b = a + 1; b < cards.length; b++) {
            var ov = boxOverlap(cards[a].rect, cards[b].rect);
            if (ov) cardOverlaps.push({ a: a, b: b, overlap: ov });
          }
        }
        // 서로 다른 카드의 텍스트끼리 겹침
        var textOverlaps = [];
        for (var i2 = 0; i2 < cards.length; i2++) {
          for (var j2 = i2 + 1; j2 < cards.length; j2++) {
            for (var ti = 0; ti < cards[i2].textRects.length; ti++) {
              for (var tj = 0; tj < cards[j2].textRects.length; tj++) {
                var to = boxOverlap(cards[i2].textRects[ti], cards[j2].textRects[tj]);
                if (to) textOverlaps.push({ a: i2, b: j2, overlap: to });
              }
            }
          }
        }

        return {
          cls: String(g.className).slice(0, 90),
          display: gcs.display,
          isCarousel: isCarousel,
          columns: cols,
          width: Math.round(gr.width),
          directChildren: kids.length,
          cardsAmongDirectChildren: cards.filter(function (c) { return c.isCard; }).length,
          totalCardsInside: inside.length,
          nonCardDirectChildren: cards.filter(function (c) { return !c.isCard; }).length,
          cards: cards,
          cardOverlaps: cardOverlaps,
          textOverlaps: textOverlaps
        };
      })
      .filter(Boolean);

    // 5. 가로 스크롤 스트립 — 스크롤 가능 여부와 스크롤바 두께
    out.strips = Array.prototype.slice.call(document.querySelectorAll('[class*="overflow-x-auto"]'))
      .map(function (s) {
        var r = s.getBoundingClientRect();
        if (r.width < 1) return null;
        return {
          scrollable: s.scrollWidth > s.clientWidth + 1,
          scrollbarPx: Math.round(r.height - s.clientHeight),
          scrollWidth: s.scrollWidth,
          clientWidth: s.clientWidth
        };
      })
      .filter(Boolean);

    return out;
  }

  function send() {
    var payload;
    try { payload = measure(); } catch (e) { payload = { ok: false, error: String(e && e.message || e) }; }
    try { parent.postMessage(JSON.stringify(payload), '*'); } catch (e) { /* 무시 */ }
  }

  if (document.readyState === 'complete') setTimeout(send, 120);
  else window.addEventListener('load', function () { setTimeout(send, 120); });
})();
</script>
`;

function hostHtml(innerFile, width, height) {
    return `<!doctype html><html><head><meta charset="utf-8"><title>probe</title>
<style>html,body{margin:0}iframe{display:block;width:${width}px;height:${height}px;border:0}</style>
</head><body>
<iframe src="./${innerFile}" id="f"></iframe>
<pre id="probe-result">PENDING</pre>
<script>
window.addEventListener('message', function (e) {
  document.getElementById('probe-result').textContent = String(e.data);
});
</script>
</body></html>`;
}

function main() {
    const browser = findBrowser();
    if (!fs.existsSync(path.join(OUT, 'render-report.json'))) {
        console.error(`FATAL: ${OUT}/render-report.json 이 없습니다. 먼저 'node build.cjs' 를 실행하세요.`);
        process.exit(2);
    }
    const report = JSON.parse(fs.readFileSync(path.join(OUT, 'render-report.json'), 'utf8'));

    if (!browser) {
        const out = {
            browserAvailable: false,
            reason: '헤드리스 Chrome/Edge 를 찾지 못했습니다. --browser <경로> 또는 G7_PREVIEW_BROWSER 로 지정하세요.',
            results: {},
        };
        fs.writeFileSync(path.join(OUT, 'browser-probe.json'), JSON.stringify(out, null, 2), 'utf8');
        console.error('FATAL: ' + out.reason);
        console.error('브라우저 실측 없이는 이미지 로드·가시성·가로 넘침을 PASS 로 보고할 수 없습니다.');
        process.exit(2);
    }

    console.log('브라우저: ' + browser + '\n');

    fs.rmSync(PROBE, { recursive: true, force: true });
    fs.mkdirSync(PROBE, { recursive: true });

    const results = {};
    for (const p of report.pages) {
        // 계측 스크립트를 붙인 페이지 사본 (pages/ 원본은 건드리지 않는다)
        const raw = fs.readFileSync(path.join(PAGES, p.file), 'utf8');
        const instrumented = raw.replace('</body>', PROBE_SCRIPT + '</body>');
        const innerName = 'inner-' + p.file;
        fs.writeFileSync(path.join(PROBE, innerName), instrumented, 'utf8');

        const hostName = 'host-' + p.file;
        const height = p.width <= 767 ? 3400 : 3600;
        fs.writeFileSync(path.join(PROBE, hostName), hostHtml(innerName, p.width, height), 'utf8');

        const url = 'file:///' + path.join(PROBE, hostName).replace(/\\/g, '/');
        let dom = '';
        try {
            dom = execFileSync(browser, [
                '--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files',
                '--virtual-time-budget=6000', '--run-all-compositor-stages-before-draw',
                '--dump-dom', url,
            ], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, stdio: ['ignore', 'pipe', 'ignore'] });
        } catch (e) {
            results[p.file] = { ok: false, error: '브라우저 실행 실패: ' + e.message };
            console.log(`${p.file.padEnd(32)} 실행 실패`);
            continue;
        }

        const m = dom.match(/<pre id="probe-result">([\s\S]*?)<\/pre>/);
        if (!m || m[1].trim() === 'PENDING') {
            results[p.file] = { ok: false, error: '측정값을 회수하지 못했습니다 (스크립트 미실행 또는 타임아웃)' };
            console.log(`${p.file.padEnd(32)} 측정 실패`);
            continue;
        }
        const decoded = m[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
        let data;
        try {
            data = JSON.parse(decoded);
        } catch (e) {
            results[p.file] = { ok: false, error: 'JSON 파싱 실패: ' + e.message };
            console.log(`${p.file.padEnd(32)} 파싱 실패`);
            continue;
        }
        data.viewport = p.width;
        data.locale = p.locale;
        data.kind = p.kind;
        results[p.file] = data;

        const g = (data.grids || []).map((x) => x.columns).join('/');
        console.log(
            `${p.file.padEnd(32)} vw=${String(data.innerWidth).padStart(4)} ` +
            `img ${data.images.loaded}/${data.images.total} ` +
            `overflow=${data.horizontalOverflow} grid=[${g}] 카드=${data.cardCount}`
        );
    }

    fs.writeFileSync(
        path.join(OUT, 'browser-probe.json'),
        JSON.stringify({ browserAvailable: true, browser, generatedAt: new Date().toISOString(), results }, null, 2),
        'utf8'
    );
    console.log('\n측정 결과: ' + path.join(OUT, 'browser-probe.json'));
}

main();
