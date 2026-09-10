# TeeWide 라이브커머스 (Phase 0 기술 스파이크)

TeeWide 멀티업체 라이브 판매 플랫폼의 **첫 단계**입니다. 라이브커머스 기능은 아직
하나도 없습니다. 이 단계의 목적은 네 가지를 실제 코드와 자동 테스트로 증명하는 것입니다.

1. yutiv.com 과 teewide.com / live.teewide.com 의 **라우트 격리**
2. yutiv.com 세션과 TeeWide 세션의 **완전한 분리**
3. teewide.com ↔ live.teewide.com 의 **TeeWide 세션 공유**
4. `config:cache` / `route:cache` 및 **플러그인 비활성 상태의 안전성**

## 기본 비활성

```env
TEEWIDE_ENABLED=false          # 기본값 — 이 상태에서는 아무 것도 등록되지 않는다
TEEWIDE_ROOT_HOST=teewide.com
TEEWIDE_LIVE_HOST=live.teewide.com
TEEWIDE_SESSION_COOKIE=teewide_session
TEEWIDE_SESSION_DOMAIN=.teewide.com
TEEWIDE_DIAGNOSTICS=true       # Phase 0 진단 라우트. 운영에서는 false 로 둔다
```

두 관문을 모두 통과해야 라우트가 붙습니다.

| 상태 | 결과 |
|---|---|
| 플러그인 미설치 / 번들 상태 | 프로바이더 자체가 발견되지 않음 — 영향 0 |
| 플러그인 비활성 | 라우트·미들웨어·세션 변경 없음 |
| 활성 + `TEEWIDE_ENABLED=false` | 라우트 미등록, 호스트 게이트 완전 no-op |
| 활성 + 호스트 미설정 / 두 호스트 동일 | 라우트 미등록 |

## 도메인 구조

| 호스트 | 경로 | 라우트 이름 |
|---|---|---|
| `teewide.com` | `/` | `teewide.portal` |
| `teewide.com` | `/_teewide/session` | `teewide.portal.session` (진단) |
| `live.teewide.com` | `/{tenant}` | `teewide.live.tenant` |
| `live.teewide.com` | `/` | **없음** — 라이브 홈은 존재하지 않는다 |

`golfif`(골프이프)가 첫 업체입니다. 알 수 없는 slug 는 404 입니다.

## 왜 코어 라우트 로더를 쓰지 않는가

`App\Providers\PluginRouteServiceProvider` 는 플러그인 라우트를 `plugins/{id}` 또는
`api/plugins/{id}` 프리픽스로 **강제**합니다(같은 파일 121·128행). TeeWide 는 호스트
루트와 `/{tenant}` 가 필요하므로 그 로더로는 만들 수 없습니다. 코어를 수정하지 않기
위해 프로바이더 `boot()` 에서 `Route::domain()` 으로 직접 등록합니다.

## 세션 설계 — StartSession 보다 먼저

세션 쿠키 이름·도메인 변경은 **StartSession 이전**에 일어나야 합니다.

코어의 확장 미들웨어 게이트(`App\Http\Middleware\ExtensionMiddlewareGate`)는 51행에서
`$request->route()` 를 읽습니다 — 즉 **라우트 매칭 이후**에 실행됩니다.
`bootstrap/app.php` 가 그것을 `prependToGroup('web', ...)` 으로 web 그룹 맨 앞에 넣으므로
StartSession 보다는 앞이지만, **그 상대 위치는 프레임워크 내부 web 그룹 구성에 의존**해
저장소 소스만으로 확정할 수 없습니다.

그래서 TeeWide 라우트는 web 그룹을 쓰지 않고 **자체 스택**을 선언합니다.

```php
ConfigureTeeWideSession::class,   // ★ 첫 자리 — 여기서 쿠키 이름·도메인을 바꾼다
EncryptCookies::class,
AddQueuedCookiesToResponse::class,
StartSession::class,              // ← 그다음에야 세션이 시작된다
ShareErrorsFromSession::class,
ValidateCsrfToken::class,
SubstituteBindings::class,
```

라우트 레벨 미들웨어는 선언 순서대로 실행되므로, "세션 설정이 먼저" 라는 사실이
프레임워크 내부가 아니라 **이 배열 한 곳**으로 증명됩니다.

## 호스트 게이트

`routes/web.php:51` 의 SPA catch-all `Route::get('/{any?}')` 에는 도메인 제약이 없습니다.
`routes/api.php:364` 의 `/api/search`, 이커머스 모듈의 `api/modules/sirsoft-ecommerce/*`
도 마찬가지입니다. 즉 **teewide.com 으로 요청하면 기존 쇼핑몰 라우트가 매칭됩니다.**

`TeeWideHostGate` 가 코어의 확장 미들웨어 자가 게이트(`targets: ['everything']`,
`before_core`, `web`+`api`)로 붙어 이를 끊습니다. sirsoft-gdpr 이 쓰는 것과 같은 방식입니다.

| 호스트 | 라우트 | 결과 |
|---|---|---|
| TeeWide | TeeWide | 통과 |
| TeeWide | 그 외 | **404** |
| 그 외 | TeeWide | **404** (심층 방어) |
| 그 외 | 그 외 | 통과 (기존 yutiv.com 그대로) |

리다이렉트하지 않습니다 — 목적지를 알려주는 정보 노출이자 open redirect 표면입니다.

## Host 정규화

포트·대문자·후행 점·좌우 공백·IPv6 대괄호를 모두 `TeeWideHost::canonical()` 한 곳에서
처리합니다. 판정은 **정확히 일치**할 때만 TeeWide 로 봅니다 — `evil-teewide.com`,
`teewide.com.attacker.net` 은 통과하지 못합니다.

## 캐시

- `config:cache` — 설정에 클로저가 없어 직렬화됩니다.
- `route:cache` — 라우트 액션이 컨트롤러 배열이라 컴파일됩니다.
- ⚠ `Route::domain()` 은 호스트 문자열을 **컴파일 시점에 박습니다.**
  `TEEWIDE_ROOT_HOST` 를 바꾼 뒤에는 반드시 `php artisan route:cache` 를 다시 만드세요.

## 테스트

```bash
# Host 정규화·기본 비활성 (PHP 7.4 로도 실행, vendor 불필요)
php tests/TeeWide/yutiv-live-commerce-check.php --verbose

# 서버 (PHP 8.3 + vendor) — 라우트·세션·차단은 여기서만 증명된다
vendor/bin/phpunit plugins/_bundled/yutiv-live_commerce/tests
```

독립 하네스는 **런타임 증명이 아닙니다.** 도메인 매칭·세션 공유·차단 동작은 서버
PHPUnit 으로만 확인됩니다.

## 아직 없는 것

회원가입·주문·상품·재고·엑셀·라벨·정산은 Phase 1 이후입니다. 관리자 메뉴와 권한도
아직 선언하지 않습니다 — 지금 만들면 비활성 상태에서도 메뉴가 보이는 부작용만 생깁니다.
