# Changelog

이 템플릿의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.1] - 2026-09-09

### Fixed

- **운영 활성화 시 화면이 뜨지 않던 치명적 결함을 수정했습니다.** 코어 로더는 템플릿
  식별자에서 IIFE 전역 변수명을 계산해 그 전역에서 컴포넌트를 꺼냅니다
  (`resources/js/core/template-engine/ComponentRegistry.ts` 의 `getGlobalVariableName()` —
  식별자를 `-`/`_` 로 나눠 각 조각의 첫 글자만 대문자로 이어붙임). 따라서 `yutiv-commerce`
  는 `YutivCommerce` 를 기대하는데, 번들은 원본에서 복사할 때 남은 `SirsoftBasic` 을 그대로
  노출하고 있었습니다. 스크립트는 HTTP 200 으로 내려왔지만 브라우저에서
  `Component bundle not loaded. Expected global variable: YutivCommerce` 로 초기화가
  실패했고, 운영은 `sirsoft-basic` 으로 롤백했습니다.
  - `vite.config.ts` 의 `build.lib.name` 을 `SirsoftBasic` → `YutivCommerce` 로 수정했습니다.
  - `npm run build` 로 이 템플릿의 소스에서 `dist/` 를 **다시 빌드**했습니다. 번들이
    `var YutivCommerce=` 로 시작하는 것과, 실제 스크립트 평가 후
    `typeof globalThis.YutivCommerce === 'object'` 인 것을 확인했습니다.
  - 내부 타입명·핸들러 함수명은 그대로 두었습니다. 이번 계약은 **브라우저 전역 이름** 하나입니다.
- `package-lock.json` 의 `name`/`version` 이 원본 값(`sirsoft-basic` 1.1.2)으로 남아 있던 것을
  이 템플릿 값으로 바로잡았습니다 (`npm install` 이 정정).

### Changed

- `dist/` 를 이 템플릿의 소스로 재빌드했습니다. 종전 `dist/` 는 원본 `sirsoft-basic` 의 빌드
  산출물을 그대로 복사한 것이었습니다. 재빌드로 CSS 클래스 9종이 빠지고 1종이 추가됐으며,
  빠진 9종(`bg-white/20`, `hover:bg-white/30`, `dark:hover:border-blue-800`,
  `from-emerald-500`, `to-teal-600`, `grid-cols-[60px_1fr]`, `grid-cols-[60px_1fr_160px]`,
  `max-h-[75vh]`, `min-h-[200px]`)은 이 템플릿의 어느 레이아웃에서도 쓰이지 않음을
  전수 확인했습니다.

### Added

- IIFE 전역 계약 회귀 검사를 추가했습니다
  (`tests/Preview/yutiv-commerce/iife-global-check.cjs`). 문자열 검색이 아니라 **번들을 실제로
  평가**해 `globalThis.YutivCommerce` 존재와 컴포넌트 export 를 확인하며, 식별자에서 계산한
  기대 이름과 대조합니다. 프리뷰 렌더러가 번들을 자체 방식으로 읽어 이 결함을 놓치던 문제를
  막기 위해 프리뷰 검사의 필수 단계로 넣었습니다.

### Notes

- 이 결함은 "dist 가 원본과 바이트 동일하므로 프론트엔드 빌드가 불필요하다" 는 이전 판단
  때문에 드러나지 않았습니다. **원본과 동일하다는 사실 자체가 결함이었습니다** — 번들 안에
  원본의 전역 이름이 박혀 있기 때문입니다. 파생 템플릿은 반드시 자기 소스로 빌드해야 합니다.

## [1.0.0] - 2026-09-08

### Added

- YUTIV 전용 상품 중심 쇼핑몰 사용자 템플릿을 신규 제공합니다. `sirsoft-basic` 1.1.3 을 원본으로 파생했으며, 원본을 수정하지 않는 독립 템플릿입니다.
- 홈(`/`)을 커뮤니티 대시보드가 아닌 **상품 중심 쇼핑몰 메인**으로 재구성했습니다 — 히어로 → 카테고리 탐색 → 신상품 → 베스트 → 전체 상품 순으로 배치하고, 회원 수·게시글 수·댓글 수 통계 카드와 최근 게시글·인기 게시판·커뮤니티 가이드 섹션을 제거했습니다.
- 홈 상품 섹션은 이커머스 모듈의 공개 API 를 그대로 사용합니다 — 신상품(`products/new`), 인기상품(`products/popular`), 전체 상품(`products`), 카테고리(`categories`). 별도 API 를 만들지 않았습니다.
- 상품이 0건일 때도 히어로·카테고리·브랜드 소개가 유지되며, 방문자에게는 준비 중 안내만 표시됩니다. 상품을 등록하면 코드 수정 없이 카드가 나타납니다.
- 한국어·영어·일본어·중국어 간체 4개 로케일 번역을 템플릿에 직접 포함했습니다 (`lang/{locale}.json` + `lang/partial/{locale}/`). 별도 언어팩 설치가 필요 없습니다.

### Changed

- 상품 목록·상품 상세·장바구니·주문·결제·마이페이지·게시판·인증 레이아웃과 라우트 계약은 원본과 동일하게 유지했습니다. 홈만 교체했습니다.
- 게시판은 삭제하지 않고 유지하되, 쇼핑몰 메인에서 전면 노출하지 않습니다 (헤더·푸터 메뉴로 접근).

### Notes

- 원본 `sirsoft-basic` 의 컴포넌트 TSX 는 수정하지 않았습니다. 홈은 기존 컴포넌트(`ProductCard`, `Container`, `Grid`, `Div`, `Img`, `Icon`, `A`, `H1`~`H4`)만으로 구성해 프론트엔드 빌드가 필요 없습니다.
- 중복 실행을 피하기 위해 원본의 컴포넌트 테스트 스위트(`__tests__/`, `tests/`, `src/**/__tests__/`)는 복제하지 않았습니다. 해당 컴포넌트는 `sirsoft-basic` 에서 이미 검증됩니다.
