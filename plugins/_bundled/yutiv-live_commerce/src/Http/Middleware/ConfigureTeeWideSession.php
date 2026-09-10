<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideHost;
use Symfony\Component\HttpFoundation\Response;

/**
 * TeeWide 요청의 세션 쿠키를 YUTIV 와 분리한다.
 *
 * ── 왜 확장 미들웨어 게이트(before_core)를 쓰지 않는가 ──────────────────────
 * 코어의 `App\Http\Middleware\ExtensionMiddlewareGate::handle()` 은 51행에서
 * `$request->route()` 를 읽는다. 즉 그 게이트는 **라우트 매칭 이후**에 실행된다.
 * `bootstrap/app.php` 는 그것을 `prependToGroup('web', ...)` 으로 web 그룹 맨 앞에
 * 넣으므로 StartSession 보다는 앞이지만, 그 사실은 Laravel 프레임워크 내부의 web 그룹
 * 구성에 의존한다 — 저장소 소스만으로는 확정할 수 없다.
 *
 * 그래서 이 미들웨어는 **라우트 자신의 미들웨어 배열 첫 자리**에 놓인다. 라우트 레벨
 * 미들웨어는 선언한 배열 순서대로 실행되므로, StartSession 보다 먼저 실행된다는 사실이
 * 프레임워크 내부가 아니라 **우리 라우트 선언 한 줄**로 증명된다.
 * (LiveCommerceServiceProvider::teeWideStack() 참조)
 *
 * ── 하는 일 ─────────────────────────────────────────────────────────────────
 * 세션이 시작되기 전에 `session.cookie` 와 `session.domain` 을 TeeWide 값으로 바꾼다.
 * 요청 단위 config 변경이라 다음 요청에 남지 않는다.
 *
 *   cookie  teewide_session   — 이름이 다르므로 YUTIV 세션과 서로 읽지 못한다.
 *   domain  .teewide.com      — teewide.com 과 live.teewide.com 이 공유한다.
 *                               브라우저가 yutiv.com 으로는 보내지 않는다.
 *
 * 기존 YUTIV 세션 설정은 건드리지 않는다 — TeeWide 라우트에서만 실행되기 때문이다.
 */
class ConfigureTeeWideSession
{
    public function handle(Request $request, Closure $next): Response
    {
        // 꺼져 있으면 완전한 no-op. 설정을 읽지도 쓰지도 않는다.
        if (! TeeWideConfig::active()) {
            return $next($request);
        }

        // 이 라우트에 도달했다는 것 자체가 도메인 제약을 통과했다는 뜻이지만,
        // 호스트를 한 번 더 확인한다 — 라우트 등록이 잘못돼도 YUTIV 세션 설정을
        // 바꾸는 일은 없어야 한다.
        $role = TeeWideConfig::roleFor($request->getHost());

        if ($role === TeeWideHost::ROLE_FOREIGN) {
            return $next($request);
        }

        config([
            'session.cookie' => TeeWideConfig::sessionCookie(),
            'session.domain' => TeeWideConfig::sessionDomain(),
        ]);

        // 표식 — 이후 미들웨어·컨트롤러·테스트가 "TeeWide 세션이 적용됐다" 를 확인한다.
        $request->attributes->set('teewide.session_configured', true);
        $request->attributes->set('teewide.role', $role);

        return $next($request);
    }
}
