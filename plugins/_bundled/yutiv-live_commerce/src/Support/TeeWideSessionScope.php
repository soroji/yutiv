<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Session\EncryptedStore;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use LogicException;
use SessionHandlerInterface;

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
 * `Manager::$customCreators` 를 **제거하는 공식 API 가 없다.** `forgetDrivers()`
 * (Manager.php:170-175)는 `$drivers` 인스턴스만 비우고 creator 는 남긴다. 호스트 매니저에
 * creator 를 설치하면 그 드라이버의 생성 경로가 영구히 가로채여, 이후 Laravel 이나 Octane 이
 * `forgetDrivers()` 로 새 Store 를 만들려 해도 붙잡아 둔 오래된 Store 가 다시 나온다.
 *
 * 그래서 이 스코프는 호스트 매니저에 **아무 것도 설치하지 않는다.** 요청 동안만 쓰고 버리는
 * `SessionManager` 를 따로 만들어 거기에만 creator 를 달고, 컨테이너의 `session` /
 * `session.store` / `StartSession` 바인딩을 잠시 그 쪽으로 돌린 뒤 원래 인스턴스를 되돌린다.
 *
 * ── custom creator 가 무엇을 반환해야 하는가 ───────────────────────────────
 * ⚠ 여기에 함정이 있다. `SessionManager` 는 `callCustomCreator()` 를 **오버라이드**한다
 * (SessionManager.php:18-21):
 *
 *     protected function callCustomCreator($driver)
 *     {
 *         return $this->buildSession(parent::callCustomCreator($driver));
 *     }
 *
 * 즉 콜백의 반환값은 완성된 Store 가 **아니라** `buildSession($handler)` 에 넘길
 * `SessionHandlerInterface` 다(SessionManager.php:190-200). 콜백이 Store 를 돌려주면
 * `new Store($cookie, $store, ...)` 가 되어 `Store::__construct()` 의 타입 선언
 * (Store.php:85, `SessionHandlerInterface $handler`)에 걸려 TypeError 가 난다 —
 * 서버 6차 실행의 24건이 정확히 이것이었다.
 *
 * 그래서 콜백은 **handler 를 돌려주고**, Store 는 Laravel 의 `buildSession()` /
 * `buildEncryptedSession()` 이 직접 만들게 둔다. 그러면 `session.encrypt`,
 * `session.serialization`, 쿠키 이름이 프레임워크와 완전히 같은 규칙으로 처리된다.
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
     * 호출 전에 TeeWide `session.*` 설정이 이미 적용돼 있어야 한다 — Laravel 이
     * `buildSession()` 에서 그 설정을 읽어 Store 를 만들기 때문이다.
     *
     * @param  \Illuminate\Session\Store  $hostStore  원래(YUTIV) Store — handler 만 빌려 쓴다.
     * @param  string  $cookie  기대하는 TeeWide 쿠키 이름 (검증용)
     * @return array<string, mixed>  `leave()` 에 그대로 넘길 복원 토큰
     */
    public static function enter(Application $app, Store $hostStore, string $cookie): array
    {
        $driverName = (string) $app['config']->get('session.driver');
        $handler = $hostStore->getHandler();

        if (! $handler instanceof SessionHandlerInterface) {
            throw new LogicException(
                'TeeWide 세션 격리: 호스트 Store 의 handler 가 SessionHandlerInterface 가 아닙니다 — '
                .get_debug_type($handler)
            );
        }

        // 쓰고 버리는 매니저 — 여기에만 creator 를 단다. 호스트 매니저는 그대로 둔다.
        //
        // 콜백은 **handler** 를 돌려준다. SessionManager::callCustomCreator() 가 그 결과를
        // buildSession() 에 넘겨 Store 를 만든다. 여기서 Store 를 돌려주면 이중 build 가
        // 일어나 TypeError 가 난다 (클래스 주석 참조).
        $scopedManager = new SessionManager($app);
        $scopedManager->extend($driverName, static fn () => $handler);

        $teeWideStore = $scopedManager->driver($driverName);

        self::assertScopedStore($teeWideStore, $hostStore, $handler, $cookie, (bool) $app['config']->get('session.encrypt'));

        $token = [
            'session' => $app->make('session'),
            'session.store' => $app->make('session.store'),
            'start_session' => $app->make(StartSession::class),
            'store' => $teeWideStore,
        ];

        $app->instance('session', $scopedManager);
        $app->instance('session.store', $teeWideStore);
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
     * Laravel 이 만들어 준 Store 가 기대한 모양인지 확인한다.
     *
     * 여기서 틀리면 세션 경계가 조용히 무너지므로, 500 까지 흘려보내지 않고 무엇이
     * 어긋났는지 말하는 예외로 즉시 끊는다.
     */
    private static function assertScopedStore(
        mixed $store,
        Store $hostStore,
        SessionHandlerInterface $handler,
        string $cookie,
        bool $encrypt
    ): void {
        if (! $store instanceof Store) {
            throw new LogicException(
                'TeeWide 세션 격리: 스코프 매니저가 Store 를 만들지 않았습니다 — '.get_debug_type($store)
                .'. custom creator 는 SessionHandlerInterface 를 반환해야 합니다'
                .' (SessionManager::callCustomCreator 가 buildSession 에 넘깁니다).'
            );
        }

        if ($store === $hostStore) {
            throw new LogicException('TeeWide 세션 격리: 호스트 Store 가 그대로 반환됐습니다 — 세션이 분리되지 않습니다.');
        }

        if ($store->getHandler() !== $handler) {
            throw new LogicException(
                'TeeWide 세션 격리: 전용 Store 가 호스트 handler 를 쓰지 않습니다 — 세션 레코드를 읽지 못합니다.'
            );
        }

        if ($encrypt && ! $store instanceof EncryptedStore) {
            throw new LogicException('TeeWide 세션 격리: session.encrypt 가 켜져 있는데 EncryptedStore 가 아닙니다.');
        }

        if (! $encrypt && $store instanceof EncryptedStore) {
            throw new LogicException('TeeWide 세션 격리: session.encrypt 가 꺼져 있는데 EncryptedStore 가 만들어졌습니다.');
        }

        if ($store->getName() !== $cookie) {
            throw new LogicException(sprintf(
                'TeeWide 세션 격리: 전용 Store 의 쿠키 이름이 %s 여야 하는데 %s 입니다.',
                $cookie,
                (string) $store->getName()
            ));
        }
    }
}
