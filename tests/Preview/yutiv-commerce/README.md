# yutiv-commerce 프리뷰 렌더러 · 검사기

레이아웃 JSON 을 **실제 템플릿 번들로 렌더**해서 화면을 확인하고, 렌더 결과를 자동 검사한다.
로컬에 Laravel 앱(`vendor/`)이나 브라우저 자동화가 없어도 동작한다.

## 왜 이게 필요한가

정적 JSON 검사(`tests/Translations/yutiv-commerce-template-check.php`)로는 못 잡는 결함이 있다.

- **런타임 번역 누락** — 템플릿의 composite 컴포넌트(Header/Footer/SearchBar/ProductImageViewer…)는
  문구를 레이아웃 JSON 이 아니라 전역 `window.G7Core.t(key)` 에서 가져온다.
  ```ts
  // src/components/composite/Header.tsx
  const t = (key, params) => window.G7Core?.t?.(key, params) ?? key;
  ```
  `G7Core` 가 없으면 **번역키를 그대로 출력**한다. 실제로 `nav.home`, `auth.login`,
  `common.search_placeholder` 가 화면에 노출된 적이 있고, 이 툴이 그걸 잡는다.
- **표현식 미해석** — 데이터소스나 특수 변수(`$locale`, `$locales`)가 빠지면 `{{...}}` 가 그대로 남는다.
- **깨진 이미지** — `thumbnail_url` 은 nullable 이라 `<img src="">` 가 될 수 있다.

프리뷰는 문자열을 치환해 감추지 않는다. **런타임 계약을 구현**해서 실제와 같은 경로로 문구를 만든다.

## 준비 — React 18 UMD

번들은 React 를 external 로 두고 전역(`React`, `ReactDOM`, `ReactJSXRuntime`)을 기대한다.
React 19 는 UMD 빌드를 배포하지 않으므로 React 18 UMD 로 렌더한다. 이 템플릿은 React 19 전용
API(`useActionState`/`useOptimistic`/`use`)를 쓰지 않아 렌더 결과가 같다.

```bash
mkdir -p tests/Preview/yutiv-commerce/.umd && cd $_
curl -sLO https://unpkg.com/react@18.3.1/umd/react.production.min.js
curl -sLO https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js
curl -sLO https://unpkg.com/react-dom@18.3.1/umd/react-dom-server-legacy.browser.production.min.js
```

다른 위치에 두려면 `--umd <dir>` 또는 `G7_PREVIEW_UMD` 환경변수로 지정한다.
`.umd/` 와 `output/` 은 이 디렉토리의 `.gitignore` 로 추적에서 제외한다.

## 준비 2 — 아이콘 폰트 (Font Awesome)

템플릿의 `Icon` 컴포넌트는 Font Awesome 클래스(`fas fa-bars`)를 붙인 `<i>` 를 낸다.
FA 스타일시트는 **코어 호스트 페이지가 제공**하며 이 저장소에는 없다. 프리뷰에 넣지 않으면
아이콘 전용 버튼(장바구니·햄버거)이 0×0 으로 접혀 "안 보인다" 는 **잘못된 측정**이 나온다.

```bash
cd tests/Preview/yutiv-commerce
mkdir -p .vendor/fontawesome/css .vendor/fontawesome/webfonts
curl -sL -o .vendor/fontawesome/css/all.min.css \
  https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css
for f in fa-solid-900 fa-regular-400 fa-brands-400 fa-v4compatibility; do \
  curl -sL -o ".vendor/fontawesome/webfonts/$f.woff2" \
    "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/webfonts/$f.woff2"; done
```

`build.cjs` 가 이걸 `output/vendor/fontawesome/` 로 복사해 페이지에 링크한다.
없으면 경고를 내고 계속 진행하지만, 헤더 가시성 측정은 신뢰할 수 없다.

## 실행

```bash
cd tests/Preview/yutiv-commerce
node iife-global-check.cjs   # ★ IIFE 전역 계약 (운영 로더와 동일) — 필수 관문
node build.cjs           # 렌더 → output/
node browser-probe.cjs   # 헤드리스 Chrome/Edge 로 실측 → output/browser-probe.json
node check.cjs           # 정적 + 실측 결과 검사 (위반 시 exit 1)
node audit.cjs           # 사람이 읽는 내용 요약 (판정하지 않음)

# 검토용 프리뷰의 단일 기준 경로
#   tests/Preview/yutiv-commerce/output/clean/
```

## 산출물

```
output/
  pages/<id>-<vp>.html   크롬 없는 순수 페이지 (실제 dist CSS 인라인)
  clean/<id>-<vp>.html   지정 뷰포트 폭 iframe 한 장 — 사용자 검토·캡처용
  diag/<id>-<vp>.html    같은 iframe + 진단 막대·점선 경계 — 개발자용
  render-report.json     검사기가 읽는 렌더 메타데이터
  browser-probe.json     헤드리스 브라우저 실측값
  probe/                 실측용 계측 사본 (호스트 + 계측 스크립트 삽입 페이지)
  vendor/fontawesome/    아이콘 폰트 (페이지가 상대경로로 링크)
  index.html             전체 목록
```

**검토·캡처는 `clean/` 만 사용한다.** `pages/` 는 iframe 안에 들어가는 알맹이라
창 폭에 따라 미디어쿼리가 달라지고, `diag/` 는 진단 막대가 붙어 있다.

`clean` / `diag` 가 `iframe` 을 쓰는 이유: CSS 미디어쿼리(`sm:` `lg:`)는 **뷰포트** 폭을 본다.
1440px 창 안의 390px `div` 에 넣으면 모바일 스타일이 적용되지 않는다. iframe 안에서는
iframe 폭이 곧 뷰포트라 실제 모바일 렌더가 재현된다.

## 검사 항목 (`check.cjs`)

| 항목 | 내용 |
|---|---|
| **IIFE 전역 계약** | 번들을 실제 평가해 `window.YutivCommerce` 존재 확인 — 운영 활성화 실패를 막는 관문 (`check.cjs` 가 필수로 호출) |
| 렌더 오류 | 레이아웃 렌더 중 예외 |
| 미해석 번역키 | `$t:` 잔존, `G7Core.t` 미등록 키, 화면·속성에 노출된 dot-path 키 |
| 미해석 표현식 | `{{ ... }}` 잔존 |
| 미노출 컴포넌트 | 번들에 없는 컴포넌트 참조 |
| 깨진/외부 이미지 | 빈 `src`, 외부 `http(s)` 이미지, `alt` 누락 |
| 모바일 가로 넘침(정적) | 정적 휴리스틱 — 뷰포트보다 큰 고정 폭, `w-screen` |
| **이미지 실제 로드** | 브라우저 실측 — `img.complete && naturalWidth > 0`, 화면 크기 0 여부 |
| **가로 넘침(실측)** | 브라우저 실측 — `documentElement.scrollWidth > innerWidth` |
| **모바일 헤더 가시성** | 브라우저 실측 — computed display/visibility/opacity, 뷰포트 안 좌표, 요소 간 겹침 |
| **모바일 그리드** | 브라우저 실측 — `gridTemplateColumns` 실제 열 수(4열 금지), 카드 폭·글자 크기 |
| **가로 스크롤 스트립** | 브라우저 실측 — 스크롤바 두께 0 + 스크롤 가능 유지 |
| 브라우저 실측 수행 여부 | 실측 결과가 없으면 **FAIL** — 실측 없이 PASS 로 넘기지 않는다 |
| 필수 요소 존재 | 헤더 로고·검색·장바구니(+모바일 메뉴), 상품 카드 8개 이상, 가격, 구매 CTA, 갤러리 |
| 로케일별 핵심 문구 | 각 locale 의 히어로·브랜드·섹션 문구가 그 로케일 값으로 렌더됐는지 |

## 한계 — 서버 E2E 의 대체물이 아니다

재현하지 **않는** 것:

- `actions` 실행, 데이터소스 fetch, 클라이언트 effect, 상태 변화 (데이터는 픽스처)
- 라우팅·인증·장바구니·주문·결제 흐름
- SEO 봇 렌더러(`ComponentHtmlMapper`)의 `<a href>` 변환
- 다중통화 전환 실동작 (픽스처는 KRW 기준)

레이아웃·가시성·이미지 로드는 헤드리스 브라우저로 **실측**한다(`browser-probe.cjs`).
브라우저를 못 찾으면 검사기가 FAIL 로 보고한다.

이 항목들은 서버에 설치한 뒤 실제 브라우저로 확인해야 한다.
