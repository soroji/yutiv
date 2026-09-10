<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Illuminate\Http\Request;
use Plugins\Yutiv\LiveCommerce\Http\Middleware\TeeWideHostGate;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideHost;
use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

/**
 * 호스트 게이트 판정과 Host 정규화.
 *
 * HTTP 를 거치지 않고 미들웨어를 직접 태운다 — 판정 로직 자체를 못박기 위해서다.
 * (HTTP 레벨 차단은 TeeWideDomainRoutingTest 가 따로 검사한다)
 */
class TeeWideHostGateTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureTeeWide();
    }

    /**
     * 주어진 호스트·라우트이름으로 게이트를 태우고 통과 여부를 돌려준다.
     */
    private function passesGate(string $host, ?string $routeName): bool
    {
        $request = Request::create('http://'.$host.'/whatever', 'GET');

        // 매칭된 라우트를 흉내 낸다 — 게이트는 라우트 이름으로 소유를 판정한다.
        $route = new \Illuminate\Routing\Route(['GET'], '/whatever', ['uses' => fn () => null]);
        if ($routeName !== null) {
            $route->name($routeName);
        }
        $request->setRouteResolver(fn () => $route);

        $passed = false;
        (new TeeWideHostGate)->handle($request, function ($r) use (&$passed) {
            $passed = true;

            return response('ok');
        });

        return $passed;
    }

    // ── 판정 매트릭스 ───────────────────────────────────────────────────────

    public function test_TeeWide_호스트_TeeWide_라우트는_통과(): void
    {
        $this->assertTrue($this->passesGate(self::ROOT_HOST, 'teewide.portal'));
        $this->assertTrue($this->passesGate(self::LIVE_HOST, 'teewide.live.tenant'));
    }

    public function test_TeeWide_호스트_비TeeWide_라우트는_차단(): void
    {
        $this->assertFalse($this->passesGate(self::ROOT_HOST, 'api.search'));
        $this->assertFalse($this->passesGate(self::ROOT_HOST, null), '무명 라우트(SPA 셸)가 통과했습니다');
        $this->assertFalse($this->passesGate(self::LIVE_HOST, 'api.admin.users.index'));
    }

    public function test_비TeeWide_호스트_비TeeWide_라우트는_통과(): void
    {
        $this->assertTrue($this->passesGate(self::YUTIV_HOST, 'api.search'));
        $this->assertTrue($this->passesGate(self::YUTIV_HOST, null));
    }

    public function test_비TeeWide_호스트_TeeWide_라우트는_차단(): void
    {
        // 도메인 제약상 일어날 수 없지만 등록 실수에 대한 심층 방어다.
        $this->assertFalse($this->passesGate(self::YUTIV_HOST, 'teewide.portal'));
    }

    public function test_위조_Host_는_TeeWide_로_인정되지_않는다(): void
    {
        foreach ([
            'evil.example.com',
            'evil-teewide.test',
            'teewide.test.attacker.net',
            'teewide.test.evil',
        ] as $host) {
            $this->assertTrue(
                $this->passesGate($host, 'api.search'),
                $host.' 가 TeeWide 호스트로 오인됐습니다'
            );
        }
    }

    // ── Host 정규화 ─────────────────────────────────────────────────────────

    public function test_포트가_붙어도_canonical_호스트로_판정한다(): void
    {
        $this->assertSame('teewide.com', TeeWideHost::canonical('teewide.com:8443'));
        $this->assertSame('teewide.com', TeeWideHost::canonical('teewide.com:80'));
    }

    public function test_대소문자와_후행점을_정규화한다(): void
    {
        $this->assertSame('teewide.com', TeeWideHost::canonical('TeeWide.COM'));
        $this->assertSame('teewide.com', TeeWideHost::canonical('teewide.com.'));
        $this->assertSame('teewide.com', TeeWideHost::canonical('  TeeWide.Com.  '));
        $this->assertSame('teewide.com', TeeWideHost::canonical('TEEWIDE.COM.:443'));
    }

    public function test_판정_불가_호스트는_빈_문자열(): void
    {
        $this->assertSame('', TeeWideHost::canonical(''));
        $this->assertSame('', TeeWideHost::canonical('   '));
        $this->assertSame('', TeeWideHost::canonical(null));
    }

    public function test_IPv6_리터럴의_포트만_제거한다(): void
    {
        $this->assertSame('[::1]', TeeWideHost::canonical('[::1]:8000'));
        $this->assertSame('[::1]', TeeWideHost::canonical('[::1]'));
    }

    public function test_역할_판정은_정확히_일치할_때만(): void
    {
        $root = 'teewide.com';
        $live = 'live.teewide.com';

        $this->assertSame(TeeWideHost::ROLE_ROOT, TeeWideHost::role('TeeWide.com:443', $root, $live));
        $this->assertSame(TeeWideHost::ROLE_LIVE, TeeWideHost::role('LIVE.teewide.com.', $root, $live));
        $this->assertSame(TeeWideHost::ROLE_FOREIGN, TeeWideHost::role('yutiv.com', $root, $live));
        $this->assertSame(TeeWideHost::ROLE_FOREIGN, TeeWideHost::role('evil-teewide.com', $root, $live));
        $this->assertSame(TeeWideHost::ROLE_FOREIGN, TeeWideHost::role('', $root, $live));
    }

    // ── 선언 계약 ───────────────────────────────────────────────────────────

    public function test_플러그인이_게이트를_전역_before_core_로_선언한다(): void
    {
        $declarations = (new \Plugins\Yutiv\LiveCommerce\Plugin)->getMiddleware();

        $this->assertCount(1, $declarations);

        $gate = $declarations[0];
        $this->assertSame(TeeWideHostGate::class, $gate['class']);
        $this->assertSame(['everything'], $gate['targets'], '전역 대상이 아니면 쇼핑몰 라우트를 막지 못합니다');
        $this->assertSame('before_core', $gate['timing']);
        $this->assertEqualsCanonicalizing(['web', 'api'], $gate['groups']);
    }

    public function test_차단_상태코드는_404_또는_421_로_일관된다(): void
    {
        $this->configureTeeWide(['block_status' => 500]);

        // 허용 목록 밖 값은 404 로 되돌린다 — 차단 응답이 제각각이면 안 된다.
        $this->assertSame(404, \Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig::blockStatus());

        $this->configureTeeWide(['block_status' => 421]);
        $this->assertSame(421, \Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig::blockStatus());
    }
}
