<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;
use Symfony\Component\HttpFoundation\Response;

/**
 * TeeWide 호스트에서 YUTIV 콘텐츠가 노출되는 것을 막는다.
 *
 * ── 왜 필요한가 ─────────────────────────────────────────────────────────────
 * `routes/web.php:51` 의 SPA catch-all `Route::get('/{any?}')` 에는 도메인 제약이 없다.
 * `routes/api.php:364` 의 `/api/search`, 그리고 이커머스 모듈의
 * `api/modules/sirsoft-ecommerce/*` 도 마찬가지다. 즉 teewide.com 으로 요청이 오면
 * **기존 쇼핑몰 라우트가 그대로 매칭된다.** 라우트 등록 순서에 기대지 않고 이 게이트가
 * 요청을 끊는다.
 *
 * ── 등록 방식 ───────────────────────────────────────────────────────────────
 * 코어의 확장 미들웨어 자가 게이트를 쓴다 (`Plugin::getMiddleware()` 에서
 * `targets: ['everything']`, `timing: 'before_core'`, `groups: ['web','api']`).
 * sirsoft-gdpr 이 같은 방식을 쓰고 있다 (그 plugin.php:357-367).
 *
 * 코어 게이트는 **활성 플러그인의 선언만** 수집하므로, 플러그인이 비활성이면 이
 * 미들웨어는 아예 실행되지 않는다.
 *
 * ── 실행 시점 (소스로 확인된 사실) ──────────────────────────────────────────
 * `App\Http\Middleware\ExtensionMiddlewareGate::handle()` 은 51행에서
 * `$request->route()` 를 읽는다 → **라우트 매칭 이후**에 실행된다. 따라서 이 게이트는
 * "어느 라우트가 매칭됐는지" 를 보고 판단할 수 있고, 컨트롤러가 돌기 전에 끊는다.
 * 라우트 매칭 자체를 막지는 못하지만, 콘텐츠가 나가지 않게 하는 데는 충분하다.
 *
 * ── 판정 ────────────────────────────────────────────────────────────────────
 *   TeeWide 호스트 + TeeWide 라우트   → 통과
 *   TeeWide 호스트 + 그 외 라우트     → 차단 (404)
 *   그 외 호스트 + TeeWide 라우트     → 차단 (도메인 제약상 매칭될 수 없지만 심층 방어)
 *   그 외 호스트 + 그 외 라우트       → 통과 (기존 yutiv.com 동작 그대로)
 *
 * 리다이렉트하지 않는다 — 목적지를 알려주는 정보 노출이자 open redirect 표면이다.
 */
class TeeWideHostGate
{
    /** TeeWide 라우트 이름 접두. 이것으로만 소유를 판정한다. */
    public const ROUTE_PREFIX = 'teewide.';

    public function handle(Request $request, Closure $next): Response
    {
        // 꺼져 있으면 완전한 no-op — 기존 사이트에 어떤 판단도 개입하지 않는다.
        if (! TeeWideConfig::active()) {
            return $next($request);
        }

        $isTeeWideHost = TeeWideConfig::roleFor($request->getHost()) !== 'foreign';
        $isTeeWideRoute = $this->isTeeWideRoute($request);

        if ($isTeeWideHost && ! $isTeeWideRoute) {
            // teewide.com / live.teewide.com 에서 온 쇼핑몰 요청.
            return $this->block();
        }

        if (! $isTeeWideHost && $isTeeWideRoute) {
            // 도메인 제약이 있으므로 정상적으로는 불가능하다. 등록 실수에 대한 심층 방어.
            return $this->block();
        }

        return $next($request);
    }

    /**
     * 매칭된 라우트가 TeeWide 소유인가.
     */
    private function isTeeWideRoute(Request $request): bool
    {
        $name = $request->route()?->getName();

        return is_string($name) && str_starts_with($name, self::ROUTE_PREFIX);
    }

    /**
     * 차단 응답. 본문에 YUTIV 콘텐츠나 목적지 정보를 담지 않는다.
     */
    private function block(): Response
    {
        $status = TeeWideConfig::blockStatus();

        return response()->json([
            'message' => 'Not Found',
        ], $status);
    }
}
