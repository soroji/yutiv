# YUTIV 상품 중심 쇼핑몰 사용자 템플릿 (`yutiv-commerce`) 구현 보고서

- 작성일: 2026-09-08
- 작업 전 branch: `main` / HEAD: `92bcc5400a82da284aab68a464ab0d40df64d1e0` / worktree: **clean**
- commit·push·서버 접속·배포·활성 전환 **미수행**

> **[2026-09-09 갱신]** 이 보고서의 §6·§7·§11·§15 는 디자인 개선 작업으로 갱신되었습니다.
> 현행 내용은 [yutiv-commerce-design-revision.md](yutiv-commerce-design-revision.md) 를 보세요.
> 특히 §6 의 "현재 운영값(`no_route=true`)" 서술은 로컬에 근거가 없어 서버 확인이 필요합니다.

---

## 1. 작업 전 branch / HEAD / worktree

```
$ git branch --show-current   →  main
$ git rev-parse HEAD          →  92bcc5400a82da284aab68a464ab0d40df64d1e0
$ git status --short          →  (출력 없음 = clean)
```

3항목 모두 확인 후 진행했습니다.

---

## 2. 공식 별도 쇼핑몰 템플릿 존재 여부

**없습니다.** `templates/_bundled/` 에는 `sirsoft-basic`(user), `sirsoft-admin_basic`(admin), `gnuboard7-hello_user_template`, `gnuboard7-hello_admin_template` 4종뿐이고, 상품 중심 사용자 템플릿은 제공되지 않습니다.

### 독립 템플릿의 공식 위치 — `templates/_bundled/{identifier}/`

"G7 업데이트 시 덮어쓰이지 않는" 위치는 추정이 아니라 코어에 명시되어 있습니다.

`app/Services/CoreUpdateService.php:951-955`
```php
// `{domain}/_bundled` 타깃은 최상위 한 레벨의 orphan 을 보존한다 — 사용자가
// `_bundled/` 바로 아래에 직접 만든 커스텀 확장 디렉토리/파일이 코어 업데이트
// 소스(번들 확장만 포함)에 없다는 이유로 삭제되던 결함 차단.
$preserveTopLevelOrphans = str_ends_with($normalizedTarget, '_bundled');
```

`app/Extension/Helpers/FilePermissionHelper.php:19-22` 가 그 계약을 구현합니다 — `removeOrphans=true` 라도 `_bundled/` 최상위의 미지 디렉토리는 삭제하지 않습니다. 따라서 `templates/_bundled/yutiv-commerce/` 는 **코어 업데이트가 지우지 않는 공식 커스텀 확장 자리**입니다. `.gitignore` 도 `!templates/_bundled/` 로 이 경로만 추적합니다.

설치본 `templates/yutiv-commerce/` 는 `.gitignore` 대상(`templates/*/`)이며 `template:install` 이 `_bundled` 에서 복사해 만듭니다.

---

## 3. 실제 조사한 홈·상품·카테고리·배너·프로모션 API

### 존재하는 것 (그대로 사용)

| 용도 | 엔드포인트 | 근거 | 응답 형태 |
| --- | --- | --- | --- |
| 카테고리 트리 | `GET /api/modules/sirsoft-ecommerce/categories` | `src/routes/api.php:70` | `data[]` — `id`, `name_localized`, `slug`, `depth`, `parent_id`, `products_count`, `children` (`PublicCategoryResource`) |
| 상품 목록 | `GET /api/modules/sirsoft-ecommerce/products` | `api.php:86` | `data.data[]` + `data.pagination{current_page, per_page, has_more_pages, last_page?, total?}` |
| 신상품 | `GET /api/modules/sirsoft-ecommerce/products/new?limit=` | `api.php:93` | `data[]` |
| 인기(베스트) | `GET /api/modules/sirsoft-ecommerce/products/popular?limit=` | `api.php:90` | `data[]` |
| 최근 본 상품 | `GET .../products/recent?ids=` | `api.php:96` | `data[]` (홈에서는 미사용) |

**상품 카드 실필드** (`ProductListResource`) — `id`, `product_code`, `name_localized`, `thumbnail_url`, `selling_price(_formatted)`, `list_price(_formatted)`, `discount_rate`, `multi_currency_*_price`, `stock_quantity`, `sales_status(_label)`, `brand_name`(관계 로드 시), `labels[]`, `review_count`, `rating_avg`.
→ 대표이미지·상품명·정상가·판매가·할인율·품절·브랜드·통화가 **전부 실제 필드로 존재**합니다. 홈은 기존 `ProductCard` 컴포넌트에 원본 객체를 그대로 넘기므로 필드 해석을 새로 만들지 않았습니다.

**상품 목록 필터 계약** — `query.category` → API `category_id`, `query.d1` = 브레드크럼 1단계 (`layouts/shop/index.json:products` DS + `partials/shop/list/_category_breadcrumb.json`). 홈 카테고리 카드는 이 계약을 그대로 씁니다.

### 존재하지 않는 것 (만들지 않음)

| 기능 | 조사 결과 | 이번 처리 |
| --- | --- | --- |
| **운영자 관리형 배너** | 이커머스 모듈·코어 어디에도 banner 모델/API 없음 (`grep -rli banner` 무결과) | 템플릿 기본 히어로로 대체. 구조를 §7 에 문서화 |
| **프로모션 목록 API** | `PromotionsSummary` DTO 는 **주문 계산 내부 전용**. 공개 조회 API 없음 | 홈에 프로모션 영역을 만들지 않음 (허위 할인 정보 금지) |
| **브랜드 공개 API** | `Brand` 모델·`Admin/BrandController` 만 존재. 공개 엔드포인트 없음 | 브랜드 전용 섹션 없음. 상품 카드의 `brand_name` 만 사용 |
| **라이브 방송 / 숏폼** | `livestream`/`shortform`/`reels` 전 저장소 무결과 | 구현하지 않음. 확장 지점만 §7 에 기록 |
| **이벤트/기획전** | 대응 모델·API 없음 | 메뉴·섹션 만들지 않음 |
| **쿠폰(다운로드)** | `GET /user/coupons/downloadable` 존재하나 **`auth:sanctum` 필수** | 비로그인 방문자가 대부분인 홈에는 넣지 않음. 상품 상세의 기존 쿠폰 UI 유지 |

---

## 4. 신규 템플릿 identifier 와 version

| 항목 | 값 | 유효성 근거 |
| --- | --- | --- |
| identifier | **`yutiv-commerce`** | `ValidExtensionIdentifier` — 하이픈 2부분 ✅, 소문자/숫자/밑줄 ✅, 각 단어 첫 글자 비숫자 ✅ |
| 디렉토리명 | `yutiv-commerce` | `TemplateManager::loadTemplates` 의 `/^[a-z0-9]+-[a-z0-9_]+$/i` ✅ |
| vendor | `yutiv` | 필수 필드 |
| version | **`1.0.0`** | 초기 버전 |
| type | `user` | `validateTemplateData` 가 `admin\|user` 만 허용 ✅ |
| license | `MIT` | 원본과 동일. 원저작권 고지 `LICENSE` 에 보존 |
| locales | `["ko","en","ja","zh-CN"]` | `TemplateService` 가 `lang/{locale}.json` 을 직접 읽음 → **언어팩 불필요** |
| dependencies | `sirsoft-board >=1.0.0`, `sirsoft-ecommerce >=1.1.0`, `sirsoft-page >=1.1.0`, `sirsoft-daum_postcode >=1.0.0` | 홈이 이커머스 공개 API 에 의존하므로 manifest 선언이 계약 |
| g7_version | `>=7.0.9` | 원본과 동일 |

---

## 5. 구현 완료 여부

**완료.** 로컬 검증 전부 통과, 배포·활성 전환은 미수행(승인 대기).

---

## 6. `/` 라우트가 쇼핑몰 홈으로 연결되는 방식

`routes.json` 의 `/` 라우트는 **원본과 동일하게 `layout: "home"`** 이고, 그 `layouts/home.json` 의 내용을 쇼핑몰 홈으로 **교체**했습니다.

```json
{ "path": "/", "layout": "home", "auth_required": false, "meta": { "title": "$t:user.home.page_title" } }
```

- `/` → `/products` 리다이렉트를 **쓰지 않았습니다.** 홈 자체를 상품 중심으로 구성했습니다 (검사기가 `redirect` 존재를 위반으로 잡습니다).
- 라우트 **path 집합이 원본과 완전히 동일**함을 검사기가 대조합니다 (누락·신규 0). 상품 목록/상세/장바구니/주문/결제/마이페이지/게시판/인증 경로가 전부 그대로입니다.
- 쇼핑몰 경로는 원본의 동적 표현식을 그대로 승계했습니다:
  `/{{...basic_info.no_route ? '' : (route_path ?? 'shop')}}/products`
  `TemplateService::resolveRoutePathExpressions()` 가 `//products` → `/products` 로 정규화하므로 현재 운영값(`no_route=true`)에서 `/products` 가 그대로 유지됩니다.

---

## 7. 메인 화면 섹션 구성

위에서 아래로:

| # | 섹션 | 파일 | 표시 조건 |
| --- | --- | --- | --- |
| 1 | **히어로** (전체 폭) | `partials/home/_hero.json` | 항상 |
| 2 | **카테고리 탐색** | `_category_nav.json` | 실제 카테고리 ≥ 1건 |
| 3 | **신상품** | `_new_products.json` | `newProducts.data` ≥ 1건 |
| 4 | **베스트** | `_best_products.json` | `popularProducts.data` ≥ 1건 |
| 5 | **전체 상품 미리보기** | `_all_products.json` | `products.data.data` ≥ 1건 |
| 6 | **상품 준비 중 안내** | `_no_products.json` | API 정상 **AND** 3개 소스 모두 0건 |
| 7 | **브랜드 소개** | `_brand_story.json` | 항상 |

헤더(로고·검색·카테고리·언어·통화·로그인·마이페이지·장바구니 수량)와 푸터는 `_user_base` 를 그대로 상속하므로 원본과 동일하게 동작합니다 — 홈만 교체했습니다.

**히어로 구조** (배너 기능이 없어 템플릿 기본으로 구성):
- 배경: `bg-gradient-to-br from-gray-900 via-gray-800 to-gray-700` — 이미지 자산 의존 없음
- eyebrow / H1(2줄) / 설명 / CTA 2개, 전부 번역키
- 주 CTA → 실제 상품 목록 라우트. 보조 CTA(`#yutiv-categories`)는 **카테고리가 실제로 있을 때만** 노출 (없으면 죽은 앵커가 되므로)

**향후 확장 지점** (이번에 구현하지 않음): 라이브/숏폼 섹션은 `home.json` 의 `slots.content` 배열에 파셜 1개를 추가하고 대응 data source 를 붙이는 형태로 확장합니다. 데이터 모델·API 가 생기기 전에는 넣지 않습니다.

**게시판**: 삭제하지 않았습니다. 라우트·레이아웃 전부 유지되고 헤더/푸터 메뉴로 접근 가능하며, 쇼핑몰 메인에서만 전면 노출을 제거했습니다.

---

## 8. 실제 데이터와 정적 템플릿 콘텐츠 구분

| 구분 | 내용 | 출처 |
| --- | --- | --- |
| **실제 운영 데이터** | 카테고리 목록·이름, 상품 카드 전체(이미지·브랜드·상품명·판매가·정상가·할인율·통화·품절·라벨·평점), 장바구니 수량, 로그인 상태, 언어/통화 선택 | 이커머스/코어 공개 API |
| **정적 템플릿 콘텐츠** | 히어로 문구(eyebrow/제목/설명/CTA 라벨), 섹션 제목("카테고리/신상품/베스트/전체 상품/전체 보기"), 준비 중 안내, 브랜드 소개 3개 항목 | 템플릿 번역 파일 (`lang/partial/{locale}/home.json`) — **운영 DB 값이 아니며 코드에 도메인·운영값을 하드코딩하지 않았습니다** |
| **만들지 않은 것** | 배너·프로모션·할인 배지(가짜)·라이브·숏폼·브랜드 목록·가짜 상품 | §3 참조 |

카테고리 아이콘·이미지는 공개 API 계약(`PublicCategoryResource`)에 없어 **아이콘 폴백을 기본값**으로 씁니다(있는 척하지 않음).

---

## 9. 상품 0개 상태 처리

- **API 오류와 0건을 구분합니다.** 각 상품 data source 에 `onError → setState(global, homeProductsFailed=true)` 를 걸고, 준비 중 안내는 `{{!_global.homeProductsFailed && ...length === 0}}` 일 때만 표시합니다. 오류일 때는 "상품이 없다"고 단정하지 않습니다.
- **0건이어도 홈이 유지됩니다**: 히어로 + 브랜드 소개는 항상 렌더됩니다 (프리뷰로 확인 — §11).
- **방문자용 중립 문구만** 노출합니다: "상품을 준비하고 있습니다 / 곧 새로운 상품으로 찾아뵙겠습니다." 관리자용 등록 안내나 로그인 상태 분기는 넣지 않았습니다.
- **상품 등록 후 코드 수정 불필요** — 섹션은 `if` 조건으로 자동 표시되고, 데이터는 API 에서 옵니다.
- 가짜 상품은 운영 화면에 넣지 않았습니다. 목 데이터는 프리뷰 렌더러(스크래치패드) 안에만 있습니다.

---

## 10. 다국어 파일과 번역키 수

| 로케일 | 진입 파일 | partial 파일 | `home` 네임스페이스 키 |
| --- | --- | ---: | ---: |
| ko | `lang/ko.json` | 21 | 20 |
| en | `lang/en.json` | 21 | 20 |
| ja | `lang/ja.json` | 21 | 20 |
| zh-CN | `lang/zh-CN.json` | 21 | 20 |

- ja / zh-CN 은 기존 `g7-template-sirsoft-basic-{ja,zh-CN}` 번들 언어팩(동일 MIT 프로젝트)의 번역을 템플릿에 직접 포함했습니다 → **별도 언어팩 설치 없이 4개 로케일 동작**.
- YUTIV 전용 신규 문구는 `home` 네임스페이스에만 추가했습니다. 나머지(shop/mypage/auth/nav/footer 등)는 기존 번역을 그대로 재사용했습니다.
- 커뮤니티 홈 전용 키(통계·최근 게시글·커뮤니티 가이드 등)는 해당 파셜을 지웠으므로 함께 제거했습니다 — 남기면 아무도 읽지 않는 죽은 키가 됩니다.
- 검사기가 4개 로케일 **키 집합 완전 일치**, **로케일 오염**(zh-CN 에 한글/가나, ja 에 한글), **홈이 쓰는 24개 번역키가 4개 로케일에 모두 존재 + 빈 값 아님**을 검증합니다.
- 상품명·카테고리명은 템플릿에서 번역하지 않습니다 — 이커머스 데이터 계약(`name_localized`)을 그대로 따릅니다.

---

## 11. 반응형 검증 결과

로컬에 Laravel 앱도 브라우저 자동화도 없어(§13) **정적 프리뷰 렌더러**를 만들어 검증했습니다. 템플릿의 **실제 `dist/css/components.css`** 를 그대로 링크하므로 클래스가 없으면 프리뷰에서도 똑같이 스타일이 빠집니다.

| 뷰포트 | 히어로 | 카테고리 | 상품 그리드 |
| --- | --- | --- | --- |
| 모바일 390px | `py-16`, H1 `text-3xl` | `flex overflow-x-auto snap-x` 가로 스크롤 (`w-32` 카드) | `grid-cols-2` |
| 태블릿 768px | `sm:px-6`, H1 `sm:text-3xl` | `grid-cols-4` | `sm:grid-cols-3` |
| 데스크톱 1440px | `lg:py-16`, H1 `lg:text-4xl` | `lg:grid-cols-6` | `lg:grid-cols-4` |

- 가로 스크롤은 카테고리 스트립 **내부 컨테이너에만** 발생하고 body 는 넘치지 않습니다.
- 터치 영역: CTA `px-6 py-3` + `text-sm` ≈ 44px, 카테고리 카드 `py-5` + 48px 아이콘.
- 긴 문구 대응: 카테고리명 `line-clamp-2`, 상품명은 `ProductCard` 의 기존 clamp 사용. en/ja/zh-CN 프리뷰에서 레이아웃 붕괴 없음.
- 접근성: heading 순서 **H1 → H2(섹션) → H3(상품/브랜드 항목)** 확인, 장식 아이콘 `aria-hidden="true"`, 포커스 표시 `focus-visible:ring-2 ... ring-offset-2`, 이미지 alt 는 `ProductCard` 가 상품명으로 채웁니다.
- 라이트/다크 양쪽 렌더 확인 (`features.dark_mode: true`).

**프리뷰 검증 결과 요약** (렌더 HTML 자동 분석):

| 프리뷰 | 상품카드 | 카테고리 | 준비중 안내 | 커뮤니티 잔재 |
| --- | ---: | ---: | --- | --- |
| desktop-1440 ko (상품 있음) | 12 | 4 | 없음 | **없음** |
| tablet-768 ko | 12 | 4 | 없음 | 없음 |
| mobile-390 ko | 12 | 4 | 없음 | 없음 |
| desktop-1440 ko (상품 0개) | 0 | 0 | **표시** | 없음 |
| desktop-1440 zh-CN | 12 | 4 | 없음 | 없음 |
| desktop-1440 en / ja | 12 | 4 | 없음 | 없음 |
| desktop-1440 ko 다크 | 12 | 4 | 없음 | 없음 |

---

## 12. 테스트·validator·lint 결과

### 신규 검사기 — `tests/Translations/yutiv-commerce-template-check.php`

```
검사 파일 494개 (JSON 272) · 레이아웃 163개 · 홈 파셜 7개 · 라우트 40개
홈 데이터소스 4개 · 홈 번역키 24개 · 홈 CSS 클래스 132종

JSON 구문                  OK (0)      홈 커뮤니티 잔재 제거   OK (0)
인코딩 (UTF-8/BOM/CRLF)    OK (0)      홈 하드코딩 문구        OK (0)
template.json 계약         OK (0)      홈 Tailwind 클래스 실재 OK (0)
LICENSE 저작권 보존        OK (0)      다국어 키 정합성        OK (0)
원본 무변경                OK (0)      로케일 오염             OK (0)
routes.json 계약           OK (0)
partial 참조               OK (0)      lang/ko    21 · en 21
컴포넌트 선언              OK (0)      lang/ja    21 · zh-CN 21
data source endpoint       OK (0)

RESULT: PASS — 위반 0건
```

**검출력 확인** (결함 주입 후 red 확인 → 원복 후 PASS):

| 주입한 결함 | 검사 결과 |
| --- | --- |
| identifier 를 `YutivCommerce` 로 훼손 | `template.json 계약 FAIL (3)` |
| 홈에 게시판 API 재삽입 | `data source FAIL (1)` + `커뮤니티 잔재 FAIL (1)` |
| zh-CN 번역에 한글 오염 | `로케일 오염 FAIL (1)` |
| 히어로에 `"Shop Now"` 하드코딩 | `홈 하드코딩 문구 FAIL (1)` |
| 빌드 CSS 에 없는 클래스(`mb-14 h-11`) 사용 | `Tailwind 클래스 FAIL (2)` |

### 회귀

| 검사 | 결과 |
| --- | --- |
| `zh-CN-core-parity-check.php` | PASS |
| `zh-CN-board-parity-check.php` | PASS |
| `zh-CN-ecommerce-parity-check.php` | PASS |
| `zh-CN-page-parity-check.php` | PASS |
| `zh-CN-plugins-parity-check.php` | PASS |
| `zh-CN-templates-parity-check.php` | PASS |
| `git diff --check` | 0건 |

### 검사기가 대신 확인한 항목

- **template manifest validator**: `TemplateManager::validateTemplateData` 의 필수 필드·`type` 규칙과 `ValidExtensionIdentifier` 규칙을 재구현해 대조 (실제 클래스 호출은 §13).
- **routes validator**: `/` 라우트 존재·layout=home·redirect 부재 + 원본과 path 집합 완전 일치.
- **component 참조 유효성**: 163개 레이아웃이 참조하는 모든 `type/name` 이 `components.json` 에 선언되어 있는지 전수 대조.
- **data source/API 경로 유효성**: 홈의 4개 endpoint 를 `modules/_bundled/sirsoft-ecommerce/src/routes/api.php` 실제 라우트 정의와 대조.
- **내부 링크 유효성**: 홈의 모든 이동이 `_global.shopBase` 기반 실제 라우트(`/products`) 또는 실제 카테고리 필터 계약을 사용.

---

## 13. 실행하지 못한 테스트와 이유

로컬 PHP는 **7.4.22**, `composer.json` 은 `^8.2` 요구, **`vendor/autoload.php` 없음**, **`node_modules` 없음**.

| 실행 못 한 것 | 이유 | 대체 |
| --- | --- | --- |
| `php artisan test` (PHPUnit 전체) | vendor 없음 / PHP 7.4 | 없음 — 서버에서 실행 필요 |
| `TemplateManager` 실제 호출로 manifest 검증 | 동일 | 검사기가 동일 규칙 재구현 |
| `php artisan template:install/activate` | 동일 + 운영 DB 금지 | §22 명령으로 제시 |
| **Playwright 스크린샷** | `node_modules` 미설치 + Laravel 앱 부팅 불가(vendor) + 로컬 DB 없음. `npm install` + 브라우저 다운로드 + `composer install` 은 "의존성 임의 변경 금지" 범위 | **정적 HTML 프리뷰**로 대체 (§23) |
| `./vendor/bin/pint`, `phpstan` | vendor 없음 | 신규 PHP 파일은 검사기 1개뿐이며 문법은 `php -l` 로 확인 |
| SEO 봇 렌더(`ComponentHtmlMapper`) 실렌더 | vendor 없음 | 코드 경로로 계약 확인 (`applyNavigateLink` 이 button+navigate → `<a href>` 변환) |

프리뷰는 **스크린샷 대체물이지 E2E 대체물이 아닙니다** — 데이터가 목이고 엔진의 데이터소스·액션 동작은 재현하지 않습니다. 실제 동작 검증은 서버 설치 후 필요합니다.

---

## 14. frontend build 필요 여부와 근거

**필요합니다 (2026-09-09 정정).** 아래는 철회된 최초 판단이며, 그 근거가 왜 틀렸는지를 함께 남깁니다.

> **[2026-09-09 정정 — 운영 활성화 실패]** 아래 "dist 가 원본과 바이트 동일 → 프론트엔드 빌드 불필요"
> 라는 결론은 **틀렸습니다.** 원본과 바이트 동일하다는 사실 자체가 결함이었습니다 — 번들 안에
> 원본의 IIFE 전역 이름 `SirsoftBasic` 이 박혀 있었기 때문입니다. 코어 로더는 식별자에서 계산한
> `YutivCommerce` 전역을 찾으므로, 스크립트가 HTTP 200 으로 내려와도 브라우저에서
> `Component bundle not loaded. Expected global variable: YutivCommerce` 로 초기화가 실패했고
> **운영은 `sirsoft-basic` 으로 롤백**했습니다. 파생 템플릿은 반드시 자기 소스로 빌드해야 합니다.
> 조치는 템플릿 CHANGELOG 의 `[1.0.1]` 항목을 보세요.
1. ~~**`dist/` 와 `src/` 가 원본과 바이트 동일**~~ ← **이것이 결함이었습니다**
   ```
   $ diff -rq templates/_bundled/sirsoft-basic/dist templates/_bundled/yutiv-commerce/dist  → 차이 없음
   $ diff -rq .../sirsoft-basic/src  .../yutiv-commerce/src                                 → differ 0개
   ```
   TSX/TS/CSS 를 **한 줄도 수정하지 않았습니다.** 홈은 기존 컴포넌트(`ProductCard`, `Container`, `Div`, `Ul`, `Li`, `H1~H3`, `P`, `Span`, `Button`, `Icon`)만 조합했습니다.

2. **Tailwind 클래스가 전부 빌드된 CSS 에 존재**
   Tailwind v4 는 `src/**` 와 `src/styles/safelist.txt` 만 스캔하고 **레이아웃 JSON 은 스캔하지 않습니다**. 여기서 없는 클래스를 쓰면 규칙이 생성되지 않아 **스타일만 조용히 빠집니다**(콘솔 오류 없음).
   → 홈이 쓰는 **132종 전부** `dist/css/components.css` 에 존재함을 확인했고, 이 검사를 검사기에 상시 항목으로 넣었습니다.

   > 최초 구현에서는 27종(`h-11`, `mb-14`, `focus-visible:outline-*`, `text-white/80`, `sm:mx-0` 등)이 빠져 있었고, 이 검사가 잡아냈습니다. 전부 CSS 에 존재하는 등가 클래스로 교체했습니다(예: `h-11→h-12`, `mb-14→mb-12`, `outline→ring`, `text-white/80→text-gray-300`, `sm:mx-0` → 엔진 `responsive` 블록).

3. **레이아웃/번역 JSON 은 런타임 로드**
   레이아웃은 설치 시 DB 에 등록되어 API 로 내려가고, 번역은 `TemplateService` 가 디스크에서 읽습니다. 번들에 인라인되지 않습니다.

`package.json` · `composer.json` · 저장소 루트 lock 파일 **무변경**입니다.

---

## 15. 신규·수정·삭제 파일 수

| 구분 | 수 |
| --- | ---: |
| 신규 | **496** (템플릿 494 + 검사기 1 + 본 보고서 1) |
| 기존 파일 수정 | **0** |
| 삭제 | 0 (템플릿 복제 과정에서 커뮤니티 홈 파셜 10개를 **새 템플릿 안에서만** 제외) |

템플릿 494파일 / 6.0MB 내역:

| 영역 | 파일 | 비고 |
| --- | ---: | --- |
| `layouts/` | 163 | 홈 8개 교체·신규, 나머지 155개는 원본 승계 |
| `src/` | 125 | 원본 바이트 동일 (재빌드 가능성 유지용) |
| `dist/` | 109 | ~~원본 바이트 동일~~ → **이 템플릿 소스로 재빌드** (전역 `YutivCommerce`, 1.0.1) |
| `lang/` | 88 | ko/en 승계 + ja/zh-CN 신규 44 + home 4개 교체 |
| `editor-spec/` | 13 | 레이아웃 편집기 지원 |
| 기타 | 6 | `template.json`, `components.json`, `routes.json`, `seo-config.json`, `LICENSE`, `CHANGELOG.md` 등 |

**원본에서 제외한 것**: `__tests__/`(69), `tests/`(11), `src/**/__tests__/`(54) — 동일 바이트의 컴포넌트를 대상으로 한 테스트라 복제하면 같은 단언이 두 벌 돌아갑니다. 해당 컴포넌트는 `sirsoft-basic` 에서 이미 검증됩니다. `node_modules/` 도 제외했습니다.

---

## 16. 수정한 기존 파일 전체 목록과 이유

**없습니다.** 기존 파일을 **한 개도 수정하지 않았습니다.**

- `templates/_bundled/sirsoft-basic/**` — 무변경 (검사기가 원본 `template.json` identifier 로 확인)
- `templates/_bundled/sirsoft-admin_basic/**` — 무변경
- `app/**`, `modules/**`, `config/**`, `lang-packs/**`, `.env`, 루트 lock 파일 — 무변경

공용 코드 수정이 필요 없었던 이유: 템플릿은 **순수 데이터 확장**(JSON + 자산)이며 PHP 클래스를 요구하지 않고, 홈이 쓰는 API·컴포넌트·라우트 계약이 전부 이미 존재했습니다.

---

## 17. 예상하지 못한 변경

허용 범위 밖 변경은 없습니다. 조사 중 발견해 **그대로 둔** 인수 사항 3건:

1. **`lang/partial/*/sirsoft-basic.json` 네임스페이스** — 원본에서 승계한 주소 입력 문구(`address.*`)입니다. 저장소 전체에서 `$t:sirsoft-basic.*` 참조자를 찾지 못했고(레이아웃·플러그인 모두), Daum 우편번호 확장은 자기 네임스페이스(`sirsoft-daum_postcode.*`)를 씁니다. 참조자가 없어 개명해도 이득이 없고 미발견 경로를 깨뜨릴 위험만 있어 **원본 그대로 두었습니다.**
2. **커스텀 핸들러 키가 `sirsoft-basic.*` 네임스페이스** — `src/handlers/index.ts` 가 상품 옵션/통화 핸들러를 그 이름으로 등록하고 상품 상세 레이아웃이 같은 이름으로 호출합니다. 엔진은 `customHandlers: Map<string, Handler>` 의 **평범한 문자열 키**로만 조회하므로(`ActionDispatcher.ts:6345`) 번들·레이아웃을 함께 복사한 이 템플릿에서 정상 동작합니다. 개명하면 TS 수정이 필요하고 이번 계약(브라우저 전역 이름)과 무관하므로 유지했습니다. (§14 의 "빌드 불필요" 전제는 2026-09-09 에 철회됐습니다 — 재빌드는 필수입니다.) 활성 템플릿의 자산만 로드되므로(`app.blade.php` 의 `$activeUserTemplate`) 두 템플릿이 동시에 등록되는 충돌도 없습니다.
3. **`template.json` 의 `preview.thumbnail`** — `preview/thumbnail.png` 를 선언하지만 파일이 없습니다. **원본 `sirsoft-basic` 도 동일**하므로 새로 생긴 문제가 아니며, 파일을 지어내지 않고 원본과 동일한 상태로 두었습니다.

---

## 18. `git status --short` 전체

```
?? docs/reports/yutiv-commerce-template-report.md
?? templates/_bundled/yutiv-commerce/
?? tests/Translations/yutiv-commerce-template-check.php
```

수정(`M`)·삭제(`D`)·이름변경(`R`) 항목이 **하나도 없습니다.** 전부 신규 추가입니다.

---

## 19. GitHub Desktop 커밋 가능 여부

**가능합니다.**

- 충돌 없음 (clean 트리에서 시작, 기존 파일 무수정)
- `git diff --check` 0건, BOM/CRLF 0건 (검사기 확인)
- 삭제·이름변경 0건
- 신규 496파일 / 약 6.0MB. **바이너리 없음** — 전부 JSON / TS / CSS / MD 텍스트
- `.gitignore` 상 `!templates/_bundled/` 로 추적 대상이 맞습니다

커밋 전 확인 권장: 496개 항목이 모두 스테이징되는지, 특히 `templates/_bundled/yutiv-commerce/dist/` (자산이라 무심코 제외하면 화면이 깨집니다).

---

## 20. 권장 커밋 제목

```
feat(template): YUTIV 상품 중심 쇼핑몰 사용자 템플릿 추가
```

---

## 21. 설치·활성화 전 서버 백업 명령

```bash
cd /path/to/g7
STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p ~/g7-backup-$STAMP

# 1) 현재 활성 템플릿 상태 (롤백 판단 근거)
php artisan template:list | tee ~/g7-backup-$STAMP/templates.before.txt

# 2) 설치된 템플릿 디렉토리
tar czf ~/g7-backup-$STAMP/templates-installed.tgz templates/sirsoft-basic templates/sirsoft-admin_basic

# 3) DB — 템플릿 활성 상태와 레이아웃이 DB 에 있다
mysqldump -u <USER> -p <DBNAME> \
  --single-transaction --quick --default-character-set=utf8mb4 \
  > ~/g7-backup-$STAMP/db-before.sql

# 4) 설정
cp -a storage/app/settings ~/g7-backup-$STAMP/settings

ls -la ~/g7-backup-$STAMP
```

---

## 22. 서버 설치·활성화·검증·롤백 명령

### 22-1. 배포 + 설치 (활성화는 아직)

```bash
cd /path/to/g7
git fetch origin && git checkout main && git pull --ff-only

# 의존성 잠금 파일 무변경 → composer/npm install 불필요
# frontend build 불필요 (§14 근거)

# 템플릿 설치 — _bundled/yutiv-commerce → templates/yutiv-commerce 복사 + 레이아웃 DB 등록
php artisan template:install yutiv-commerce

php artisan template:list          # yutiv-commerce 가 installed(비활성)로 보여야 한다
```

이 시점까지는 **운영 화면이 그대로**입니다 (`sirsoft-basic` 이 계속 활성).

### 22-2. 활성화 (승인 후)

```bash
php artisan template:activate yutiv-commerce

php artisan template:clear-cache
php artisan seo:clear
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
```

### 22-3. 검증

```bash
# 라우트·번역 정합성 (vendor 불필요)
php tests/Translations/yutiv-commerce-template-check.php --verbose

# 회귀
php artisan test --filter=Template
php artisan test --filter=Layout
php artisan test tests/Feature/Api/Public
php tests/Translations/zh-CN-templates-parity-check.php --style
```

**화면 확인**

| 확인 | 기대 |
| --- | --- |
| `/` | 히어로 + (카테고리) + 상품 섹션. 통계 카드·최근 게시글·인기 게시판·커뮤니티 가이드 **없음** |
| `/` (상품 0개 상태) | 히어로 + 브랜드 소개 + "상품을 준비하고 있습니다". 관리자 문구 없음 |
| `/products` | 기존 상품 목록 그대로 |
| 상품 상세 → 장바구니 → 주문 → 결제 | 기존 흐름 그대로 |
| `/mypage/*`, `/login`, `/board/*` | 기존 그대로 |
| 언어 ko/en/ja/zh-CN 전환 | 홈 문구가 각 언어로 |
| 통화 전환 | 상품 카드 가격이 선택 통화로 |
| 헤더 장바구니 | 실제 수량 표시 |
| 모바일 390 / 태블릿 768 / 데스크톱 1440 | 가로 스크롤 없음 |
| 봇 렌더 (`curl -A "Googlebot" https://<도메인>/`) | 상품·카테고리 링크가 `<a href>` 로 나옴 |

### 22-4. 롤백

```bash
# 가장 빠른 복구 — 원래 템플릿 재활성화 (데이터 손실 없음)
php artisan template:activate sirsoft-basic
php artisan template:clear-cache && php artisan seo:clear
php artisan config:clear && php artisan config:cache

# 신규 템플릿 완전 제거까지 원하면
php artisan template:uninstall yutiv-commerce

# 그래도 문제가 남으면 DB 복원
# mysql -u <USER> -p <DBNAME> < ~/g7-backup-$STAMP/db-before.sql
```

`yutiv-commerce` 는 독립 템플릿이라 `sirsoft-basic` 의 파일·레이아웃을 건드리지 않습니다. 따라서 롤백은 **활성 템플릿 전환 한 줄**로 끝납니다.

---

## 23. 디자인 스크린샷 또는 preview 경로

Playwright 스크린샷은 로컬에서 만들 수 없어(§13) **실제 `dist/css/components.css` 를 사용하는 정적 HTML 프리뷰**를 생성했습니다. 브라우저로 열어 창 폭을 조절하면 반응형을 그대로 확인할 수 있습니다.

```
tests/Preview/yutiv-commerce/output/clean/   ← 검토용 프리뷰의 단일 기준 경로
```

| 파일 | 내용 |
| --- | --- |
| `desktop-1440-ko-상품있음.html` | 데스크톱 · 한국어 · 상품 fixture |
| `tablet-768-ko-상품있음.html` | 태블릿 |
| `mobile-390-ko-상품있음.html` | 모바일 |
| `desktop-1440-ko-상품0개.html` | **상품 0개 상태** |
| `desktop-1440-zhCN-상품있음.html` | 중국어 간체 |
| `desktop-1440-en-상품있음.html` / `-ja-` | 영어 / 일본어 |
| `desktop-1440-ko-다크모드.html` | 다크 모드 |

생성기: `tests/Preview/yutiv-commerce/build.cjs` (저장소 안 전용 도구). 이 절에 적힌 임시 프리뷰는 폐기되었습니다 — 검토용 프리뷰의 단일 기준 경로는 `tests/Preview/yutiv-commerce/output/clean/` 하나입니다.

---

## 24. 상세 보고서 파일 경로

`docs/reports/yutiv-commerce-template-report.md`

```powershell
Get-Content -Raw -Encoding UTF8 "docs\reports\yutiv-commerce-template-report.md" | Set-Clipboard
```
