<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

/**
 * Phase 1-A 제품 화면 계약.
 *
 * ── 핵심 구조 변경 ─────────────────────────────────────────────────────────
 * Phase 0 에서는 `TEEWIDE_DIAGNOSTICS` 가 화면까지 함께 껐다. 진단 스위치를 끄면 서비스가
 * 사라지는 구조는 옳지 않다. 이제 그 스위치는 `/_teewide/session` 만 켜고 끄고,
 * 포털·라이브 HTML 은 플러그인이 활성이고 `TEEWIDE_ENABLED` 가 켜져 있으면 항상 뜬다.
 */
class TeeWideScreenTest extends PluginTestCase
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

    // ── 세 화면이 HTML 로 뜬다 ──────────────────────────────────────────────

    public function test_포털_라이브홈_채널이_모두_HTML_200_이다(): void
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
            $response->assertSee('<!DOCTYPE html>', false);
            $response->assertSee('lang="ko"', false);
        }
    }

    public function test_미등록_tenant_는_404(): void
    {
        $this->get('http://'.self::LIVE_HOST.'/unknown-tenant')->assertNotFound();
        $this->get('http://'.self::LIVE_HOST.'/golfif-2')->assertNotFound();
    }

    public function test_화면에_디버그_정보가_노출되지_않는다(): void
    {
        foreach ([
            'http://'.self::ROOT_HOST.'/',
            'http://'.self::LIVE_HOST.'/',
            'http://'.self::LIVE_HOST.'/golfif',
        ] as $url) {
            $body = (string) $this->get($url)->getContent();

            foreach (['session_configured', 'yutiv_user_leaked', 'teewide.marker', 'APP_KEY'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, $url.' 에 '.$leak.' 이 노출됐습니다');
            }
        }
    }

    public function test_외부_CDN_폰트_이미지에_의존하지_않는다(): void
    {
        foreach ([
            'http://'.self::ROOT_HOST.'/',
            'http://'.self::LIVE_HOST.'/',
            'http://'.self::LIVE_HOST.'/golfif',
        ] as $url) {
            $body = (string) $this->get($url)->getContent();

            foreach (['//cdn.', 'fonts.googleapis.com', 'fonts.gstatic.com', 'unpkg.com', 'jsdelivr.net'] as $external) {
                $this->assertStringNotContainsString($external, $body, $url.' 이 외부 자원 '.$external.' 를 참조합니다');
            }
        }
    }

    public function test_모바일_뷰포트와_접근성_기본이_들어있다(): void
    {
        $body = (string) $this->get('http://'.self::LIVE_HOST.'/golfif')->getContent();

        $this->assertStringContainsString('name="viewport"', $body, '반응형 뷰포트 메타가 없습니다');
        $this->assertStringContainsString('본문으로 건너뛰기', $body, '스킵 링크가 없습니다');
        $this->assertStringContainsString('<main', $body, 'main 랜드마크가 없습니다');
        $this->assertStringContainsString('<h1', $body, 'h1 이 없습니다');
    }

    public function test_진단이_켜지면_진단_라우트가_함께_등록된다(): void
    {
        $names = [];
        foreach ($this->teeWideRoutes() as $route) {
            $names[] = $route->getName();
        }
        sort($names);

        $this->assertSame(self::teeWideRouteNames(), $names);
    }

    // ── 라우트 계약 ─────────────────────────────────────────────────────────

    public function test_진단_라우트가_tenant_와일드카드에_잡히지_않는다(): void
    {
        // `/_teewide/session` 이 `/{tenant}` 보다 먼저 등록돼야 한다.
        $this->assertSame(
            'teewide.live.session',
            $this->matchedRouteName('http://'.self::LIVE_HOST.'/_teewide/session')
        );
    }

    public function test_화면_라우트의_domain_과_action_이_계약대로다(): void
    {
        $expected = [
            'teewide.portal' => [self::ROOT_HOST, 'PortalController@index'],
            'teewide.live.home' => [self::LIVE_HOST, 'LiveController@home'],
            'teewide.live.tenant' => [self::LIVE_HOST, 'LiveController@channel'],
            'teewide.portal.session' => [self::ROOT_HOST, 'DiagnosticsController@sessionMarker'],
            'teewide.live.session' => [self::LIVE_HOST, 'DiagnosticsController@sessionMarker'],
        ];

        $seen = [];
        foreach ($this->teeWideRoutes() as $route) {
            $seen[$route->getName()] = [$route->getDomain(), (string) $route->getActionName()];
        }

        foreach ($expected as $name => [$domain, $action]) {
            $this->assertArrayHasKey($name, $seen, $name.' 라우트가 없습니다');
            $this->assertSame($domain, $seen[$name][0], $name.' 의 도메인이 다릅니다');
            $this->assertStringContainsString($action, $seen[$name][1], $name.' 의 액션이 다릅니다');
        }
    }

    // ── 호스트 격리 ─────────────────────────────────────────────────────────

    public function test_YUTIV_호스트에서는_TeeWide_화면이_노출되지_않는다(): void
    {
        foreach (['/', '/golfif'] as $path) {
            $url = 'http://'.self::YUTIV_HOST.$path;
            $body = (string) $this->get($url)->getContent();

            $this->assertStringNotContainsString('라이브로 연결되는 새로운 쇼핑', $body, $url.' 에 포털 화면이 새어 나왔습니다');
            $this->assertStringNotContainsString('TeeWide Live', $body, $url.' 에 라이브 화면이 새어 나왔습니다');

            $name = $this->matchedRouteName($url);
            $this->assertTrue(
                $name === null || ! str_starts_with($name, 'teewide.'),
                $url.' 이 TeeWide 라우트에 매칭됐습니다: '.var_export($name, true)
            );
        }
    }

    public function test_TeeWide_호스트에서_YUTIV_콘텐츠가_노출되지_않는다(): void
    {
        $this->getJson('http://'.self::ROOT_HOST.'/api/search?q=test')->assertNotFound();
        $this->getJson('http://'.self::LIVE_HOST.'/api/modules/sirsoft-ecommerce/products')->assertNotFound();
        $this->get('http://'.self::LIVE_HOST.'/admin')->assertNotFound();
    }

}
