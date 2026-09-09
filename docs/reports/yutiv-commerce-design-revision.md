# yutiv-commerce 디자인 개선 보고서 (Still Form 시각 참고 반영)

- 작성일: 2026-09-09
- 대상: `templates/_bundled/yutiv-commerce/` (미커밋 유지)
- 선행 판단: **Still Form 전체 채택 NO-GO 확정** (조사 보고서 별도)
- commit·push·배포·활성 전환 **미수행**

> 이 문서는 `yutiv-commerce-template-report.md` 의 **§7(메인 섹션 구성)·§11(반응형)·§15(파일 수)**
> 를 대체합니다. 그 보고서 §6 의 "현재 운영값 `no_route=true`" 서술은 로컬에 근거가 없어
> 서버 확인이 필요합니다(§9 참조).

---

## 1. 유지한 것 (요구사항 1·2·6)

이번 작업은 **표현 계층만** 바꿨습니다. 아래는 한 줄도 건드리지 않았습니다.

| 유지 대상 | 확인 방법 |
| --- | --- |
| 라우트 40개 (동적 상점 경로 표현식 포함) | 검사기의 `routes.json 계약` — sirsoft-basic 과 path 집합 완전 일치 |
| 데이터소스·API 엔드포인트 | 검사기의 `data source endpoint` — 이커머스 모듈 실제 라우트와 대조 |
| 주문·결제·장바구니·재고 로직 | 해당 레이아웃/핸들러 무수정 |
| 다중통화 | 카드 가격이 `_global.preferredCurrency` → `multi_currency_*` 우선 조회 (기존 `ProductCard` 규칙과 동일) |
| 다크모드 | 모든 신규 클래스에 `dark:` 대응 |
| ko/en/ja/zh-CN | 검사기의 `다국어 키 정합성` + `로케일 오염` |
| `sirsoft-basic` / 코어 / `sirsoft-ecommerce` | `git status --short` 에 수정 항목 0건 |
| 상품 상세·페이지 본문 HTML 렌더 | `extension_point: html_content` → `HtmlContent`(DOMPurify) 경로 그대로 |

`dist/` 와 `src/` 는 여전히 원본과 **바이트 동일**합니다(`diff -rq` 차이 0) → **프론트엔드 빌드 불필요**.

---

## 2. 디자인 방향

관리자 대시보드처럼 보이던 요소를 걷어내고 상품 이미지와 브랜드 콘텐츠를 화면의 중심에 두었습니다.

| 항목 | 이전 | 이후 |
| --- | --- | --- |
| 상품 카드 | 흰 박스 + 라운드 + 테두리 + 그림자 + 파란 카테고리 칩 (`ProductCard` 컴포지트) | 박스 없음. **3:4 세로 이미지** + 그 아래 최소 타이포 (레이아웃 JSON 조립) |
| 제목 서체 | `font-bold` | `font-light` + `tracking-tight`, 데스크톱 `lg:text-5xl` |
| 섹션 헤더 | 굵은 제목만 | 작은 대문자 자간 eyebrow + 큰 경량 제목 + 우측 밑줄 링크 |
| 카테고리 | 아이콘 원형 배지 + 테두리 카드 그리드 | 텍스트 스트립 + 상품 수, 호버 밑줄 (모바일 가로 스크롤) |
| CTA | 라운드 필 버튼 | 사각 블록 + 대문자 자간, 보조는 밑줄 텍스트 링크 |
| 브랜드 스토리 | 본문 폭 안의 아이콘 3열 | **전체 폭 다크 밴드** + 헤어라인 3열 텍스트 |
| 상품 그리드 간격 | `gap-4` 균일 | `gap-x-4 gap-y-10` — 가로 좁게, 세로 넓게 |
| 목록 카테고리 필터 | 라운드 필 칩 | 밑줄 텍스트 링크 (활성 = 진한 밑줄) |
| 정책/안내 페이지 | 흰 카드 + 그림자 | 카드 제거, 지면형 본문 + 경량 대형 제목 |
| 로딩 | 없음 (빈 상태가 먼저 번쩍임) | **스켈레톤** — 실제 카드와 같은 3:4 자리 |

---

## 3. Still Form 에서 참고 / 차용한 것 (요구사항 10·11)

**실제로 복사한 코드·자산: 없음.** 전문은 템플릿 안 `NOTICE-DESIGN.md` 에 영구 기록했습니다.

### 3-1. 차용하지 않은 것

파일·코드 조각·CSS·SVG·이미지·문구를 한 줄도 옮기지 않았습니다. Still Form 은 Tailwind 를
쓰지 않고 자체 CSS 토큰(`--scm-*`) + 인라인 스타일로 되어 있어 마크업을 옮겨도 이 템플릿에서
동작하지 않습니다. 로고(`still-form-logo-*.png`), 데모 상품 사진, 브랜드명 "Still Form",
데모 사업자 정보, 약관 시안 문구는 **일절 사용하지 않았습니다.**

### 3-2. 시각적으로만 참고한 방향 → 적용 파일

| 참고한 방향 | 적용 파일 | 이 템플릿의 구현 |
| --- | --- | --- |
| 큰 경량 서체 + 대문자 eyebrow + 사각 CTA 히어로 | `layouts/partials/home/_hero.json` | Tailwind `font-light`/`tracking-tight`/`tracking-widest`, 배경은 이미지 없이 그라디언트 |
| 테두리·그림자 없는 세로 상품 카드 | `layouts/partials/shop/_product_card.json` | 3:4 이미지 + 최소 타이포. 데이터는 `ProductListResource` 실필드 |
| 상품 그리드의 넓은 세로 리듬 | 카드를 쓰는 모든 그리드 | `gap-x-4 gap-y-10` |
| 카테고리를 박스가 아닌 텍스트 스트립으로 | `layouts/partials/home/_category_nav.json` | 아이콘 배지 제거, 모바일 가로 스크롤은 엔진 `responsive` 블록 |
| 로딩 스켈레톤으로 빈 상태 깜빡임 제거 | `layouts/partials/shop/_product_skeleton.json` | `animate-pulse` + 데이터소스 `loading` 플래그 게이트 |
| 어두운 전체 폭 브랜드 스토리 밴드 | `layouts/partials/home/_brand_story.json` | 아이콘 없는 3열 + 상단 헤어라인 |
| 빈 상태를 여백·타이포로 | `_no_products.json`, `shop/list/_product_grid.json` | 중앙 경량 제목 + 보조 문구 |
| 정책 페이지를 카드가 아닌 지면으로 | `layouts/page/show.json` | 흰 카드·그림자 제거 |
| 섹션 헤더(eyebrow + 제목 + 전체보기) | `_new_products.json`, `_best_products.json`, `_all_products.json` | 동일 구성을 이 템플릿 컴포넌트로 재작성 |

각 파일의 `meta.design_reference` 에도 같은 내용을 파일 단위로 적어 두었습니다.

### 3-3. 명시적으로 배제한 것 (요구사항 "차용 금지" 전 항목)

`superbify-commerce-compat` 플러그인 / `OrderCalculationService` 교체 / 재고 예약 테이블·마이그레이션 /
cart·order·stock 훅 / 데모 시드 / `/shop` 하드코딩 / 라우트 제거 / sanitize 없는
`dangerouslySetInnerHTML` / KRW 하드코딩 / 한국어 하드코딩 / 외부 CDN — **전부 미도입.**

`?file=` 자산 URL 기법은 이번 디자인이 **템플릿 자산 이미지를 하나도 쓰지 않아**(히어로·브랜드 밴드가
CSS 그라디언트) 적용할 자리가 없었습니다. 배경 이미지를 넣게 되면 그때
`/api/templates/assets/yutiv-commerce?file=...` 형태를 쓰도록 `NOTICE-DESIGN.md` 에 근거를 남겼습니다.

---

## 4. 정책 페이지 · 브랜드 스토리 · 스켈레톤 (요구사항 8)

라우트를 늘리지 않는다는 요구사항 1을 지키기 위해 **기존 라우트 위에** 올렸습니다.

| 요구 | 구현 | 경로 |
| --- | --- | --- |
| 정책 페이지 | `layouts/page/show.json` 을 지면형으로 재설계. 본문은 sirsoft-page CMS 콘텐츠, 렌더는 `HtmlContent`(DOMPurify) | `/page/terms`, `/page/privacy`, `/page/refund` — **푸터에 이미 링크되어 있음** |
| 브랜드 스토리 | ① 홈의 전체 폭 다크 밴드 ② 같은 지면형 페이지 레이아웃 | 홈 + `/page/about` |
| 로딩 스켈레톤 | 신규 파셜. 홈 3개 섹션 + 상품 목록 그리드에 적용 | — |

정책 문구를 템플릿에 하드코딩하지 않았습니다 — 운영자가 관리자에서 작성한 CMS 콘텐츠를 그대로 렌더합니다.
(Still Form 은 `config/business-info.json` 에 약관 본문을 넣어 두고 수정 시 **재빌드**가 필요합니다.)

---

## 5. 상품 0개 / 상품 존재 상태 (요구사항 7)

| 상태 | 게이트 | 화면 |
| --- | --- | --- |
| 로딩 중 | `{{X?.loading}}` | 섹션 헤더 + 스켈레톤 8장 (3:4 자리 유지 → 레이아웃 이동 없음) |
| 상품 있음 | `!loading && length > 0` | 에디토리얼 그리드 |
| 상품 0건 | `!homeProductsFailed && !loading && 세 소스 모두 0` | 히어로 + 브랜드 밴드는 유지, 중앙에 중립 안내 |
| API 오류 | `homeProductsFailed` | "상품 없음"으로 단정하지 않음 — 안내를 띄우지 않는다 |

프리뷰로 세 상태 모두 확인했습니다(§7).

---

## 6. 다국어 (요구사항 6)

`home` 네임스페이스에 5개 키를 추가했습니다 — `sections.categories_eyebrow`,
`sections.new_eyebrow`, `sections.best_eyebrow`, `sections.all_eyebrow`, `brand.eyebrow`.
**4개 로케일 전부**에 실제 번역으로 채웠습니다(기계적 복제 없음).

| 로케일 | home 키 | 검사 |
| --- | ---: | --- |
| ko | 25 | 키 집합 일치 |
| en | 25 | 키 집합 일치 |
| ja | 25 | 한글 오염 0 |
| zh-CN | 25 | 한글·가나 오염 0 |

화면 문구는 전부 번역키이며, 상품명·카테고리명은 이커머스 `name_localized` 계약을 그대로 씁니다.

---

## 7. 프리뷰 (요구사항 12)

> **이 절은 「개정 2」 로 대체되었습니다.** 프리뷰 툴은 저장소 안
> `tests/Preview/yutiv-commerce/` 로 옮겼고, 산출물 경로·이미지 처리·미해석 토큰 상태가
> 모두 바뀌었습니다. 현행 내용은 문서 끝의 「개정 2」 를 보세요.

Playwright 를 쓸 수 없어(`node_modules` 없음, 앱 부팅 불가) **저장소의 실제
`dist/js/components.iife.js` 를 React 18 UMD 로 SSR** 하고 실제 `dist/css/components.css` 를
인라인한 정적 HTML 을 만들었습니다. 레이아웃 엔진의 부분집합(extends/slots, `partial` 인라인,
`$t:`, `{{expr}}`, `if`, `iteration`, `responsive`, `extension_point` 기본 트리)을 구현해
`layouts/*.json` 원본을 그대로 해석합니다.

```
tests/Preview/yutiv-commerce/output/clean/   ← 검토용 프리뷰의 단일 기준 경로
```

| 파일 | 뷰포트 | 결과 |
| --- | --- | --- |
| `home-desktop-1440.html` | 1440 | 카드 24 · 이미지 24 · 가격 36 · 대시보드 카드 잔재 0 |
| `home-tablet-768.html` | 768 | 동일 (그리드 `sm:grid-cols-3`) |
| `home-mobile-390.html` | 390 | 동일 + 카테고리 가로 스크롤 (엔진 responsive) |
| `home-loading-desktop-1440.html` | 1440 | **스켈레톤 24 · 이미지 0 · 가격 0** |
| `home-empty-desktop-1440.html` / `-mobile-390` | 1440 / 390 | **카드 0 · 0건 안내 표시 · 히어로+브랜드 밴드 유지** |
| `home-zhCN-desktop-1440.html` | 1440 | 全球潮流／分类／新品／热销／全部商品 |
| `home-ja-desktop-1440.html` | 1440 | 世界のトレンドに／カテゴリー／新着商品 |
| `home-en-desktop-1440.html` | 1440 | The world's trends／Categories／New arrivals |
| `shop-list-{desktop-1440,tablet-768,mobile-390}.html` | 3종 | 카드 24 · 밑줄 카테고리 필터 · 사각 페이저 |
| `product-{desktop-1440,mobile-390}.html` | 2종 | H1 경량 제목 + 공통정보(HtmlContent) |

전 화면 공통: **미노출 컴포넌트 0 · 미해석 `$t:` 0 · 대시보드형 카드 잔재 0 · 외부 호스트 0.**
제목 위계 H1 → H2 → H3 정상.

**한계** — 데이터는 픽스처이고 클라이언트 effect·네트워크·상호작용은 실행되지 않습니다.
상품 이미지 URL 은 실재하지 않아 회색 판으로 보입니다(레이아웃·비율 확인용).
푸터의 `{{siteName}}` 같은 번역 파라미터는 엔진의 파이프 치환 기능이라 프리뷰에 그대로 남습니다.
스크린샷 대체물이지 E2E 검증 대체물이 아닙니다.

---

## 8. 검사 결과

### 검사기 확장

`tests/Translations/yutiv-commerce-template-check.php` 의 Tailwind 클래스 검사를 두 군데 고쳤습니다.

1. **검사 범위 확대** — 홈 7개 파셜 → 디자인을 직접 작성한 **17개 레이아웃** (상품 카드·스켈레톤·
   상품 목록 그리드·목록 캐러셀 3종·상품 상세 헤더·`shop/index.json`·`page/show.json` 포함).
   클래스 219종을 전수 대조합니다.
2. **표현식 안의 클래스도 검사** — `{{cond ? 'a b' : 'c d'}}` 형태로 삼항 안에만 있는 클래스를
   종전에는 통째로 건너뛰었습니다. 이제 작은따옴표 리터럴 안쪽을 훑되, 경로나 평범한 단어가
   섞이므로 하이픈·콜론을 가진 토큰만 유틸리티로 간주합니다.
3. **이스케이프 오탐 수정** — 종전에는 `: / .` 만 손으로 이스케이프해 `text-[10px]`,
   `sm:auto-cols-[calc(33.333%_-_11px)]` 같은 임의값 클래스를 "없음"으로 잘못 보고했습니다.
   CSS 셀렉터에서 클래스명을 한 번 수집해 집합 조회로 바꿨습니다.

### 결과

```
검사 파일 497개 (JSON 274) · 레이아웃 165개 · 라우트 40개
디자인 레이아웃 17개 · CSS 클래스 219종

JSON 구문 / 인코딩 / template.json 계약 / LICENSE / 원본 무변경 /
routes.json 계약 / partial 참조 / 컴포넌트 선언 / data source endpoint /
커뮤니티 잔재 / 하드코딩 문구 / Tailwind 클래스 실재 / 다국어 키 정합성 / 로케일 오염
  → 전부 OK (0)

RESULT: PASS — 위반 0건
```

작업 중 이 검사가 실제로 잡아낸 결함 1건: `dark:hover:border-white` 가 빌드 CSS 에 없어
다크모드 호버 밑줄이 조용히 빠질 뻔했습니다 → `dark:hover:border-gray-200` 으로 교체.

| 회귀 검사 | 결과 |
| --- | --- |
| `zh-CN-{core,board,ecommerce,page,plugins,templates}-parity-check` | 6종 전부 PASS |
| `git diff --check` | 0건 |

---

## 9. 서버에서 확인이 필요한 항목

```bash
# 상점 경로 설정 — 이전 보고서 §6 의 no_route 서술을 확정한다.
# (이 템플릿은 동적 표현식이라 어느 값이든 동작하지만, 문서의 사실 확인이 필요하다)
php artisan tinker --execute="dump(g7_module_settings('sirsoft-ecommerce','basic_info.no_route'), g7_module_settings('sirsoft-ecommerce','basic_info.route_path'));"
```

---

## 10. 변경 파일

| 구분 | 수 |
| --- | ---: |
| 신규 | 3 — `NOTICE-DESIGN.md`, `partials/shop/_product_card.json`, `partials/shop/_product_skeleton.json` |
| 교체·수정 (템플릿 안) | 19 — 홈 7 + `home.json` + 상품 목록 4 + 상품 상세 헤더 1 + `shop/index.json` + `page/show.json` + 번역 4 |
| 검사기 | 1 — `tests/Translations/yutiv-commerce-template-check.php` |
| **기존 추적 파일 수정** | **0** |

```
$ git status --short
?? docs/reports/yutiv-commerce-design-revision.md
?? docs/reports/yutiv-commerce-template-report.md
?? templates/_bundled/yutiv-commerce/
?? tests/Translations/yutiv-commerce-template-check.php
```

`M` / `D` / `R` 항목 없음 — `sirsoft-basic`, 코어, `sirsoft-ecommerce`, 루트 lock 파일 전부 무변경.
commit·push·배포·활성 전환 미수행.

---

# 개정 2 — 프리뷰 결함 수정 (2026-09-09)

배포 미승인 후 지적된 결함 전부를 수정했습니다. **원인은 대부분 템플릿이 아니라 프리뷰 렌더러**
쪽이었고, 그래서 문자열을 감추는 대신 **런타임 계약을 구현**하는 방향으로 고쳤습니다.

## 1. 미해석 번역키·표현식 0건 (요구 1·2)

### 근본 원인

템플릿의 composite 컴포넌트는 문구를 레이아웃 JSON 이 아니라 **런타임 전역**에서 가져옵니다.

```ts
// templates/_bundled/yutiv-commerce/src/components/composite/Header.tsx
const t = (key, params) => (window as any).G7Core?.t?.(key, params) ?? key;
```

프리뷰 샌드박스에 `G7Core` 가 없어 이 컴포넌트들이 **번역키를 그대로 출력**했습니다.
`Site`, `common.search_placeholder`, `nav.home`, `auth.login`, `auth.register_link`,
`shop.guest_order_form.nav_link` 가 전부 이 경로였습니다. Header·Footer·SearchBar·
Pagination·ProductImageViewer 등이 해당합니다.

### 고친 것

| 결함 | 위치 | 수정 |
|---|---|---|
| `G7Core` 부재 → 컴포넌트가 키 출력 | 프리뷰 런타임 | `tests/Preview/yutiv-commerce/runtime.cjs` 에 `G7Core.t / state / dispatch / api / componentEvent / createLogger` 를 실제 계약대로 구현. `t()` 는 템플릿 `lang/{locale}.json` 을 조회하고 `{{param}}` 을 치환하며, **미등록 키는 기록해 검사기가 잡는다** |
| `Site` | 헤더 로고 | `_user_base.json` 의 `{{_global.settings?.general?.site_name ?? 'Site'}}` 폴백. 픽스처에 `_global.settings.general.site_name` 을 채워 실제 렌더 경로를 재현 |
| `{{siteName}}` (푸터 저작권) | `$t:footer.copyright\|siteName={{...}}` | 파이프 파라미터 값에 공백이 들어가는데(`?? '그누보드7'`) 파서가 `[^\s]*` 로 끊고 있었음 → 첫 `\|` 이후 전체를 파라미터로 파싱하도록 수정 |
| `shop.product.total_amount ({{...}}...)` | `_purchase_card.json:422` | 엔진은 문자열 **중간**의 `$t:` 도 치환한다. 문자열 시작만 보고 통째로 키 취급하던 것을 인라인 치환으로 수정 |
| `$locale` / `$locales` 미정의 | 언어 선택기 | 엔진이 주입하는 특수 변수를 스코프에 추가 |
| `notifications` / `boards` / `qna` / `productDownloadableCoupons` 미정의 | `_user_base` · 상세 | 공통 데이터소스 픽스처 추가 |
| **`shop.image` 번역키 자체가 없음** | `ProductImageViewer.tsx:135` `t('shop.image')` | **템플릿 결함.** 갤러리 썸네일의 `aria-label` 이 `shop.image 1` 로 나오고 있었다. 원본 `sirsoft-basic` 에도 없는 인수 결함이며, yutiv-commerce 의 4개 로케일에 키를 추가해 수정 |

**결과: `$t:` 잔존 0 · `{{...}}` 잔존 0 · 런타임 미등록 키 0 · 표현식 평가 실패 0.**

## 2. 프리뷰 렌더러 정확도 (요구 2)

문자열 치환은 쓰지 않았습니다. 엔진 부분집합을 실제 규칙대로 구현했습니다.

| 재현 | 구현 |
|---|---|
| 번역 네임스페이스 | `lang/{locale}.json` 의 `$partial` 참조를 펼쳐 21개 네임스페이스 로드. 레이아웃 `$t:` 와 런타임 `G7Core.t()` 가 **같은 사전**을 본다 |
| 표현식 | `{{expr}}` 전체/보간, 삼항 안의 `$t:`, 문자열 중간 `$t:`, `$t:key\|p=v` 파이프 파라미터 |
| partial | 등록 시점 인라인 (`./` 상대경로 포함) |
| iteration | `item_var` / `index_var` 스코프 주입 |
| 변수 스코프 | `_global` / `_local` / `_computed` / `_isolated` / `query` / `route` / `$locale` / `$locales` |
| responsive | `mobile` / `tablet` / `desktop` / `portable` + `0-639` 형식 범위 |
| extension_point | 확장 미등록 상태 = `default` 트리 (상세 본문의 `HtmlContent`/DOMPurify 경로가 여기서 살아난다) |
| 미디어쿼리 | 캡처를 **지정 폭 iframe** 안에서 렌더 — iframe 폭이 곧 뷰포트라 `sm:`/`lg:` 가 실제로 동작한다 |

## 3. 모바일 390px (요구 3)

| 요구 | 결과 |
|---|---|
| 로고·검색·장바구니·로그인/회원가입·모바일 메뉴 | 전부 존재 — 검사기의 `필수 요소 존재` 항목이 매 렌더마다 확인한다 (로고 `YUTIV`, 검색 input, `fa-shopping-cart`, `fa-bars`) |
| 히어로 제목 한 글자 단위 줄바꿈 금지 | 제목·본문에 `break-keep`(word-break: keep-all) 적용. 한글이 어절 중간에서 끊기지 않는다 |
| 자연스러운 문장 단위 줄바꿈 | 번역 문자열의 명시적 `\n` + `whitespace-pre-line` 으로 로케일마다 줄 나눔 위치를 번역 파일에서 정한다 |
| 좌우 여백·CTA 크기 | 히어로 모바일 전용 `px-5 py-16` (엔진 responsive). CTA 는 `px-8 py-4` 로 터치 영역 44px 이상 유지 |
| body 가로 넘침 0 | 검사기의 정적 휴리스틱 통과 — 다만 **실제 브라우저 측정이 아니다**(§7) |

## 4. 상품 상세 (요구 4)

| 요구 | 결과 |
|---|---|
| 정상 이미지 placeholder | 픽스처가 **로컬 생성 SVG data URI** 를 쓴다. 외부 요청 0, 깨진 이미지 0 |
| 이미지 갤러리 | 기존 `ProductImageViewer` 컴포지트에 4장 공급 — 대표 + 상세/텍스처/패키지 |
| 위계 정리 | 정보 열 항목 간격 `space-y-4` → `space-y-6`, 페이지 여백 `py-4` → `px-4 py-12 sm:px-6 lg:px-8` |
| 데스크톱 2열 | 이 레이아웃은 이미 엔진 `responsive.desktop` 으로 2열을 만들고 있었다. 기본 클래스에 `lg:grid-cols-2` 를 얹으면 데스크톱에서 덮여 무시되므로 **기존 방식 안에서** 간격을 넓혔다(`gap-8 mb-8` → `gap-16 mb-16`) |
| 갤러리 고정 | 데스크톱에서만 `sticky top-8` — 정보 열을 스크롤해도 이미지가 따라온다 |
| 구매/장바구니 CTA | `장바구니 담기` + `바로 구매` 존재를 검사기가 확인 |
| 모바일 상세 별도 검증 | `product-mobile-390` 캡처 + 검사 통과 (1열 `flex flex-col gap-10 mb-12`) |

## 5. 홈 상품 중심 (요구 5)

| 항목 | 결과 |
|---|---|
| 상품 카드 수 | 홈 **24장** (신상품 8 + 베스트 8 + 전체 8), 상품 목록 **28장** |
| 섹션 | 신상품·베스트·전체 상품 전부 캡처에 포함 |
| 카드 표시 | 이미지 24 · 상품명 24 · 브랜드 24 · 정상가(취소선) 12 · 할인율 12 |
| 빈 상품 상태 | `home-empty-{desktop-1440, mobile-390}` 별도 유지 (카드 0, 안내 문구 노출, 히어로·브랜드 밴드 유지) |
| 로딩 상태 | `home-loading-desktop-1440` — 스켈레톤 24, 이미지 0 |

**추가 템플릿 수정**: `ProductListResource.thumbnail_url` 은 nullable 이다
(`Product::getThumbnailUrl(): ?string`). 값이 없으면 `<img src="">` 가 되어 브라우저가
깨진 이미지 아이콘을 그리므로, **값이 있을 때만 `<img>` 를 렌더**하도록 카드에 게이트를 넣었다.

## 6. 클린 / 진단 캡처 분리 (요구 6·7)

```
tests/Preview/yutiv-commerce/output/
  pages/   크롬이 전혀 없는 순수 페이지
  clean/   지정 뷰포트 폭 iframe 한 장 — 검은 막대·점선 없음 (검토·캡처용)
  diag/    같은 iframe + 진단 막대·점선 경계 (개발자용)
```

제출 캡처 (clean):

| 요구 | 파일 |
|---|---|
| 홈 데스크톱 1440 | `clean/home-desktop-1440.html` |
| 홈 모바일 390 | `clean/home-mobile-390.html` |
| 상품 목록 데스크톱/모바일 | `clean/shop-list-desktop-1440.html`, `clean/shop-list-mobile-390.html` |
| 상품 상세 데스크톱/모바일 | `clean/product-desktop-1440.html`, `clean/product-mobile-390.html` |
| 상품 0건 | `clean/home-empty-desktop-1440.html`, `clean/home-empty-mobile-390.html` |
| zh-CN 홈 | `clean/home-zhCN-desktop-1440.html`, `clean/home-zhCN-mobile-390.html` |

부가: `home-tablet-768`, `home-loading-desktop-1440`, `home-ja-desktop-1440`, `home-en-desktop-1440`.
총 14장. 목록은 `output/index.html`.

## 7. 자동 검사 추가 (요구 8)

신규 `tests/Preview/yutiv-commerce/check.cjs` — 렌더 결과를 검사한다.

```
=== yutiv-commerce 프리뷰 렌더 검사 ===
페이지 14개 · <img> 250개 · 번역 네임스페이스 21종 · 로케일 ko, en, ja, zh-CN

렌더 오류                          OK (0)
미해석 번역키                      OK (0)
미해석 표현식                      OK (0)
미노출 컴포넌트                    OK (0)
깨진/외부 이미지                   OK (0)
모바일 가로 넘침(정적 휴리스틱)    OK (0)
필수 요소 존재                     OK (0)
로케일별 핵심 문구                 OK (0)

RESULT: PASS — 위반 0건
```

기존 정적 검사기도 상품 상세 레이아웃을 검사 범위에 추가했습니다.

```
디자인 레이아웃 18개 · CSS 클래스 248종
RESULT: PASS — 위반 0건
```

### 서버 E2E 가 **아닌** 것 (요구 10)

이 검사들은 정적 렌더 결과 검사입니다. 다음은 포함되지 않으며 서버 설치 후 실제 브라우저로
확인해야 합니다.

- `actions` 실행, 데이터소스 fetch, 클라이언트 effect, 상태 변화 — **데이터는 픽스처**
- **실제 브라우저 레이아웃 측정** — 모바일 가로 넘침 검사는 고정 폭/`w-screen` 을 보는 **정적 휴리스틱**이며, 실제 스크롤 폭을 재지 않는다
- 라우팅·인증·장바구니·주문·결제 흐름
- SEO 봇 렌더러(`ComponentHtmlMapper`)의 `<a href>` 변환
- 다중통화 전환 실동작 (픽스처는 KRW 기준. 카드가 `_global.preferredCurrency` 로 `multi_currency_*` 를 조회하는 경로만 확인)

## 8. 변경 파일 (요구 9·10)

기존 `sirsoft-basic` · 코어 · `sirsoft-ecommerce` 는 **읽기만** 했습니다.

| 구분 | 파일 |
|---|---|
| 템플릿 수정 | `layouts/partials/shop/_product_card.json` (thumbnail null 게이트), `layouts/partials/home/{_hero,_brand_story,_no_products,_category_nav,_new_products,_best_products,_all_products}.json` (`break-keep`, 모바일 여백), `layouts/shop/show.json` (상세 여백·2열 간격·갤러리 sticky), `lang/partial/{ko,en,ja,zh-CN}/shop.json` (`shop.image` 키 추가) |
| 전용 검사기 | `tests/Translations/yutiv-commerce-template-check.php` (상세 레이아웃 검사 범위 추가) |
| 프리뷰 툴 (신규) | `tests/Preview/yutiv-commerce/{runtime,engine,fixtures,build,check,audit}.cjs`, `README.md`, `.gitignore` |

`output/` 과 `.umd/`(벤더링한 React UMD)는 `.gitignore` 로 추적에서 제외했습니다.

```
$ git status --short
?? docs/reports/yutiv-commerce-design-revision.md
?? docs/reports/yutiv-commerce-template-report.md
?? templates/_bundled/yutiv-commerce/
?? tests/Preview/
?? tests/Translations/yutiv-commerce-template-check.php

$ git diff --check
(0건)
```

`M` / `D` / `R` 항목 없음. `dist/` 와 `src/` 는 여전히 원본과 바이트 동일 → **프론트엔드 빌드 불필요**.
zh-CN 파리티 검사 6종 전부 PASS.

commit·push·배포·활성 전환 미수행 — 재승인 대기.

---

# 개정 3 — 프리뷰 기준 경로 통일 · 모바일 헤더 · 브라우저 실측 (2026-09-09)

## 0. 검토용 프리뷰의 단일 기준 경로

```
tests/Preview/yutiv-commerce/output/clean/
```

이전 임시 프리뷰(스크래치패드 경로)는 **파일까지 삭제**했고, 보고서 두 곳의 경로 참조도
위 경로로 바꿨습니다. 저장소·보고서·안내 어디에도 옛 경로는 남아 있지 않습니다.

`clean/` 파일은 **지정 뷰포트 폭의 iframe 한 장짜리 호스트**라 수백 바이트입니다.
알맹이는 `pages/` 에 있고 iframe 이 그것을 불러옵니다. iframe 을 쓰는 이유는 CSS
미디어쿼리가 **뷰포트** 폭을 보기 때문입니다 — 1440px 창 안의 390px `div` 에 넣으면
모바일 스타일이 아예 적용되지 않습니다. **이전 캡처에서 상품이 4열로 좁게 나오고 모바일
헤더가 안 보였던 것이 정확히 이 문제였습니다.**

## 1. 지적 결함의 실제 원인

헤드리스 Chrome 으로 재 보니 두 가지가 겹쳐 있었습니다.

| 증상 | 원인 | 판정 |
|---|---|---|
| 모바일에서 장바구니·햄버거가 안 보임 | 프리뷰에 **Font Awesome 스타일시트가 없어** 아이콘 전용 버튼이 0×0 으로 접힘. FA 는 코어 호스트 페이지가 제공하며 이 저장소에 없다 | **프리뷰 결함** — FA 를 `.vendor/` 에 받아 페이지에 링크 |
| 모바일에서 검색이 안 보임 | 검색 입력이 **햄버거 드로어 안에만** 있었다. 드로어는 닫힌 상태(화면 밖 x=391)라 390px 화면에서 검색에 도달할 방법이 없었다 | **템플릿 결함** — 첫 줄에 검색 아이콘 추가 |
| 상품이 4열로 좁게 렌더 | 옛 프리뷰가 `div` 폭만 390px 로 잡아 미디어쿼리가 데스크톱으로 동작 | **프리뷰 결함** — 현재 iframe 방식에서는 실측 2열 |

## 2. 템플릿 수정

**모바일 헤더 첫 줄에 검색 진입점 추가** (`layouts/_user_base.json`)

```
[YUTIV 로고]        [테마] [알림] [검색] [장바구니] [내정보] [☰]
```

- 새 라우트를 만들지 않고 기존 `/search` 화면으로 보냅니다.
- 드로어 안의 검색 입력(`/search?q=` 계약)은 그대로 둡니다 — 기존 기능·링크 유지.
- 로그인·회원가입·주문조회는 요구하신 대로 드로어 안에 그대로 둡니다.
- 로케일과 무관한 검사를 위해 `data-testid="mobile-search"` 를 붙였습니다.

**모바일 카테고리 스트립** (`layouts/partials/home/_category_nav.json`)

- `scrollbar-hide` — 브라우저 기본 스크롤바를 감추되 스크롤은 유지
  (빌드된 CSS 에 이미 있는 유틸리티: `-ms-overflow-style:none; scrollbar-width:none;
  ::-webkit-scrollbar{display:none}`)
- `overscroll-x-contain` — 가로 스와이프가 페이지로 새지 않게
- 오른쪽 끝 **페이드 오버레이** + `pr-10` 여백 — "뒤에 더 있다" 를 알린다.
  `pointer-events-none` 이라 터치를 가로채지 않고, 모바일에서만 노출합니다.

## 3. 브라우저 실측 (요구 4·7)

**헤드리스 Chrome 을 찾아 실제로 렌더해 측정합니다.** 신규 `browser-probe.cjs`.

`--window-size` 가 `--dump-dom` 모드에서 무시되므로, 지정 폭 iframe 을 가진 호스트를 만들고
iframe 안의 페이지가 측정값을 `postMessage` 로 부모에 보내 `--dump-dom` 으로 회수합니다.

측정 결과 (`output/browser-probe.json`):

| 페이지 | 뷰포트 | 이미지 로드 | 가로 넘침 | 그리드 열 |
|---|---:|---|---:|---|
| home-desktop-1440 | 1440 | 24/24 | 없음 | 4 / 4 / 4 |
| home-tablet-768 | 768 | 24/24 | 없음 | 2 / 3 / 3 / 3 |
| home-mobile-390 | 390 | 24/24 | 없음 | 2 / 2 / 2 / 2 |
| shop-list-desktop-1440 | 1440 | 28/28 | 없음 | 4 |
| shop-list-mobile-390 | 390 | 28/28 | 없음 | 2 / 2 |
| product-desktop-1440 | 1440 | 13/13 | 없음 | 2 |
| product-mobile-390 | 390 | 13/13 | 없음 | 2 / 1 |
| home-zhCN-mobile-390 | 390 | 24/24 | 없음 | 2 / 2 / 2 / 2 |

**이미지**: `img.complete && naturalWidth > 0 && naturalHeight > 0` 로 판정했습니다.
로컬 SVG data URI 가 실제로 디코드되어 그려집니다. 외부 요청 0건.

**모바일 헤더 실측** (`home-mobile-390`, 뷰포트 390px):

| 요소 | 보임 | 뷰포트 안 | 좌표 |
|---|---|---|---|
| 로고 YUTIV | ✅ | ✅ | x=16 w=52 |
| 검색 | ✅ | ✅ | x=221 w=32 |
| 장바구니 | ✅ | ✅ | x=265 |
| 내정보 | ✅ | ✅ | x=303 |
| 햄버거 | ✅ | ✅ | x=337 |

겹침 0건. 판정은 DOM 존재가 아니라 computed `display`/`visibility`/`opacity` 를 조상까지
거슬러 확인하고 `getBoundingClientRect()` 로 뷰포트 안인지 봅니다.

**모바일 그리드·카드**: 실제 2열, 카드 폭 148px, 상품명 14px / 행간 23px — 4열 렌더 0건.

**가로 스크롤 스트립**: 스크롤바 두께 **0px**, `scrollWidth 497 > clientWidth 311` 로
스크롤은 살아 있음.

## 4. 검사기 강화 (요구 4·8)

`check.cjs` 에 실측 기반 항목 6개를 추가했습니다. **실측 결과가 없거나 브라우저를 찾지
못하면 FAIL 입니다** — 실측 없이 PASS 로 넘기지 않습니다.

```
=== yutiv-commerce 프리뷰 렌더 검사 ===
페이지 14개 · <img> 250개 · 번역 네임스페이스 21종 · 로케일 ko, en, ja, zh-CN

렌더 오류                        OK (0)   브라우저 실측 수행 여부      OK (0)
이미지 실제 로드(naturalWidth>0) OK (0)   가로 넘침(브라우저 실측)     OK (0)
모바일 헤더 실제 가시성          OK (0)   모바일 그리드 열 수·가독성   OK (0)
가로 스크롤 스트립               OK (0)   미해석 번역키                OK (0)
미해석 표현식                    OK (0)   미노출 컴포넌트              OK (0)
깨진/외부 이미지                 OK (0)   모바일 가로 넘침(정적)       OK (0)
필수 요소 존재                   OK (0)   로케일별 핵심 문구           OK (0)

RESULT: PASS — 위반 0건
```

검사 중 실제로 잡아낸 것: zh-CN 모바일에서 검색 진입점 판정이 흔들렸습니다. 선택자가
`aria-label` 문구에 의존해 로케일이 바뀌면 닫힌 드로어의 입력을 집었습니다 →
`data-testid` 훅 + "뷰포트 안 후보 우선" 규칙으로 수정했습니다.

## 5. 브라우저 실측 여부 (요구 9)

**실측했습니다.** `C:/Program Files/Google/Chrome/Application/chrome.exe` (헤드리스).
`output/browser-probe.json` 에 원본 측정값이 남습니다.

**여전히 실측하지 않은 것** — 서버 E2E 로만 확인 가능:

- `actions` 실행, 데이터소스 fetch, 클라이언트 effect, 상태 변화 (데이터는 픽스처)
- 라우팅·인증·장바구니·주문·결제 흐름
- SEO 봇 렌더러(`ComponentHtmlMapper`)의 `<a href>` 변환
- 다중통화 전환 실동작 (픽스처는 KRW 기준)
- 실제 단말의 터치 스크롤 감각 (스크롤 가능 여부만 수치로 확인)

---

# 개정 4 — 상품 카드 DOM 구조 수정 (2026-09-09)

## 1. 근본 원인 — Button 컴포넌트의 display 가 클래스로 되돌려지지 않았다

카드 루트로 쓰던 `Button` 컴포넌트는 className 앞에 자기 클래스를 붙인다.

```ts
// src/components/basic/Button.tsx
const baseClasses = 'inline-flex items-center justify-center';
```

레이아웃에서 `block w-full` 을 줘도 소용이 없었다. 빌드된 CSS 에서

```
.block{...}        // index 37401
.inline-flex{...}  // index 37595   ← 뒤에 있어 display 를 이긴다
```

`.inline-flex` 가 `.block` 보다 **뒤에** 있어 카드 루트가 `display:inline-flex` +
`align-items:center` 인 **가로 flex 행**이 됐다. 그래서 이미지와 상품 정보가 나란히 서고,
좁은 폭에서는 텍스트가 이웃 카드로 밀려 겹쳤다. **운영 CSS 도 같은 파일이므로 프리뷰만의
문제가 아니라 템플릿 결함이다.**

## 2. 수정 — 요청하신 DOM 계약대로

```html
<div data-product-card data-product-id="…">   <!-- 반복 1회 = grid 의 direct child 1개 -->
  <button data-product-image>…</button>        <!-- 이미지 3:4 -->
  <div data-product-info>                      <!-- 이미지 바로 아래 -->
    <span data-product-brand>…</span>
    <button><h3 data-product-name>…</h3></button>
    <div data-product-price>판매가 / 정상가 / 할인율</div>
  </div>
</div>
```

- 카드 루트는 **반복 노드 자체**다. `display:flex; flex-direction:column; width:100%;
  min-width:0` 을 **인라인 스타일**로 준다 — 클래스로는 `.inline-flex` 를 이길 수 없기 때문이다.
- 파셜은 루트가 하나뿐이라 이미지+정보를 한 파셜에 담으면 카드 루트를 파셜 밖에서 만들 수
  없다. 그래서 `_product_card_image.json` / `_product_card_info.json` 둘로 나눴다
  (`_product_card.json` 은 삭제).
- **`<a>` 대신 `<button>` 을 쓴 이유**: 엔진에 `<a href>` 가로채기가 없어 실제 앵커는 SPA
  이동이 아니라 전체 페이지 새로고침이 된다. 템플릿 표준 관용구가 Button+navigate 이고,
  SEO 봇 렌더러(`ComponentHtmlMapper::applyNavigateLink`)가 이 조합을 `<a href>` 로 변환한다.
  구조 계약(단일 루트·세로 배치·형제 셀 금지)은 그대로 지킨다.
- 상품명도 같은 목적지로 가는 두 번째 진입점으로 만들었다(목록에서 이름 클릭 가능).

적용 범위 — **상품이 반복되는 모든 자리 7곳**:
홈 신상품 / 홈 베스트 / 홈 전체 상품 / 상품 목록 그리드 / 상품 목록 캐러셀 3종
(최근 본·인기·신상품, 상품 상세 하단의 인기 상품 포함).

## 3. 반응형 그리드 — 실측

| 뷰포트 | 열 수 | 카드 폭 | 가로 간격 |
|---|---:|---:|---:|
| 모바일 390px | **2** | 146px | 20px (`gap-x-5`) |
| 태블릿 768px | **3** | — | 20px |
| 데스크톱 1440px | **4** | 273px | 20px |

요구 최소치(모바일 12px, 데스크톱 20px)를 `gap-x-5`(20px) 하나로 만족시켰다 —
`lg:gap-x-6` 은 빌드된 CSS 에 없어 쓸 수 없다.

## 4. 실측 결과

| 페이지 | direct child | 카드 | 비카드 | 카드겹침 | 텍스트겹침 |
|---|---:|---:|---:|---:|---:|
| home-desktop-1440 (그리드 3개) | 8 / 8 / 8 | 8 / 8 / 8 | 0 | 0 | 0 |
| home-mobile-390 (그리드 3개) | 8 / 8 / 8 | 8 / 8 / 8 | 0 | 0 | 0 |
| shop-list-mobile-390 | 12 | 12 | 0 | 0 | 0 |

카드별: `display=flex / flex-direction=column`, 이미지 1개, 상품명 1개,
이미지와 상품명의 가장 가까운 카드 조상 동일, 정보가 이미지 **아래**(`infoBelowImage=true`),
왼쪽 정렬 일치, 카드 박스 이탈 0.

## 5. 검사기 검출력 — red → green 확인

`browser-probe.cjs` 가 재고, `check.cjs` 가 판정한다. 두 가지 결함을 실제로 주입해
red 가 되는지 확인한 뒤 원복했다.

| 주입한 결함 | 결과 |
|---|---|
| **A.** 카드 루트를 가로 flex 로 (원래 결함 재현) | **FAIL 168건** — "세로형이 아니다 (flex-direction=row)", "상품 정보가 이미지 아래가 아니라 옆에 있다", "상품 카드 폭 70px" |
| **B.** 이미지와 정보를 grid 의 형제 셀로 직접 출력 | 1차 시도에서 **PASS 로 새어나감** → 검출력 보강 후 **FAIL 42건** — "카드 밖에 있는 이미지 8개", "카드 16개인데 이미지는 24개 — 1:1 이 아니다" |
| 원복 후 | **PASS — 위반 0건** |

B 가 처음 새어나간 이유: 카드 래퍼가 통째로 빠지면 그 컨테이너에 `[data-product-card]` 가
하나도 없어 "카드의 부모" 역추적에서 아예 사라진다. 그래서 **부속 요소 쪽에서도**
(`[data-product-image]` / `[data-product-name]` / `[data-product-info]` 의 카드 조상 유무와
카드 대비 1:1 개수) 세도록 보강했다. 지적하신 "현재 검사가 이 결함을 PASS 시켰다" 가
정확히 이 구조였다.

### 추가된 단언 (전부 브라우저 실측)

- grid direct child 수 === 카드 수 === 상품 수, 비카드 direct child 0
- 각 direct child 가 `data-product-card`
- 카드당 이미지 1개 / 상품명 1개 / 정보·브랜드·가격 존재
- 이미지와 상품명의 `closest('[data-product-card]')` 동일
- 카드 루트가 `display:flex` + `flex-direction:column`
- 정보가 이미지 아래(`top >= image.bottom`) 이고 왼쪽 정렬 일치 — 옆에 서면 실패
- 이미지·정보·상품명이 카드 bounding box 안
- 카드 박스끼리 겹침 0, 서로 다른 카드의 텍스트끼리 겹침 0
- 모바일 2열 / 태블릿 3열 / 데스크톱 4열 (캐러셀은 열 수 계약 제외)
- 카드 밖 이미지·상품명·정보 0개, 카드 대비 1:1
- **마지막 카드까지 전부** 순회 (일부 샘플링 아님)

## 6. 상품 상세 — 방향 유지 + 구매 영역 캡처 추가

상세 화면은 재설계하지 않았다. 구매 카드에 앵커 `id="product_purchase"` 만 달고,
그 지점을 열어 프레임 높이를 900px 로 낮춘 캡처를 추가했다.

`clean/product-buy-mobile-390.html` — 수량 · 총 금액 · 바로 구매 · 장바구니 담기 포함.

## 7. 제출 캡처 (기준 경로 고정)

```
D:\work\yutiv\tests\Preview\yutiv-commerce\output\clean\
```

| 요청 | 파일 | clean | pages(알맹이) |
|---|---|---:|---:|
| 홈 데스크톱 (카드 24장) | `home-desktop-1440.html` | 558 B | 484,565 B |
| 홈 모바일 (카드 24장) | `home-mobile-390.html` | 551 B | 485,197 B |
| 상품 목록 데스크톱 | `shop-list-desktop-1440.html` | 583 B | 491,767 B |
| 상품 목록 모바일 | `shop-list-mobile-390.html` | 576 B | 492,014 B |
| 상품 상세 모바일 (구매 영역) | `product-buy-mobile-390.html` | 626 B | 448,658 B |

수정 시각 전부 2026-09-09 10:16:44. `clean/` 이 수백 바이트인 것은 지정 뷰포트 폭
iframe 한 장짜리 호스트이기 때문이고, 내용은 `pages/` 에 있다 — 여는 것은 `clean/` 이다.

## 8. 검사 결과

```
=== 정적 검사 ===
디자인 레이아웃 19개 · CSS 클래스 260종
RESULT: PASS — 위반 0건

=== 프리뷰 검사 (브라우저 실측 포함) ===
페이지 15개 · <img> 263개 · 번역 네임스페이스 21종 · 로케일 ko, en, ja, zh-CN
렌더 오류 / 브라우저 실측 수행 / 이미지 실제 로드 / 가로 넘침(실측) /
모바일 헤더 실제 가시성 / 모바일 그리드 열 수·가독성 / 가로 스크롤 스트립 /
상품 카드 DOM 계약(실측) / 미해석 번역키 / 미해석 표현식 / 미노출 컴포넌트 /
깨진·외부 이미지 / 모바일 가로 넘침(정적) / 필수 요소 존재 / 로케일별 핵심 문구
  → 전부 OK (0)
RESULT: PASS — 위반 0건

zh-CN 파리티 6종 PASS · git diff --check 0건
```

## 9. 변경 파일

| 구분 | 파일 |
|---|---|
| 신규 | `layouts/partials/shop/_product_card_image.json`, `_product_card_info.json` |
| 삭제 | `layouts/partials/shop/_product_card.json` (둘로 분리) |
| 수정 | 홈 상품 섹션 3종, 상품 목록 그리드, 상품 목록 캐러셀 3종 (카드 루트 승격 + `gap-x-5`), `partials/shop/detail/_purchase_card.json` (앵커 id) |
| 검사기 | `tests/Translations/yutiv-commerce-template-check.php`(신규 파셜 검사 범위), `tests/Preview/yutiv-commerce/{browser-probe,check,build}.cjs` |

`sirsoft-basic` · 코어 · `sirsoft-ecommerce` 무변경. commit·push·배포 미수행.
