<?php

namespace Plugins\Yutiv\LiveCommerce\Providers;

use App\Extension\BasePluginServiceProvider;
use App\Extension\Traits\CachesPluginStatus;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\DiagnosticsController;
use Plugins\Yutiv\LiveCommerce\Http\Middleware\ConfigureTeeWideSession;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;

/**
 * TeeWide 라이브커머스 서비스 프로바이더 (Phase 0 스파이크).
 *
 * ── 코어 계약 주의점 ────────────────────────────────────────────────────────
 * `App\Providers\PluginServiceProvider` 는 `plugins/` **바로 아래**만 훑고(비재귀),
 * 활성 여부와 무관하게 발견한 프로바이더를 등록한다. 그래서 이 프로바이더는 스스로
 * 활성 여부와 기능 스위치를 확인하고, 어느 하나라도 아니면 **아무 것도 등록하지 않는다.**
 *
 * ── 왜 라우트를 여기서 등록하는가 ──────────────────────────────────────────
 * `App\Providers\PluginRouteServiceProvider` 는 플러그인 라우트를 `plugins/{id}` 또는
 * `api/plugins/{id}` 프리픽스로 **강제**한다(같은 파일 121·128행). TeeWide 는 호스트
 * 루트(`teewide.com/`)와 `live.teewide.com/{tenant}` 가 필요하므로 그 로더로는 만들 수
 * 없다. 코어 `routes/web.php` 를 수정하지 않기 위해 여기서 직접 등록한다.
 * (같은 방식이 yutiv-ses_monitor 의 `/webhooks/aws/ses` 에서 이미 검증됐다.)
 */
class LiveCommerceServiceProvider extends BasePluginServiceProvider
{
    use CachesPluginStatus;

    protected string $pluginIdentifier = 'yutiv-live_commerce';

    public function register(): void
    {
        parent::register();

        // 설정 병합 — env 가 비어 있으면 config/live-commerce.php 의 기본값(비활성)이 남는다.
        $this->mergeConfigFrom(
            dirname(__DIR__, 2).'/config/live-commerce.php',
            TeeWideConfig::KEY
        );
    }

    public function boot(): void
    {
        parent::boot();

        // 두 관문: 플러그인이 활성이어야 하고, TEEWIDE_ENABLED 도 켜져 있어야 한다.
        // 둘 중 하나라도 아니면 라우트도 미들웨어도 세션 변경도 없다.
        if (! $this->pluginIsActive() || ! TeeWideConfig::active()) {
            return;
        }

        $this->registerTeeWideRoutes();
    }

    /**
     * TeeWide 도메인 라우트.
     *
     * `Route::domain()` 은 route:cache 로 컴파일된다. 다만 호스트 문자열이 컴파일 시점에
     * 박히므로, `TEEWIDE_ROOT_HOST` 를 바꾼 뒤에는 `route:cache` 를 다시 만들어야 한다.
     */
    private function registerTeeWideRoutes(): void
    {
        $stack = $this->teeWideStack();

        // ── 계정 포털: teewide.com ──────────────────────────────────────────
        Route::domain(TeeWideConfig::rootHost())
            ->middleware($stack)
            ->group(function () {
                if (! TeeWideConfig::diagnosticsEnabled()) {
                    return;
                }

                Route::get('/', [DiagnosticsController::class, 'portal'])
                    ->name('teewide.portal');

                Route::get('/_teewide/session', [DiagnosticsController::class, 'sessionMarker'])
                    ->name('teewide.portal.session');
            });

        // ── 라이브 판매: live.teewide.com/{tenant} ──────────────────────────
        Route::domain(TeeWideConfig::liveHost())
            ->middleware($stack)
            ->group(function () {
                if (! TeeWideConfig::diagnosticsEnabled()) {
                    return;
                }

                Route::get('/_teewide/session', [DiagnosticsController::class, 'sessionMarker'])
                    ->name('teewide.live.session');

                // `/` 는 의도적으로 등록하지 않는다 — 라이브 홈은 존재하지 않고,
                // 업체 slug 로만 들어온다. `/` 요청은 호스트 게이트가 404 로 끝낸다.
                Route::get('/{tenant}', [DiagnosticsController::class, 'live'])
                    ->where('tenant', TeeWideConfig::tenantSlugPattern())
                    ->name('teewide.live.tenant');
            });
    }

    /**
     * TeeWide 전용 미들웨어 스택.
     *
     * ── 왜 'web' 그룹을 쓰지 않는가 ─────────────────────────────────────────
     * 세션 설정 변경은 **StartSession 보다 먼저** 일어나야 한다. 코어의 확장 미들웨어
     * 게이트는 라우트 매칭 이후에 돌고(ExtensionMiddlewareGate.php:51 이 `$request->route()`
     * 를 읽는다), web 그룹 안에서의 상대 위치는 프레임워크 내부 구성에 달려 있어 저장소
     * 소스만으로 확정할 수 없다.
     *
     * 라우트 레벨 미들웨어는 **선언한 배열 순서대로** 실행된다. 그래서 스택을 직접 쓰고
     * `ConfigureTeeWideSession` 을 첫 자리에 둔다 — 순서가 프레임워크가 아니라 이 배열로
     * 증명된다. 덤으로 YUTIV web 그룹의 부가 미들웨어(SetLocale·Boost·확장 게이트)가
     * TeeWide 요청에 끼어들지 않아 격리도 깨끗해진다.
     *
     * @return array<int, class-string>
     */
    private function teeWideStack(): array
    {
        return [
            // ★ 반드시 첫 자리 — 세션이 시작되기 전에 쿠키 이름·도메인을 바꾼다.
            ConfigureTeeWideSession::class,

            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            SubstituteBindings::class,
        ];
    }

    /**
     * 이 플러그인이 활성 상태인가.
     *
     * 조회가 실패하는 부팅 초기·마이그레이션 전 상황에서는 비활성으로 본다 —
     * 라우트를 여는 쪽으로 실패하지 않는다.
     */
    protected function pluginIsActive(): bool
    {
        try {
            return in_array($this->pluginIdentifier, self::getActivePluginIdentifiers(), true);
        } catch (\Throwable) {
            return false;
        }
    }
}
