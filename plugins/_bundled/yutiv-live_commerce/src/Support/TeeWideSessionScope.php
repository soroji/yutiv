<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Session\EncryptedStore;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;

/**
 * TeeWide 요청 동안만 살아 있는 **요청 범위 세션 스코프**.
 *
 * ── 왜 별도 Store 가 필요한가 ───────────────────────────────────────────────
 * `SessionManager` 는 드라이버를 캐시하고(`Illuminate\Support\Manager::driver()`,
 * v12.62.0 Manager.php:65-79), `session.store` 는 싱글턴이라(SessionServiceProvider)
 * 앱 수명 내내 Store 하나가 재사용된다. 그 하나를 YUTIV 와 TeeWide 가 나눠 쓰면
 * `Store::loadSession()` 의 `array_replace($this->attributes, ...)`(Store.php:114-119)
 * 때문에 이전 요청의 속성이 그대로 남아 `login_web_<sha1>` 이 TeeWide 요청까지 따라온다.
 *
 * ── 왜 호스트 SessionManager 를 건드리지 않는가 ────────────────────────────
 * 이전 구현은 호스트 매니저에 `extend()` 로 커스텀 creator 를 설치했다. 그건 위험하다:
 *
 *   · `Manager::$customCreators` 를 **제거하는 공식 API 가 없다.** `forgetDrivers()`
 *     (Manager.php:170-175)는 `$drivers` 인스턴스만 비우고 creator 는 남긴다.
 *   · 따라서 TeeWide 요청이 끝난 뒤에도 그 드라이버 이름의 생성 경로가 영구히 가로채여,
 *     Laravel 이나 Octane 이 `forgetDrivers()` 로 **새 Store 를 만들려는 동작**이 무력화되고
 *     creator 가 붙잡아 둔 오래된 Store(오래된 id·attributes·started)가 다시 나온다.
 *   · 드라이버 설정이 바뀌어도 최초 handler/store 가 재사용된다.
 *   · TeeWide 가 아닌 요청, 심지어 플러그인 비활성 이후에도 creator 가 계속 관여한다.
 *
 * 그래서 이 스코프는 호스트 매니저에 **아무 것도 설치하지 않는다.** 대신 요청 동안만
 * 쓰고 버리는 `SessionManager` 를 따로 만들어 거기에만 creator 를 달고, 컨테이너의
 * `session` / `session.store` / `StartSession` 바인딩을 잠시 그 쪽으로 돌린다.
 * 요청이 끝나면 원래 인스턴스를 그대로 되돌려 놓는다 — 호스트 매니저의 `$drivers` 도
 * `$customCreators` 도 처음부터 끝까지 손대지 않았으므로 되돌릴 것이 없다.
 *
 * `StartSession` 이 어느 Store 를 받는지도 이 방식으로 명확해진다. 미들웨어 파이프라인은
 * `StartSession::class` 를 컨테이너에서 해석하고(SessionServiceProvider 가 싱글턴으로
 * 바인딩), 그 인스턴스는 생성자에서 받은 매니저를 통해 `driver()` 를 부른다
 * (StartSession.php:37-41, 156-161). 그래서 요청 동안 그 바인딩을 스코프 매니저로 만든
 * 인스턴스로 바꿔 두면 StartSession 은 반드시 TeeWide Store 를 받는다.
 *
 * ── 세션 레코드는 건드리지 않는다 ──────────────────────────────────────────
 * TeeWide Store 는 호스트 Store 와 **같은 handler** 를 쓴다. 저장된 세션 레코드는 handler 가
 * 들고 있으므로 지우거나 옮기지 않으며, teewide.com ↔ live.teewide.com 은 같은 쿠키로 계속
 * 세션을 공유한다. 달라지는 것은 새 Store 의 `attributes` 가 비어 있다는 점뿐이다.
 */
final class TeeWideSessionScope
{
    /** 현재 열려 있는 스코프 깊이 (중첩 호출 대비). */
    private static int $depth = 0;

    public static function depth(): int
    {
        return self::$depth;
    }

    /**
     * 프로세스 전역 상태 초기화 (테스트 격리용).
     *
     * 보유하는 것이 정수 하나뿐이라는 점이 이 설계의 핵심이다 — Store 도 handler 도
     * 매니저도 static 으로 붙들지 않으므로, 새 Application 으로 넘어갈 잔재가 없다.
     */
    public static function reset(): void
    {
        self::$depth = 0;
    }

    /**
     * 요청 범위 스코프를 연다.
     *
     * @param  \Illuminate\Session\Store  $hostStore  원래(YUTIV) Store — handler 만 빌려 쓴다.
     * @return array<string, mixed>  `leave()` 에 그대로 넘길 복원 토큰
     */
    public static function enter(Application $app, Store $hostStore, string $cookie): array
    {
        $driverName = (string) $app['config']->get('session.driver');
        $serialization = (string) $app['config']->get('session.serialization', 'php');

        $store = self::makeStore(
            $hostStore,
            $cookie,
            $serialization,
            $app['config']->get('session.encrypt') ? $app->make('encrypter') : null
        );

        // 쓰고 버리는 매니저 — 여기에만 creator 를 단다. 호스트 매니저는 그대로 둔다.
        $scopedManager = new SessionManager($app);
        $scopedManager->extend($driverName, static fn () => $store);

        $token = [
            'session' => $app->make('session'),
            'session.store' => $app->make('session.store'),
            'start_session' => $app->make(StartSession::class),
            'store' => $store,
        ];

        $app->instance('session', $scopedManager);
        $app->instance('session.store', $store);
        $app->instance(StartSession::class, new StartSession(
            $scopedManager,
            static fn () => $app->make(CacheFactory::class)
        ));

        self::$depth++;

        return $token;
    }

    /**
     * 스코프를 닫고 원래 인스턴스를 **그대로** 되돌린다.
     *
     * 중첩 호출이어도 각 호출이 자기 토큰(지역 변수)을 들고 있으므로, 안쪽이 닫히면
     * 바깥이 설치해 둔 상태로 정확히 돌아간다.
     *
     * @param  array<string, mixed>  $token
     */
    public static function leave(Application $app, array $token): void
    {
        $app->instance('session', $token['session']);
        $app->instance('session.store', $token['session.store']);
        $app->instance(StartSession::class, $token['start_session']);

        if (self::$depth > 0) {
            self::$depth--;
        }
    }

    /**
     * 원래 Store 와 **같은 handler** 를 쓰는 새 Store.
     *
     * handler 를 공유하므로 저장된 세션 레코드를 그대로 읽고 쓴다. 달라지는 것은
     * `attributes` 가 비어 있다는 점뿐 — 그래서 이전 요청 상태를 물려받지 않는다.
     */
    public static function makeStore(Store $hostStore, string $cookie, string $serialization, $encrypter = null): Store
    {
        $handler = $hostStore->getHandler();

        if ($encrypter !== null) {
            return new EncryptedStore($cookie, $handler, $encrypter, null, $serialization);
        }

        return new Store($cookie, $handler, null, $serialization);
    }
}
