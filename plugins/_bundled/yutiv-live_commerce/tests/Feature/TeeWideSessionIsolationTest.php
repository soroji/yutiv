<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use App\Models\User;
use Plugins\Yutiv\LiveCommerce\Http\Middleware\ConfigureTeeWideSession;
use Plugins\Yutiv\LiveCommerce\Providers\LiveCommerceServiceProvider;
use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

/**
 * 세션 격리와 공유.
 *
 * 세 가지를 증명한다.
 *   · TeeWide 요청은 전용 쿠키(`teewide_session`, `.teewide.test`)를 쓴다.
 *   · teewide.test 와 live.teewide.test 는 그 세션을 공유한다.
 *   · yutiv.test 는 TeeWide 세션과 무관하고, 반대로 YUTIV 로그인도 TeeWide 로 새지 않는다.
 */
class TeeWideSessionIsolationTest extends PluginTestCase
{
    /**
     * 이 스위트는 **라우트 우선순위**를 검증하므로 운영과 같은 시점에 부팅해야 한다.
     * 부팅 뒤에 등록하면 `routes/web.php:51` 의 SPA catch-all 이 먼저 등록돼 있어
     * 언제나 그쪽이 이긴다. (PluginTestCase::createApplication 주석 참조)
     */
    protected function teeWideBootConfig(): ?array
    {
        return static::teeWideConfigValues();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 라우트는 이미 부팅 시점에 올라와 있다. 설정은 부팅 때 쓴 값을 그대로 되풀이해
        // 부팅 후 코드(미들웨어·컨트롤러)도 같은 값을 읽게 한다.
        $this->configureTeeWide();
        $this->bootPlugin();
        $this->attachHostGate();
    }

    // ── 쿠키 이름·도메인 ────────────────────────────────────────────────────

    public function test_TeeWide_요청은_전용_세션_쿠키를_쓴다(): void
    {
        $this->get('http://'.self::ROOT_HOST.'/')
            ->assertOk()
            ->assertJsonPath('session_cookie', 'teewide_session')
            ->assertJsonPath('session_domain', '.teewide.test')
            ->assertJsonPath('session_configured', true);
    }

    public function test_라이브_호스트도_같은_세션_설정을_쓴다(): void
    {
        $this->get('http://'.self::LIVE_HOST.'/golfif')
            ->assertOk()
            ->assertJsonPath('session_cookie', 'teewide_session')
            ->assertJsonPath('session_domain', '.teewide.test');
    }

    public function test_YUTIV_세션_설정은_변경되지_않는다(): void
    {
        $originalCookie = config('session.cookie');
        $originalDomain = config('session.domain');

        // TeeWide 요청을 한 번 처리한 뒤에도 기본 설정이 되돌아와야 한다.
        // (config 변경은 요청 범위이며, 다음 요청에 남지 않는다)
        $this->get('http://'.self::ROOT_HOST.'/')->assertOk();

        $this->assertSame($originalCookie, config('session.cookie'));
        $this->assertSame($originalDomain, config('session.domain'));
    }

    public function test_YUTIV_요청은_TeeWide_쿠키_설정을_받지_않는다(): void
    {
        // yutiv 호스트로 들어온 요청에서 미들웨어를 직접 태워도 설정이 바뀌지 않아야 한다.
        $request = \Illuminate\Http\Request::create('http://'.self::YUTIV_HOST.'/', 'GET');
        $before = config('session.cookie');

        (new ConfigureTeeWideSession)->handle($request, fn ($r) => response('ok'));

        $this->assertSame($before, config('session.cookie'));
        $this->assertFalse($request->attributes->get('teewide.session_configured', false));
    }

    // ── 서브도메인 간 공유 ──────────────────────────────────────────────────

    public function test_teewide_에서_기록한_세션을_live_에서_읽는다(): void
    {
        // teewide.test 에서 표식을 기록한다.
        $write = $this->get('http://'.self::ROOT_HOST.'/_teewide/session?write=phase0-marker');
        $write->assertOk()->assertJsonPath('marker', 'phase0-marker');

        $sessionId = $write->getCookie('teewide_session', false)?->getValue();
        $this->assertNotNull($sessionId, 'TeeWide 세션 쿠키가 발급되지 않았습니다');

        // 같은 쿠키를 live.teewide.test 로 보낸다 — 같은 세션이어야 한다.
        $read = $this->withUnencryptedCookie('teewide_session', $sessionId)
            ->get('http://'.self::LIVE_HOST.'/_teewide/session');

        $read->assertOk()->assertJsonPath('marker', 'phase0-marker');
    }

    public function test_TeeWide_쿠키_도메인이_서브도메인_공유를_허용한다(): void
    {
        $response = $this->get('http://'.self::ROOT_HOST.'/_teewide/session?write=x');

        $cookie = $response->getCookie('teewide_session', false);

        $this->assertNotNull($cookie);
        $this->assertSame('.teewide.test', $cookie->getDomain(), '쿠키 도메인이 서브도메인 공유 형태가 아닙니다');
    }

    // ── YUTIV ↔ TeeWide 격리 ────────────────────────────────────────────────

    public function test_TeeWide_쿠키를_yutiv_요청에_넣어도_TeeWide_사용자가_아니다(): void
    {
        $write = $this->get('http://'.self::ROOT_HOST.'/_teewide/session?write=leak-check');
        $sessionId = $write->getCookie('teewide_session', false)?->getValue();
        $this->assertNotNull($sessionId);

        // 같은 쿠키를 yutiv 호스트로 보낸다 — TeeWide 라우트가 없으므로 도달할 수 없다.
        $response = $this->withUnencryptedCookie('teewide_session', $sessionId)
            ->get('http://'.self::YUTIV_HOST.'/_teewide/session');

        $this->assertNotSame(200, $response->getStatusCode(), 'yutiv 호스트에서 TeeWide 진단이 응답했습니다');
    }

    public function test_YUTIV_로그인_세션이_TeeWide_사용자로_인정되지_않는다(): void
    {
        $user = User::factory()->create();

        // YUTIV 세션으로 로그인한 상태에서 TeeWide 진단을 호출한다.
        $response = $this->actingAs($user)
            ->get('http://'.self::ROOT_HOST.'/_teewide/session');

        $response->assertOk();

        // TeeWide 는 전용 guard 를 쓸 예정이므로(Phase 2), 지금은 기존 web guard 사용자가
        // 새어 들어오지 않는다는 사실만 고정한다.
        $response->assertJsonPath('yutiv_user_leaked', false);
    }

    // ── 미들웨어 순서 (설계 계약) ───────────────────────────────────────────

    public function test_세션_설정_미들웨어가_StartSession_보다_먼저_선언된다(): void
    {
        $routes = $this->teeWideRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $stack = $route->middleware();

            $configureAt = array_search(ConfigureTeeWideSession::class, $stack, true);
            $startAt = array_search(\Illuminate\Session\Middleware\StartSession::class, $stack, true);

            $this->assertNotFalse($configureAt, $route->getName().' 에 세션 설정 미들웨어가 없습니다');
            $this->assertNotFalse($startAt, $route->getName().' 에 StartSession 이 없습니다');
            $this->assertLessThan(
                $startAt,
                $configureAt,
                $route->getName().' — 세션 설정이 StartSession 뒤에 있습니다. 쿠키 이름이 적용되지 않습니다.'
            );
        }
    }

    public function test_TeeWide_스택은_YUTIV_web_그룹을_쓰지_않는다(): void
    {
        // web 그룹을 쓰면 SetLocale·Boost·확장 게이트 등 YUTIV 전처리가 끼어든다.
        foreach ($this->teeWideRoutes() as $route) {
            $this->assertNotContains('web', $route->middleware(), $route->getName());
        }
    }

    public function test_프로바이더가_YUTIV_세션_설정을_건드리지_않는다(): void
    {
        $source = (string) file_get_contents(
            base_path('plugins/_bundled/yutiv-live_commerce/src/Providers/LiveCommerceServiceProvider.php')
        );

        // 프로바이더는 세션 config 를 전역으로 바꾸지 않는다 — 요청 미들웨어에서만 바꾼다.
        $this->assertStringNotContainsString("config(['session.", $source);
        $this->assertStringNotContainsString("'session.cookie' =>", $source);
    }

    public function test_라우트가_전부_프로바이더_한_곳에서만_등록된다(): void
    {
        $this->assertTrue(
            method_exists(LiveCommerceServiceProvider::class, 'boot'),
            '프로바이더 boot 이 없습니다'
        );

        // src/routes/*.php 를 쓰면 코어 로더가 plugins/{id} 프리픽스를 강제해
        // 도메인 루트 라우트를 만들 수 없다. 그 파일이 없어야 한다.
        $this->assertFalse(
            is_dir(base_path('plugins/_bundled/yutiv-live_commerce/src/routes')),
            'src/routes 가 있으면 코어 로더가 프리픽스를 강제해 도메인 라우트와 충돌합니다.'
        );
    }
}
