<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideHost;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideSessionScope;
use Symfony\Component\HttpFoundation\Response;

/**
 * TeeWide 요청의 세션을 YUTIV 와 완전히 분리한다 — **이 요청 동안에만.**
 *
 * ── 왜 확장 미들웨어 게이트(before_core)를 쓰지 않는가 ──────────────────────
 * 코어의 `App\Http\Middleware\ExtensionMiddlewareGate::handle()` 은 51행에서
 * `$request->route()` 를 읽는다. 즉 그 게이트는 **라우트 매칭 이후**에 실행된다.
 * 세션 준비는 StartSession 보다 먼저여야 하는데, web 그룹 안에서의 상대 위치는
 * 프레임워크 내부 구성에 달려 있어 저장소 소스만으로 확정할 수 없다.
 *
 * 그래서 이 미들웨어는 **라우트 자신의 미들웨어 배열 첫 자리**에 놓인다. 라우트 레벨
 * 미들웨어는 선언한 배열 순서대로 실행되므로, StartSession 보다 먼저 실행된다는 사실이
 * 프레임워크 내부가 아니라 **우리 라우트 선언 한 줄**로 증명된다.
 * (LiveCommerceServiceProvider::teeWideStack() 참조)
 *
 * ── 격리해야 하는 것이 세 가지다 ───────────────────────────────────────────
 * ① **전역 config** — `config(['session.cookie' => ...])` 는 전역이다. 되돌리지 않으면 이 앱이
 *    사는 동안 계속 TeeWide 값이라, 다음 YUTIV 요청이 teewide_session 을 자기 세션 쿠키로
 *    읽는다. (서버 4차 실행이 잡은 결함)
 *
 * ② **세션 Store 인스턴스** — `SessionManager` 는 드라이버를 캐시하고(Manager.php:65-79)
 *    `session.store` 는 싱글턴이다. 같은 Store 를 나눠 쓰면 `Store::loadSession()` 이
 *    `array_replace($this->attributes, ...)` 로 **이전 요청의 attributes 를 그대로 남기므로**
 *    (Store.php:114-119), TeeWide 요청이 YUTIV 의 `login_web_<sha1>` 키를 물려받는다.
 *    (서버 5차 실행이 잡은 결함 — `yutiv_user_leaked=true`)
 *
 * ③ **인증 guard** — `AuthManager::createSessionDriver()` 는 guard 생성 시
 *    `$this->app['session.store']` 를 캡처하고(AuthManager.php:122-131) guard 를 캐시한다(65-70).
 *    Store 만 바꾸고 guard 를 그대로 두면 guard 가 옛 Store 를 계속 본다.
 *
 * 이 격리는 **호스트 SessionManager 를 건드리지 않고** 이뤄진다. 요청 동안만 쓰고 버리는
 * 매니저를 따로 만들어 컨테이너 바인딩만 잠시 돌린다 — 호스트 매니저의 `$drivers` 도
 * `$customCreators` 도 손대지 않으므로, Laravel/Octane 이 나중에 `forgetDrivers()` 로
 * 새 Store 를 만들려는 동작을 방해하지 않는다. (TeeWideSessionScope 주석 참조)
 *
 * 세 가지 모두 `finally` 에서 되돌린다. 테스트 tearDown 이 아니라 **프로덕션 코드**가
 * 요청 범위 격리를 책임진다 — PHP-FPM 이 아닌 queue worker · Octane · RoadRunner 에서는
 * 이것이 곧 교차 테넌트 세션 혼입 방지다.
 *
 * ── 되돌리는 시점이 너무 이르지 않은가 ─────────────────────────────────────
 * 아니다. `StartSession::handleStatefulRequest()` 는 `$next($request)` 가 돌아온 **자기
 * 프레임 안에서** 응답 쿠키를 붙이고 세션을 저장한다(StartSession.php:120-129):
 *
 *     $response = $next($request);      // 120
 *     $this->addCookieToResponse(...);  // 124  ← 쿠키 이름·도메인이 여기서 정해진다
 *     $this->saveSession($request);     // 129  ← 세션 레코드가 여기서 기록된다
 *
 * 이 미들웨어는 스택 **첫 자리**라 StartSession 보다 바깥이다. 따라서 아래 `finally` 는
 * 쿠키 발급과 세션 저장이 **모두 끝난 뒤**에 실행된다.
 */
class ConfigureTeeWideSession
{
    public function __construct(
        private readonly Application $app,
        private readonly SessionManager $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 꺼져 있으면 완전한 no-op. 설정을 읽지도 쓰지도 않는다.
        if (! TeeWideConfig::active()) {
            return $next($request);
        }

        // 이 라우트에 도달했다는 것 자체가 도메인 제약을 통과했다는 뜻이지만,
        // 호스트를 한 번 더 확인한다 — 라우트 등록이 잘못돼도 YUTIV 세션을
        // 건드리는 일은 없어야 한다.
        $role = TeeWideConfig::roleFor($request->getHost());

        if ($role === TeeWideHost::ROLE_FOREIGN) {
            return $next($request);
        }

        // ① 원래(YUTIV) Store 를 **설정 변경 전에** 확보한다. 순서가 반대면 원래 Store 가
        //    TeeWide 설정으로 만들어져 버린다.
        $hostStore = $this->resolveHostStore();

        // 세션을 해석할 수 없는 실행 경로(콘솔 등)에서는 설정 격리만 수행한다.
        if ($hostStore === null) {
            return $this->withConfigScope($next, $request, $role);
        }

        $snapshot = config('session');

        config([
            'session.cookie' => TeeWideConfig::sessionCookie(),
            'session.domain' => TeeWideConfig::sessionDomain(),
        ]);

        // ② 요청 범위 스코프를 연다 — 전용 Store + 쓰고 버리는 매니저 + 바인딩 교체.
        //    호스트 SessionManager 에는 아무 것도 설치하지 않는다.
        $token = TeeWideSessionScope::enter($this->app, $hostStore, TeeWideConfig::sessionCookie());

        // ③ guard 가 새 Store 를 보도록 guard 캐시를 비운다 (로그아웃이 아니다).
        $this->forgetGuards();

        $request->attributes->set('teewide.session_configured', true);
        $request->attributes->set('teewide.role', $role);
        $request->attributes->set('teewide.session_store_id', spl_object_id($token['store']));
        $request->attributes->set('teewide.host_session_store_id', spl_object_id($hostStore));

        try {
            return $next($request);
        } finally {
            // 예외로 빠져나가더라도 반드시 되돌린다. 되돌리지 못한 채 예외가 나가면
            // 그 앱의 남은 수명 동안 YUTIV 요청이 TeeWide 세션을 쓰게 된다.
            TeeWideSessionScope::leave($this->app, $token);
            $this->forgetGuards();

            if (is_array($snapshot)) {
                // 배열을 통째로 되돌린다 — Config Repository 에는 forget() 이 없어
                // 키 단위로는 "원래 없던 키" 를 되살릴 수 없다. 통째 교체는 없던 키·
                // null·빈 문자열·false 를 모두 원래 모습 그대로 복원한다.
                // 지역 변수이므로 중첩 호출이 바깥 스냅샷을 훼손하지 않는다.
                config(['session' => $snapshot]);
            }
        }
    }

    /**
     * 세션을 해석할 수 없을 때의 축소 경로 — 설정만 격리한다.
     */
    private function withConfigScope(Closure $next, Request $request, string $role): Response
    {
        $snapshot = config('session');

        config([
            'session.cookie' => TeeWideConfig::sessionCookie(),
            'session.domain' => TeeWideConfig::sessionDomain(),
        ]);

        $request->attributes->set('teewide.session_configured', true);
        $request->attributes->set('teewide.role', $role);

        try {
            return $next($request);
        } finally {
            if (is_array($snapshot)) {
                config(['session' => $snapshot]);
            }
        }
    }

    /**
     * 현재(YUTIV) 세션 Store — 해석할 수 없으면 null.
     */
    private function resolveHostStore(): ?Store
    {
        try {
            $driver = $this->sessions->driver();

            return $driver instanceof Store ? $driver : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 인증 guard 캐시를 비운다 — 다음 `guard()` 가 현재 `session.store` 로 다시 만들어진다.
     *
     * guard 를 비우는 것은 **로그아웃이 아니다.** 세션 레코드도 세션 속성도 지우지 않는다.
     * 캐시된 guard 객체만 버리므로, YUTIV 호스트로 돌아가면 같은 세션에서 같은 사용자가
     * 그대로 복원된다.
     */
    private function forgetGuards(): void
    {
        try {
            $auth = $this->app->make('auth');

            if (method_exists($auth, 'forgetGuards')) {
                $auth->forgetGuards();
            }
        } catch (\Throwable) {
            // guard 를 비우지 못해도 요청을 막지는 않는다.
        }
    }
}
