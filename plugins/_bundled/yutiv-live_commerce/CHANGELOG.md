# Changelog

이 플러그인의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

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
