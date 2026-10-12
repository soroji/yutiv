# Changelog

이 플러그인의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [0.1.4] - 2026-10-12

### Changed
- 고객 화면의 브랜드를 공개 판매자 `live_tenants.name`으로 통일하고 포털 판매자 컨텍스트를 서버 설정으로 분리합니다.
- 소유 판매자의 계정 화면에 브랜드 이름 검증·저장을 연결합니다. 내부 식별자, 회원 및 도메인 검증은 유지합니다.
- 의존 확장을 스캔했으며 이 플러그인의 공개 API를 소비하는 번들 확장은 없어 다른 확장 버전 제약 변경은 없습니다.

## [0.1.3] - 2026-10-12

### Changed
- 메인을 골프장 배경과 간결한 라이브·회원 행동 중심의 단일 화면으로 개선했습니다.
- 모바일에서도 같은 배경을 사용하며, 낮은 화면에서는 내용을 자르지 않고 스크롤할 수 있도록 했습니다.

## [0.1.2] - 2026-10-11

### Changed
- 방송 준비 화면과 모바일 메뉴를 정돈했습니다.

## [0.1.1] - 2026-10-11

### Changed

- 포털과 라이브 홈의 메인 전용 Blade/CSS로 설명·중복 영역을 줄이고 모바일 간격을 조정합니다.
- 인증·도메인·tenant·channel·공통 레이아웃의 공개 계약을 바꾸지 않았으며 개인 스토어/리워드를 연결하지 않습니다. 다른 확장의 버전 제약 상향 대상은 없습니다.

## [0.1.0] - 2026-09-10

Phase 0 기술 스파이크. 라이브커머스 기능은 없습니다.

### Added

- `Route::domain()` 기반 TeeWide 도메인 라우트 (`teewide.portal`, `teewide.live.tenant`).
  코어 `PluginRouteServiceProvider` 가 프리픽스를 강제하므로 프로바이더 `boot()` 에서
  직접 등록합니다.
- `TeeWideHostGate` — TeeWide 호스트에서 기존 쇼핑몰 SPA·검색·이커머스 API 가 노출되지
  않도록 차단합니다. 코어 확장 미들웨어 자가 게이트(`everything`/`before_core`)로 붙습니다.
- `ConfigureTeeWideSession` — TeeWide 요청의 세션 쿠키를 `teewide_session` /
  `.teewide.com` 으로 바꿉니다. **StartSession 보다 먼저** 실행되도록 라우트 자체 스택의
  첫 자리에 둡니다.
- `TeeWideHost` — 포트·대문자·후행 점·공백·IPv6 를 정규화하고, 정확히 일치할 때만
  TeeWide 로 판정합니다.
- Phase 0 진단 라우트(JSON). `TEEWIDE_DIAGNOSTICS=false` 면 등록되지 않습니다.
- PHPUnit 4개 스위트와 vendor 불필요 하네스 `tests/TeeWide/yutiv-live-commerce-check.php`.

### Notes

- **기본 비활성.** `TEEWIDE_ENABLED` 기본값이 false 라, 플러그인을 번들 상태로 두거나
  설치·활성화만 해도 기존 yutiv.com 은 아무 영향을 받지 않습니다.
- 코어 파일(`app/`, `routes/`, `config/`, `bootstrap/`)과 루트 `composer.json` 은
  **한 줄도 수정하지 않았습니다.**
- `Route::domain()` 은 route:cache 시 호스트를 박습니다. 호스트 변경 후 재캐시가 필요합니다.
- 차단은 404 이며 리다이렉트하지 않습니다.
