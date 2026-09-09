# 디자인 출처 고지 (NOTICE-DESIGN)

이 문서는 `yutiv-commerce` 템플릿의 시각 디자인을 만들 때 참고한 외부 저작물과,
그 참고가 **어디까지**였는지를 파일 단위로 기록합니다.

---

## 1. 원본 템플릿 — sirsoft-basic

`yutiv-commerce` 는 G7 번들 사용자 템플릿 `sirsoft-basic` 에서 파생했습니다.
원저작권 고지는 저장소 루트의 `LICENSE` 에 보존되어 있습니다.

```
MIT License
Copyright (c) 2026 SIRSOFT ((주)에스아이알소프트)
```

레이아웃·컴포넌트·번역의 대부분이 이 원본에서 왔으며, 이 문서가 다루는
"디자인 참고"와는 성격이 다릅니다(그쪽은 실제 코드 파생, 이쪽은 시각 참고).

---

## 2. 시각 참고 — SuperBify Commerce Minimal (Still Form)

```
MIT License
Copyright (c) 2026 SuperBify
https://github.com/20ft-bahamut/20feet-stillform-template
```

MIT 라이선스이므로 코드·자산의 사용·수정·재배포에 제약이 없습니다. 다만 이번
작업에서는 **코드도 자산도 가져오지 않았습니다.** 아래 목록은 "이런 시각적
방향을 참고했다"는 기록이며, 마크업·클래스·스타일·문구·이미지는 전부 이
템플릿의 기본 컴포넌트와 자체 번역키로 새로 작성했습니다.

### 2-1. 실제로 복사한 것

**없습니다.** 파일·코드 조각·CSS·SVG·이미지·문구를 한 줄도 옮기지 않았습니다.

- Still Form 은 Tailwind 를 쓰지 않고 자체 CSS 토큰(`--scm-*`) + 인라인 스타일로
  구성되어 있어, 마크업을 옮겨도 이 템플릿에서는 동작하지 않습니다.
- Still Form 의 로고(`still-form-logo-*.png`), 데모 상품 사진, 브랜드명
  "Still Form", 데모 사업자 정보, 약관 시안 문구는 **일절 사용하지 않았습니다.**

### 2-2. 시각적으로 참고한 방향과 적용 파일

| 참고한 방향 | 적용 파일 | 이 템플릿에서의 구현 |
| --- | --- | --- |
| 큰 경량 서체 + 작은 대문자 자간 eyebrow + 사각 CTA 의 히어로 | `layouts/partials/home/_hero.json` | `font-light` + `tracking-tight` 대형 제목, `text-xs uppercase tracking-widest` eyebrow, 라운드 필 대신 사각 블록 CTA. 배경은 이미지 자산 없이 그라디언트만 |
| 테두리·그림자 없는 이미지 우선 세로 상품 카드 | `layouts/partials/shop/_product_card.json` | 3:4 세로 이미지 + 그 아래 최소 타이포. 카드 박스·라운드·그림자 없음. 데이터는 전부 `ProductListResource` 실필드 |
| 상품 그리드의 넓은 세로 리듬 | `_product_card.json` 을 쓰는 모든 그리드 | `gap-x-4 gap-y-10` — 가로는 좁게, 세로는 넓게 |
| 카테고리를 박스가 아닌 텍스트 스트립으로 | `layouts/partials/home/_category_nav.json` | 아이콘 원형 배지 제거, 텍스트 + 밑줄 호버. 모바일은 가로 스크롤 |
| 로딩 스켈레톤으로 빈 상태 깜빡임 제거 | `layouts/partials/shop/_product_skeleton.json` | `animate-pulse` + 실제 카드와 같은 3:4 자리. 데이터소스의 `loading` 플래그로 게이트 |
| 어두운 전체 폭 브랜드 스토리 밴드 | `layouts/partials/home/_brand_story.json` | 아이콘 배지 없는 3열 텍스트 + 상단 헤어라인 |
| 빈 상태를 아이콘 배지 대신 여백·타이포로 | `layouts/partials/home/_no_products.json`, `layouts/partials/shop/list/_product_grid.json` | 중앙 정렬 경량 제목 + 보조 문구 |
| 정책/안내 페이지를 카드가 아닌 지면으로 | `layouts/page/show.json` | 흰 카드 + 그림자 제거, 경량 대형 제목, 본문 행간 확대 |
| 섹션 헤더(eyebrow + 큰 제목 + 우측 전체보기) | `layouts/partials/home/_new_products.json`, `_best_products.json`, `_all_products.json` | 동일 구성을 이 템플릿 컴포넌트로 재작성 |

### 2-3. 의도적으로 가져오지 **않은** 것

Still Form 조사에서 확인된 다음 항목은 이번 작업에서 배제했습니다.

- `superbify-commerce-compat` 플러그인 일체 (주문·결제·재고 계산 개입)
- `OrderCalculationService` 컨테이너 바인딩 교체
- 재고 예약 테이블(`ecommerce_stock_reservations`)과 마이그레이션
- `cart.before_add` / `order.before_create` / `stock.after_deduct` / `stock.after_restore` 훅
- 데모 시드 SQL (`seed/demo-seed.sql`) 및 데모 상품·공지 데이터
- `/shop` 하드코딩 라우트 (이 템플릿은 `no_route`/`route_path` 동적 표현식 유지)
- 라우트 축소 (게시판·페이지·검색·본인인증 라우트 전부 유지)
- sanitize 없는 `dangerouslySetInnerHTML` (`ProductCommonInfo.tsx:166`)
  → 이 템플릿은 상품 상세·페이지 본문 모두 `HtmlContent`(DOMPurify) 경로를 유지
- KRW 하드코딩 (이 템플릿은 `_global.preferredCurrency` 기반 다중통화 유지)
- 한국어 하드코딩 (이 템플릿의 화면 문구는 전부 번역키)
- 외부 CDN 의존 (추가 없음)

---

## 3. 외부 스크립트

이 템플릿이 로드하는 외부 스크립트는 **우편번호 검색 하나**뿐이며,
원본 `sirsoft-basic` 에서 그대로 승계한 것입니다.

```
//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js
  ← templates/_bundled/yutiv-commerce/extensions/sirsoft-daum_postcode/user-address-search.json
```

이번 디자인 개선으로 추가된 외부 도메인·CDN·추적기·분석 스크립트는 없습니다.
