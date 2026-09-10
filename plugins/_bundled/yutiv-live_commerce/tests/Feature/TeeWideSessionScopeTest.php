<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Illuminate\Http\Request;
use Plugins\Yutiv\LiveCommerce\Http\Middleware\ConfigureTeeWideSession;
use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

/**
 * 세션 설정의 **요청 범위** 격리.
 *
 * `ConfigureTeeWideSession` 은 전역 `config('session.*')` 를 바꾼다. 되돌리지 않으면 같은
 * Application 을 재사용하는 실행 모델(queue worker · Octane · RoadRunner, 그리고 이 테스트
 * 앱)에서 다음 YUTIV 요청이 TeeWide 쿠키를 자기 세션 쿠키로 읽는다.
 *
 * 복원 책임은 **미들웨어**에 있다. 테스트 tearDown 이 치워 주는 방식이면 운영에서는
 * 아무도 치우지 않으므로, 여기서는 미들웨어만 태우고 결과를 본다.
 */
class TeeWideSessionScopeTest extends PluginTestCase
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

    /**
     * 관심 있는 세션 설정 키 전부를 한 번에 찍는다.
     *
     * @return array<string, mixed>
     */
    private function sessionConfigSnapshot(): array
    {
        return [
            'cookie' => config('session.cookie'),
            'domain' => config('session.domain'),
            'path' => config('session.path'),
            'secure' => config('session.secure'),
            'same_site' => config('session.same_site'),
            'http_only' => config('session.http_only'),
        ];
    }

    // ── 요청 순서별 복원 ────────────────────────────────────────────────────

    public function test_YUTIV_TeeWide_YUTIV_순서에서_설정이_복원된다(): void
    {
        $before = $this->sessionConfigSnapshot();

        $this->get('http://'.self::YUTIV_HOST.'/');
        $this->assertSame($before, $this->sessionConfigSnapshot(), 'YUTIV 요청이 세션 설정을 바꿨습니다');

        $this->get('http://'.self::ROOT_HOST.'/')->assertOk();
        $this->assertSame($before, $this->sessionConfigSnapshot(), 'TeeWide 요청 후 설정이 복원되지 않았습니다');

        $this->get('http://'.self::YUTIV_HOST.'/');
        $this->assertSame($before, $this->sessionConfigSnapshot());
    }

    public function test_TeeWide_live_YUTIV_순서에서_설정이_복원된다(): void
    {
        $before = $this->sessionConfigSnapshot();

        $this->get('http://'.self::ROOT_HOST.'/')->assertOk();
        $this->get('http://'.self::LIVE_HOST.'/golfif')->assertOk();

        $this->assertSame($before, $this->sessionConfigSnapshot(), '라이브 호스트 요청 후 복원되지 않았습니다');

        $this->get('http://'.self::YUTIV_HOST.'/');
        $this->assertSame($before, $this->sessionConfigSnapshot());
    }

    public function test_TeeWide_요청_중_예외가_나도_설정이_복원된다(): void
    {
        $before = $this->sessionConfigSnapshot();

        $request = Request::create('http://'.self::ROOT_HOST.'/', 'GET');
        $middleware = $this->app->make(ConfigureTeeWideSession::class);

        try {
            $middleware->handle($request, function () {
                throw new \RuntimeException('컨트롤러 폭발');
            });
            $this->fail('예외가 전파되지 않았습니다');
        } catch (\RuntimeException $e) {
            $this->assertSame('컨트롤러 폭발', $e->getMessage());
        }

        $this->assertSame(
            $before,
            $this->sessionConfigSnapshot(),
            '예외 경로에서 세션 설정이 복원되지 않았습니다 — finally 가 없습니다.'
        );
    }

    // ── 원래 값의 정확한 보존 ───────────────────────────────────────────────

    public function test_원래_설정의_null_빈문자열_false_가_그대로_보존된다(): void
    {
        // 되돌릴 때 "없던 키" · null · '' · false 를 뭉뚱그리면 안 된다.
        config([
            'session.domain' => null,
            'session.path' => '',
            'session.secure' => false,
            'session.same_site' => null,
        ]);

        $before = $this->sessionConfigSnapshot();

        $request = Request::create('http://'.self::ROOT_HOST.'/', 'GET');
        $this->app->make(ConfigureTeeWideSession::class)
            ->handle($request, fn () => response('ok'));

        $after = $this->sessionConfigSnapshot();

        $this->assertSame($before, $after);
        $this->assertNull($after['domain'], 'null 이 다른 값으로 바뀌었습니다');
        $this->assertSame('', $after['path'], '빈 문자열이 다른 값으로 바뀌었습니다');
        $this->assertFalse($after['secure'], 'false 가 다른 값으로 바뀌었습니다');
        $this->assertNull($after['same_site']);
    }

    public function test_원래_없던_키는_되살아나지_않는다(): void
    {
        $session = config('session');
        unset($session['partitioned']);
        config(['session' => $session]);

        $this->assertArrayNotHasKey('partitioned', config('session'));

        $request = Request::create('http://'.self::ROOT_HOST.'/', 'GET');
        $this->app->make(ConfigureTeeWideSession::class)
            ->handle($request, fn () => response('ok'));

        $this->assertArrayNotHasKey(
            'partitioned',
            config('session'),
            '원래 없던 키가 복원 과정에서 생겼습니다'
        );
    }

    public function test_중첩_호출이_바깥_스냅샷을_훼손하지_않는다(): void
    {
        $before = $this->sessionConfigSnapshot();

        $request = Request::create('http://'.self::ROOT_HOST.'/', 'GET');
        $middleware = $this->app->make(ConfigureTeeWideSession::class);

        $inner = null;
        $middleware->handle($request, function ($r) use ($middleware, &$inner) {
            $outerCookie = config('session.cookie');

            $middleware->handle($r, function () use (&$inner) {
                $inner = config('session.cookie');

                return response('ok');
            });

            // 안쪽 호출이 끝나면 바깥이 설정한 값으로 돌아와야 한다.
            $this->assertSame($outerCookie, config('session.cookie'));

            return response('ok');
        });

        $this->assertSame('teewide_session', $inner);
        $this->assertSame($before, $this->sessionConfigSnapshot());
    }

    // ── 응답 쿠키 ───────────────────────────────────────────────────────────

    public function test_TeeWide_응답은_전용_쿠키_이름과_도메인으로_발급한다(): void
    {
        $response = $this->get('http://'.self::ROOT_HOST.'/_teewide/session?write=scope');

        $response->assertOk();

        $cookie = $response->getCookie('teewide_session', false);
        $this->assertNotNull($cookie, 'TeeWide 응답이 전용 세션 쿠키를 발급하지 않았습니다');
        $this->assertSame('.teewide.test', $cookie->getDomain());

        // YUTIV 쿠키 이름으로는 발급하지 않는다.
        $this->assertNull(
            $response->getCookie('g7-session', false),
            'TeeWide 응답이 YUTIV 세션 쿠키까지 발급했습니다'
        );
    }

    public function test_TeeWide_요청_직후_YUTIV_응답은_YUTIV_쿠키_이름을_쓴다(): void
    {
        $yutivCookie = (string) config('session.cookie');

        $this->get('http://'.self::ROOT_HOST.'/_teewide/session?write=scope')->assertOk();

        $response = $this->get('http://'.self::YUTIV_HOST.'/');

        $this->assertNull(
            $response->getCookie('teewide_session', false),
            'YUTIV 응답이 TeeWide 세션 쿠키를 발급했습니다 — 세션 스토어 이름이 복원되지 않았습니다.'
        );
        $this->assertNotNull(
            $response->getCookie($yutivCookie, false),
            'YUTIV 응답이 자기 세션 쿠키('.$yutivCookie.')를 발급하지 않았습니다'
        );
    }

    // ── 유지되어야 하는 것 ──────────────────────────────────────────────────

    public function test_연속_TeeWide_요청끼리는_세션을_계속_공유한다(): void
    {
        // 격리를 넣었다고 해서 TeeWide 끼리의 공유가 깨지면 안 된다.
        $write = $this->get('http://'.self::ROOT_HOST.'/_teewide/session?write=still-shared');
        $write->assertOk()->assertJsonPath('marker', 'still-shared');

        $sessionId = $write->getCookie('teewide_session', false)?->getValue();
        $this->assertNotNull($sessionId);

        $read = $this->withUnencryptedCookie('teewide_session', $sessionId)
            ->get('http://'.self::LIVE_HOST.'/_teewide/session');

        $read->assertOk()->assertJsonPath('marker', 'still-shared');
    }
}
