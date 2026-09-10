<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

/**
 * `TEEWIDE_DIAGNOSTICS=false` 계약.
 *
 * ── Phase 0 과 달라진 점 ───────────────────────────────────────────────────
 * Phase 0 에서는 이 스위치를 끄면 **화면까지 전부** 사라졌다. 진단 스위치가 서비스를
 * 끄는 구조는 옳지 않으므로, 이제 이 값은 `/_teewide/session` 만 켜고 끈다.
 *
 * 라우트 등록 여부를 보는 검사이므로, 설정만 바꾸고 이미 등록된 라우트를 그대로 두면
 * 아무 것도 증명하지 못한다. 그래서 이 스위트는 **부팅 시점부터** 진단을 끈 설정으로
 * 앱을 만든다 (`teeWideBootConfig()`).
 */
class TeeWideDiagnosticsSwitchTest extends PluginTestCase
{
    protected function teeWideBootConfig(): ?array
    {
        return static::teeWideConfigValues(['diagnostics_enabled' => false]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 부팅 때 쓴 값을 부팅 후 코드에도 그대로 반영한다.
        $this->configureTeeWide(['diagnostics_enabled' => false]);
        $this->bootPlugin();
        $this->attachHostGate();
    }

    public function test_진단이_꺼져도_제품_화면_세_개는_정상이다(): void
    {
        foreach ([
            'http://'.self::ROOT_HOST.'/' => 'teewide.portal',
            'http://'.self::LIVE_HOST.'/' => 'teewide.live.home',
            'http://'.self::LIVE_HOST.'/golfif' => 'teewide.live.tenant',
        ] as $url => $routeName) {
            $this->assertSame($routeName, $this->matchedRouteName($url), $this->routingDiagnostics($url));

            $response = $this->get($url);

            $response->assertOk();
            $response->assertHeader('content-type', 'text/html; charset=UTF-8');
        }
    }

    public function test_진단이_꺼지면_session_엔드포인트가_404(): void
    {
        foreach ([self::ROOT_HOST, self::LIVE_HOST] as $host) {
            $url = 'http://'.$host.'/_teewide/session';

            $this->assertNull($this->matchedRouteName($url), $url.' 진단 라우트가 여전히 등록돼 있습니다');
            $this->get($url)->assertNotFound();
        }
    }

    public function test_진단이_꺼지면_제품_라우트만_등록된다(): void
    {
        $names = [];
        foreach ($this->teeWideRoutes() as $route) {
            $names[] = $route->getName();
        }
        sort($names);

        $this->assertSame(self::teeWideProductRouteNames(), $names);
    }

    public function test_진단이_꺼져도_미등록_tenant_는_404(): void
    {
        $this->get('http://'.self::LIVE_HOST.'/unknown-tenant')->assertNotFound();
    }

    public function test_진단이_꺼져도_세션_쿠키_격리는_유지된다(): void
    {
        // 진단 JSON 없이도 응답 쿠키로 확인할 수 있다.
        $response = $this->get('http://'.self::ROOT_HOST.'/');

        $response->assertOk();

        $cookie = $response->getCookie('teewide_session', false);
        $this->assertNotNull($cookie, 'TeeWide 전용 세션 쿠키가 발급되지 않았습니다');
        $this->assertSame('.teewide.test', $cookie->getDomain());
    }
}
