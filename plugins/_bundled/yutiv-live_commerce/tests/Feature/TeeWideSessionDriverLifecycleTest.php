<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Plugins\Yutiv\LiveCommerce\Http\Middleware\ConfigureTeeWideSession;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideSessionScope;
use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

/**
 * 세션 드라이버 **수명주기** — 격리가 프레임워크의 정상 동작을 방해하지 않는가.
 *
 * `Manager::$customCreators` 에는 제거 API 가 없다. `forgetDrivers()`(v12.62.0
 * Manager.php:170-175)는 `$drivers` 인스턴스만 비우고 creator 는 남긴다. 그래서 호스트
 * SessionManager 에 creator 를 설치하면 그 드라이버의 생성 경로가 **영구히** 가로채여,
 * 이후 Laravel 이나 Octane 이 새 Store 를 만들려 해도 붙잡아 둔 오래된 Store 가 다시 나온다.
 *
 * 이 스위트는 "격리는 되지만 프레임워크는 그대로 동작한다" 를 못박는다.
 */
class TeeWideSessionDriverLifecycleTest extends PluginTestCase
{
    protected function teeWideBootConfig(): ?array
    {
        return static::teeWideConfigValues();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureTeeWide();
        $this->bootPlugin();
        $this->attachHostGate();
    }

    /** TeeWide 미들웨어를 한 번 태운다. */
    private function runTeeWideRequest(?callable $inside = null): void
    {
        $request = Request::create('http://'.self::ROOT_HOST.'/', 'GET');

        $this->app->make(ConfigureTeeWideSession::class)->handle($request, function () use ($inside) {
            if ($inside !== null) {
                $inside();
            }

            return response('ok');
        });
    }

    // ── 호스트 매니저가 그대로인가 ──────────────────────────────────────────

    public function test_TeeWide_요청_후_forgetDrivers_는_새_Store_를_만든다(): void
    {
        $manager = $this->app->make('session');
        $before = $this->app['session']->driver();

        $this->runTeeWideRequest();

        // 요청이 끝나면 호스트 Store 가 그대로 돌아와야 한다.
        $this->assertSame(spl_object_id($before), spl_object_id($manager->driver()));

        // 그리고 Laravel 이 드라이버를 잊으면 **새 Store** 가 만들어져야 한다.
        // creator 가 오래된 Store 를 붙잡고 있으면 여기서 같은 객체가 나온다.
        $manager->forgetDrivers();
        $after = $manager->driver();

        $this->assertNotSame(
            spl_object_id($before),
            spl_object_id($after),
            'forgetDrivers() 후에도 이전 Store 가 다시 나왔습니다 — creator 가 붙잡고 있습니다.'
        );
    }

    public function test_forgetDrivers_후_새_Store_는_이전_attributes_를_물려받지_않는다(): void
    {
        $manager = $this->app->make('session');

        $manager->driver()->put('stale.attribute', 'from-old-store');

        $this->runTeeWideRequest();

        $manager->forgetDrivers();

        $this->assertNull(
            $manager->driver()->get('stale.attribute'),
            '새로 만들어진 Store 가 이전 Store 의 attributes 를 물려받았습니다.'
        );
    }

    public function test_driver_설정이_바뀌면_이전_handler_를_재사용하지_않는다(): void
    {
        $manager = $this->app->make('session');
        $originalHandler = spl_object_id($manager->driver()->getHandler());

        $this->runTeeWideRequest();

        // 드라이버를 바꾸고 다시 만들면 그 드라이버의 handler 여야 한다.
        config(['session.driver' => 'null']);
        $manager->forgetDrivers();

        $this->assertNotSame(
            $originalHandler,
            spl_object_id($manager->driver()->getHandler()),
            'driver 를 바꿨는데 이전 handler 가 재사용됐습니다.'
        );
    }

    public function test_비활성_스코프에서는_플러그인이_Store_생성에_개입하지_않는다(): void
    {
        $manager = $this->app->make('session');

        $this->runTeeWideRequest();

        // TeeWide 스코프가 닫힌 상태에서 연달아 두 번 새로 만들어 보면,
        // 매번 서로 다른 새 객체가 나와야 한다 (플러그인이 고정값을 돌려주지 않는다).
        $manager->forgetDrivers();
        $first = spl_object_id($manager->driver());

        $manager->forgetDrivers();
        $second = spl_object_id($manager->driver());

        $this->assertNotSame($first, $second, '플러그인 creator 가 고정된 Store 를 돌려주고 있습니다.');
    }

    public function test_StartSession_바인딩이_요청_후_원래_인스턴스로_돌아온다(): void
    {
        $before = spl_object_id($this->app->make(StartSession::class));
        $managerBefore = spl_object_id($this->app->make('session'));

        $seenStartSession = null;
        $seenManager = null;

        $this->runTeeWideRequest(function () use (&$seenStartSession, &$seenManager) {
            $seenStartSession = spl_object_id($this->app->make(StartSession::class));
            $seenManager = spl_object_id($this->app->make('session'));
        });

        $this->assertNotSame($before, $seenStartSession, 'StartSession 이 호스트 매니저를 그대로 썼습니다');
        $this->assertNotSame($managerBefore, $seenManager, '스코프 전용 매니저가 쓰이지 않았습니다');

        $this->assertSame($before, spl_object_id($this->app->make(StartSession::class)),
            'StartSession 바인딩이 복원되지 않았습니다');
        $this->assertSame($managerBefore, spl_object_id($this->app->make('session')),
            'session 매니저 바인딩이 복원되지 않았습니다');
    }

    public function test_스코프_매니저는_요청마다_다른_인스턴스다(): void
    {
        $seen = [];

        foreach ([1, 2] as $ignored) {
            $this->runTeeWideRequest(function () use (&$seen) {
                $seen[] = spl_object_id($this->app->make('session'));
            });
        }

        $this->assertCount(2, $seen);
        $this->assertNotSame($seen[0], $seen[1], '스코프 매니저가 요청 간에 재사용됐습니다');
    }

    // ── 스코프 깊이 ─────────────────────────────────────────────────────────

    public function test_정상_종료_후_스코프_깊이가_0_이다(): void
    {
        $this->assertSame(0, TeeWideSessionScope::depth());

        $this->runTeeWideRequest(function () {
            $this->assertSame(1, TeeWideSessionScope::depth());
        });

        $this->assertSame(0, TeeWideSessionScope::depth());
    }

    public function test_중첩_호출_중_예외가_나도_깊이가_0_으로_복원된다(): void
    {
        $middleware = $this->app->make(ConfigureTeeWideSession::class);
        $outer = Request::create('http://'.self::ROOT_HOST.'/', 'GET');

        try {
            $middleware->handle($outer, function ($r) use ($middleware) {
                $this->assertSame(1, TeeWideSessionScope::depth());

                $middleware->handle($r, function () {
                    $this->assertSame(2, TeeWideSessionScope::depth());

                    throw new \RuntimeException('안쪽 폭발');
                });

                return response('ok');
            });
            $this->fail('예외가 전파되지 않았습니다');
        } catch (\RuntimeException $e) {
            $this->assertSame('안쪽 폭발', $e->getMessage());
        }

        $this->assertSame(0, TeeWideSessionScope::depth(), '예외 후 스코프 깊이가 남았습니다');
    }

    // ── 새 Application 누수 ─────────────────────────────────────────────────

    public function test_새_SessionManager_는_플러그인_creator_없이_동작한다(): void
    {
        $this->runTeeWideRequest();

        // 새 매니저를 직접 만들어 본다 — 플러그인 상태가 전역에 남아 있으면 여기 드러난다.
        $fresh = new SessionManager($this->app);
        $store = $fresh->driver();

        $this->assertSame(
            (string) config('session.cookie'),
            $store->getName(),
            '새 SessionManager 가 TeeWide 이름의 Store 를 돌려줬습니다 — 전역 잔재가 있습니다.'
        );

        $this->assertNotSame(
            spl_object_id($this->app['session']->driver()),
            spl_object_id($store),
            '새 매니저가 기존 Store 를 그대로 돌려줬습니다'
        );
    }

    public function test_YUTIV_TeeWide_YUTIV_TeeWide_경계가_각각_독립적이다(): void
    {
        $manager = $this->app->make('session');
        $hostId = spl_object_id($manager->driver());

        $teeWideIds = [];

        foreach ([1, 2] as $ignored) {
            // YUTIV 구간 — 호스트 Store 그대로
            $this->assertSame($hostId, spl_object_id($manager->driver()));

            $this->runTeeWideRequest(function () use (&$teeWideIds) {
                $teeWideIds[] = spl_object_id($this->app['session']->driver());
            });

            // TeeWide 구간이 끝나면 다시 호스트 Store
            $this->assertSame($hostId, spl_object_id($manager->driver()));
        }

        $this->assertCount(2, $teeWideIds);
        $this->assertNotSame($teeWideIds[0], $teeWideIds[1], 'TeeWide 구간끼리 Store 를 공유했습니다');
        $this->assertNotContains($hostId, $teeWideIds, 'TeeWide 구간이 호스트 Store 를 썼습니다');
    }
}
