<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideHost;
use Symfony\Component\HttpFoundation\Response;

/**
 * TeeWide 요청의 세션 쿠키를 YUTIV 와 분리한다 — **이 요청 동안에만.**
 *
 * ── 왜 확장 미들웨어 게이트(before_core)를 쓰지 않는가 ──────────────────────
 * 코어의 `App\Http\Middleware\ExtensionMiddlewareGate::handle()` 은 51행에서
 * `$request->route()` 를 읽는다. 즉 그 게이트는 **라우트 매칭 이후**에 실행된다.
 * 세션 설정 변경은 StartSession 보다 먼저여야 하는데, web 그룹 안에서의 상대 위치는
 * 프레임워크 내부 구성에 달려 있어 저장소 소스만으로 확정할 수 없다.
 *
 * 그래서 이 미들웨어는 **라우트 자신의 미들웨어 배열 첫 자리**에 놓인다. 라우트 레벨
 * 미들웨어는 선언한 배열 순서대로 실행되므로, StartSession 보다 먼저 실행된다는 사실이
 * 프레임워크 내부가 아니라 **우리 라우트 선언 한 줄**로 증명된다.
 * (LiveCommerceServiceProvider::teeWideStack() 참조)
 *
 * ── 무엇을 바꾸고, 왜 반드시 되돌리는가 ────────────────────────────────────
 * `config(['session.cookie' => ...])` 는 **전역** 설정을 바꾼다. 되돌리지 않으면 이 앱이
 * 사는 동안 계속 TeeWide 값이다. PHP-FPM 의 한 요청-한 프로세스에서는 눈에 띄지 않지만,
 * queue worker · Octane · RoadRunner 처럼 같은 Application 을 재사용하는 실행 모델에서는
 * **다음 YUTIV 요청이 teewide_session 쿠키를 자기 세션 쿠키로 읽는다.** 그건 테스트만의
 * 문제가 아니라 교차 테넌트 세션 혼입이다.
 *
 * 서버 4차 PHPUnit 이 정확히 이것을 잡아냈다 —
 * `test_YUTIV_세션_설정은_변경되지_않는다` 가 TeeWide 요청 뒤에도 `session.cookie` 가
 * `teewide_session` 으로 남아 있음을 보였다(기대 `g7-session`).
 *
 * 그래서 이 미들웨어가 요청 범위 격리를 **스스로** 책임진다. 테스트의 tearDown 이 아니라
 * 프로덕션 코드가 되돌린다.
 *
 * ── 왜 세션 스토어 이름까지 다루는가 ───────────────────────────────────────
 * `SessionManager` 는 드라이버를 캐시한다(`Illuminate\Support\Manager::driver()`,
 * v12.62.0 Manager.php:65-79). 그리고 스토어의 쿠키 **이름은 생성 시점의 설정으로 한 번
 * 박힌다**(`SessionManager::buildSession()`, SessionManager.php:190-200):
 *
 *     new Store($this->config->get('session.cookie'), $handler, ...)
 *
 * `StartSession` 은 요청 쿠키를 `$session->getName()` 으로 찾고(StartSession.php:156-161)
 * 응답 쿠키도 그 이름으로 발급한다(221-236). 즉 설정만 되돌리고 스토어를 그대로 두면,
 * TeeWide 요청 중에 만들어진 스토어가 `teewide_session` 이라는 이름을 그대로 들고 있어
 * 이후 YUTIV 응답까지 그 이름으로 쿠키를 발급한다.
 *
 * 그래서 **설정을 바꾸기 전에** 스토어를 먼저 해석해 원래 이름(YUTIV 값)을 확보하고,
 * 요청 동안만 TeeWide 이름을 씌운 뒤 되돌린다.
 *
 * ── 되돌리는 시점이 너무 이르지 않은가 ─────────────────────────────────────
 * 아니다. `StartSession::handleStatefulRequest()` 는 `$next($request)` 가 돌아온 **자기
 * 프레임 안에서** 응답 쿠키를 붙인다(StartSession.php:120-129):
 *
 *     $response = $next($request);      // 120
 *     $this->storeCurrentUrl(...);      // 122
 *     $this->addCookieToResponse(...);  // 124  ← 쿠키 이름·도메인이 여기서 정해진다
 *     $this->saveSession($request);     // 129
 *
 * 이 미들웨어는 스택 **첫 자리**라 StartSession 보다 바깥이다. 따라서 아래 `finally` 는
 * StartSession 이 쿠키를 다 붙인 뒤에 실행된다 — 응답 쿠키는 TeeWide 값으로 나가고,
 * 설정만 YUTIV 로 돌아온다.
 */
class ConfigureTeeWideSession
{
    public function __construct(private readonly SessionManager $sessions) {}

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

        // ① 스토어를 **설정 변경 전에** 해석한다. 순서가 반대면 스토어가 TeeWide 이름으로
        //    만들어져, 그 이름이 앱이 사는 동안 남는다 (위 주석 참조).
        $store = $this->resolveSessionStore();
        $originalStoreName = $store?->getName();

        // ② 설정 전체를 통째로 찍어 둔다. 키 단위로 되돌리면 "원래 없던 키" 를 되살릴
        //    수 없다 — Config Repository 에는 forget() 이 없기 때문이다. 배열을 통째로
        //    되돌리면 없던 키·null·빈 문자열·false 가 모두 원래 모습 그대로 복원된다.
        //    지역 변수이므로 중첩 호출이 있어도 바깥 스냅샷을 훼손하지 않는다.
        $snapshot = config('session');

        config([
            'session.cookie' => TeeWideConfig::sessionCookie(),
            'session.domain' => TeeWideConfig::sessionDomain(),
        ]);

        $store?->setName(TeeWideConfig::sessionCookie());

        // 표식 — 이후 미들웨어·컨트롤러·테스트가 "TeeWide 세션이 적용됐다" 를 확인한다.
        $request->attributes->set('teewide.session_configured', true);
        $request->attributes->set('teewide.role', $role);

        try {
            return $next($request);
        } finally {
            // 예외로 빠져나가더라도 반드시 되돌린다. 되돌리지 못한 채 예외가 나가면
            // 그 앱의 남은 수명 동안 YUTIV 요청이 TeeWide 쿠키를 쓰게 된다.
            if ($store !== null && is_string($originalStoreName)) {
                $store->setName($originalStoreName);
            }

            if (is_array($snapshot)) {
                config(['session' => $snapshot]);
            }
        }
    }

    /**
     * 현재 세션 스토어 (해석할 수 없으면 null).
     *
     * 세션이 구성되지 않은 실행 경로(콘솔 등)에서도 이 미들웨어가 요청을 막아서는 안 되므로
     * 실패는 조용히 null 로 떨어뜨린다 — 그 경우 설정 격리만 수행한다.
     */
    private function resolveSessionStore(): ?Store
    {
        try {
            $driver = $this->sessions->driver();

            return $driver instanceof Store ? $driver : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
