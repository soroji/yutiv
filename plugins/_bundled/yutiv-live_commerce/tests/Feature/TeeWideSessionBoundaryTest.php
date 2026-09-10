<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use App\Models\User;
use Illuminate\Http\Request;
use Plugins\Yutiv\LiveCommerce\Http\Middleware\ConfigureTeeWideSession;
use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

/**
 * YUTIV ↔ TeeWide 세션 **경계** — 속성·인증 상태가 서로 넘어가지 않는가.
 *
 * 쿠키 이름 격리(TeeWideSessionScopeTest)와는 다른 층위다. 같은 Application 을 재사용하면
 * `SessionManager` 가 캐시한 하나의 `Store` 를 두 호스트가 나눠 쓰게 되고,
 * `Store::loadSession()` 은 `array_replace($this->attributes, ...)` 라 **이전 요청의 속성을
 * 지우지 않는다**(v12.62.0 Store.php:114-119). 그래서 쿠키를 아무리 갈라도 객체가 같으면
 * YUTIV 로그인 키가 TeeWide 요청까지 따라온다.
 *
 * 이 스위트는 그 경계를 객체 수준에서 고정한다.
 */
class TeeWideSessionBoundaryTest extends PluginTestCase
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

    /** SessionGuard 가 세션에 쓰는 로그인 키 (PluginTestCase 정의를 그대로 쓴다). */
    private function loginKey(): string
    {
        return $this->yutivLoginSessionKey();
    }

    // ── 양방향 누수 ─────────────────────────────────────────────────────────

    public function test_YUTIV_로그인_세션이_TeeWide_요청으로_넘어가지_않는다(): void
    {
        $user = User::factory()->create();
        $yutivCookie = (string) config('session.cookie');
        $yutivSessionId = $this->makeYutivLoginSession($user);

        $response = $this->withUnencryptedCookie($yutivCookie, $yutivSessionId)
            ->get('http://'.self::ROOT_HOST.'/_teewide/session');

        $response->assertOk();
        $response->assertJsonPath('session_cookie', 'teewide_session');

        $this->assertFalse(
            $response->json('yutiv_user_leaked'),
            sprintf(
                'YUTIV 로그인(%s = %s, 쿠키 %s)이 TeeWide 사용자로 인정됐습니다.',
                $this->loginKey(),
                $user->getAuthIdentifier(),
                $yutivCookie
            )
        );
    }

    public function test_TeeWide_세션_속성이_YUTIV_요청으로_넘어가지_않는다(): void
    {
        // TeeWide 세션에 표식을 남긴다.
        $this->get('http://'.self::ROOT_HOST.'/_teewide/session?write=boundary-marker')
            ->assertOk()
            ->assertJsonPath('marker', 'boundary-marker');

        // 이어서 YUTIV 요청을 처리한 뒤, YUTIV 쪽 세션에 그 표식이 없어야 한다.
        $this->get('http://'.self::YUTIV_HOST.'/');

        $this->assertNull(
            $this->app['session']->driver()->get('teewide.marker'),
            'TeeWide 세션 표식이 YUTIV 세션으로 넘어왔습니다'
        );
    }

    public function test_TeeWide_요청은_YUTIV_세션_속성을_상속하지_않는다(): void
    {
        // 쿠키가 없어도(새 세션이어도) 이전 요청의 속성을 물려받으면 안 된다.
        $host = $this->app['session']->driver();
        $host->put('yutiv.only', 'must-not-cross');
        $host->save();

        $request = Request::create('http://'.self::ROOT_HOST.'/_teewide/session', 'GET');
        $seen = 'sentinel';

        $this->app->make(ConfigureTeeWideSession::class)->handle($request, function () use (&$seen) {
            $store = $this->app['session']->driver();
            $store->start();
            $seen = $store->get('yutiv.only');

            return response('ok');
        });

        $this->assertNull($seen, 'TeeWide 요청이 YUTIV 세션 속성을 물려받았습니다');

        // 그렇다고 YUTIV 쪽 데이터를 지운 것도 아니어야 한다.
        $this->assertSame('must-not-cross', $this->app['session']->driver()->get('yutiv.only'),
            'YUTIV 세션 속성이 사라졌습니다 — 격리가 데이터를 지우면 안 됩니다');
    }

    // ── Store 객체 정체 ─────────────────────────────────────────────────────

    public function test_TeeWide_요청은_YUTIV_와_다른_Store_객체를_쓴다(): void
    {
        $hostStoreId = spl_object_id($this->app['session']->driver());

        $request = Request::create('http://'.self::ROOT_HOST.'/', 'GET');
        $seen = null;

        $this->app->make(ConfigureTeeWideSession::class)->handle($request, function ($r) use (&$seen) {
            $seen = spl_object_id($this->app['session']->driver());

            return response('ok');
        });

        $this->assertNotNull($seen);
        $this->assertNotSame($hostStoreId, $seen, 'TeeWide 요청이 YUTIV Store 객체를 재사용했습니다');

        // 요청이 끝나면 원래 Store 로 돌아와야 한다.
        $this->assertSame(
            $hostStoreId,
            spl_object_id($this->app['session']->driver()),
            'TeeWide 요청 후 원래 Store 로 복원되지 않았습니다'
        );
    }

    public function test_TeeWide_요청마다_새_Store_객체가_만들어진다(): void
    {
        $ids = [];

        foreach ([1, 2] as $ignored) {
            $request = Request::create('http://'.self::ROOT_HOST.'/', 'GET');
            $this->app->make(ConfigureTeeWideSession::class)->handle($request, function () use (&$ids) {
                $ids[] = spl_object_id($this->app['session']->driver());

                return response('ok');
            });
        }

        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1], '연속 TeeWide 요청이 같은 Store 객체를 재사용했습니다');
    }

    // ── 저장소는 보존된다 ───────────────────────────────────────────────────

    public function test_객체는_달라도_세션_ID_기반_데이터는_유지된다(): void
    {
        $write = $this->get('http://'.self::ROOT_HOST.'/_teewide/session?write=persisted');
        $write->assertOk()->assertJsonPath('marker', 'persisted');

        $sessionId = $write->getCookie('teewide_session', false)?->getValue();
        $this->assertNotNull($sessionId);

        // 새 Store 객체가 만들어져도, 같은 쿠키면 핸들러에서 같은 레코드를 읽는다.
        $read = $this->withUnencryptedCookie('teewide_session', $sessionId)
            ->get('http://'.self::LIVE_HOST.'/_teewide/session');

        $read->assertOk()->assertJsonPath('marker', 'persisted');
    }

    public function test_TeeWide_요청_뒤에도_YUTIV_로그인_세션_레코드가_남아_있다(): void
    {
        $user = User::factory()->create();
        $yutivSessionId = $this->makeYutivLoginSession($user);

        $this->get('http://'.self::ROOT_HOST.'/_teewide/session?write=x')->assertOk();

        // 세션 레코드를 지우거나 flush 하지 않았는지 — 같은 ID 로 다시 읽어 확인한다.
        $store = $this->app['session']->driver();
        $store->setId($yutivSessionId);
        $store->start();

        $this->assertSame(
            $user->getAuthIdentifier(),
            $store->get($this->loginKey()),
            'TeeWide 요청이 YUTIV 로그인 세션 레코드를 훼손했습니다'
        );
    }

    // ── 예외 경로 ───────────────────────────────────────────────────────────

    public function test_예외가_나도_Store_와_guard_가_복원된다(): void
    {
        $hostStoreId = spl_object_id($this->app['session']->driver());
        $hostBinding = spl_object_id($this->app->make('session.store'));
        $hostCookie = config('session.cookie');

        $request = Request::create('http://'.self::ROOT_HOST.'/', 'GET');

        try {
            $this->app->make(ConfigureTeeWideSession::class)->handle($request, function () {
                throw new \RuntimeException('컨트롤러 폭발');
            });
            $this->fail('예외가 전파되지 않았습니다');
        } catch (\RuntimeException $e) {
            $this->assertSame('컨트롤러 폭발', $e->getMessage());
        }

        $this->assertSame($hostStoreId, spl_object_id($this->app['session']->driver()),
            '예외 후 Store 가 복원되지 않았습니다');
        $this->assertSame($hostBinding, spl_object_id($this->app->make('session.store')),
            '예외 후 session.store 바인딩이 복원되지 않았습니다');
        $this->assertSame($hostCookie, config('session.cookie'), '예외 후 설정이 복원되지 않았습니다');
    }
}
