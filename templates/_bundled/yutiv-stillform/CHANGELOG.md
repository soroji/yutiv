# Changelog

## [Unreleased]

### Fixed
- 무통장입금의 활성 계좌가 없을 때 관리자 설정 필요 안내를 표시하고 결제를 차단한다. 선택 계좌가 활성 목록에 없을 때도 진행을 차단한다. 주문·결제 API와 서버 검증은 유지한다.
- c3585c0 서버 화면 검토: 모바일 메뉴에 누락된 브랜드 이야기·쇼핑몰 공지·배송 안내 진입을 추가하고, 모바일 푸터의 마지막 링크 그룹을 두 열로 정렬해 오른쪽 빈 공간을 줄였다. 기존 API·동적 쇼핑 경로·인증·주문 흐름은 유지한다.
- 실제 G7 extension_point의 빈 DOM wrapper가 히어로 두 번째 열을 차지하던 문제 수정. 배너를 grid 밖으로 이동하고 문구·미디어의 두 열만 배치한다.
- 한글 제목의 단어 중간 줄바꿈과 중간 화면 폭에서 비주얼 종횡비·최소 높이로 생기는 넘침 수정.
- 닫힌 모바일 드로어를 숨겨 화면 밖 요소가 문서 폭에 영향을 주지 않도록 처리.

### Changed
- 원본의 콘텐츠 폭, 열 간격, 큰 미디어 비율, 모바일 스토리 순서, 어두운 푸터와 중앙 CTA 비율 반영. 상품 0건 안내 여백과 푸터 소개를 축소.
- 공개 API·훅·의존 계약 변경 없는 템플릿 내부 화면 수정이다. 다른 번들 확장 manifest에 이 템플릿을 소비하는 의존성이 없어 최소 버전 제약 동기화 대상 없음. 릴리스 버전은 유지한다.
- 실제 서버 DOM/CSS 및 브라우저 한정 수정 응답 검증을 수행. 서버 배포 후 검증은 미수행이며 `docs/design-review.md`에 근거·전후 화면·적용 절차를 기록.

## [1.0.0] - 2026-10-08

### Added
- Independent YUTIV Still Form user template with the 40 original routes, plus story, store notices, shipping/returns and coupon wallet.
- Four locales, dark palette, locally drawn SVG icons, configurable media slots and approved-storage image filtering.
- Null-safe public business information supplied by yutiv-storefront_support >=1.0.0.
- Inert extension points for future store branding, filters, rewards and server-validated order context.

### Changed
- Still Form paper/ivory surfaces, hairlines, generous spacing and consistent commerce/account styling replace the baseline visual system.
- Dynamic route prefix and existing ecommerce >=1.2.0 contracts are preserved; fallback currency formatting uses Intl.

### Security
- Pre-commit audit (2026-10-09): enforce sanitizer policy after custom options, sanitize editor initialization, replace icon-picker raw HTML with authored SVG, restrict public URLs, remove SEO/palette CDN dependencies and disable compression CDN workers.
- Exclude test setup declarations from dist and keep PHPUnit cache inside the new plugin; add local cache/output ignore rules.
- No demo data, external theme CDNs, installation, activation, database access or migration.
- Dependency scan: this is a new independent template and API consumer; no existing extension depends on it. Existing public core/module/plugin APIs are unchanged, so existing constraints remain unchanged.
