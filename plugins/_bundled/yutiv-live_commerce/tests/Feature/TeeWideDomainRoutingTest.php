<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

/**
 * 도메인 라우트 격리 — 어느 호스트에서 어느 라우트가 매칭되는가.
 *
 * 응답 코드만 보지 않는다. "라우트가 없어서 우연히 404" 와 "우리 라우트가 매칭됐다" 는
 * 다른 사실이므로, 매칭된 **라우트 이름**을 함께 단언한다.
 */
class TeeWideDomainRoutingTest extends PluginTestCase
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

    // ── 매칭돼야 하는 것 ────────────────────────────────────────────────────

    public function test_teewide_루트가_포털_라우트로_매칭된다(): void
    {
        $url = 'http://'.self::ROOT_HOST.'/';

        $this->assertSame('teewide.portal', $this->matchedRouteName($url), $this->routingDiagnostics($url));

        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/html; charset=UTF-8');
        $response->assertSee('라이브로 연결되는 새로운 쇼핑');
        $response->assertSee('TeeWide', false);

        // 진단 JSON 이 제품 화면으로 새어 나오지 않는다.
        $response->assertDontSee('session_configured', false);
        $response->assertDontSee('yutiv_user_leaked', false);
    }

    public function test_live_호스트의_업체_slug_가_라이브_라우트로_매칭된다(): void
    {
        $url = 'http://'.self::LIVE_HOST.'/golfif';

        $this->assertSame('teewide.live.tenant', $this->matchedRouteName($url), $this->routingDiagnostics($url));

        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/html; charset=UTF-8');
        $response->assertSee('골프이프');
        $response->assertSee('라이브 상품을 준비하고 있습니다');
        $response->assertDontSee('session_configured', false);
    }

    // ── 매칭되면 안 되는 것 ─────────────────────────────────────────────────

    public function test_yutiv_호스트에서는_TeeWide_라우트가_매칭되지_않는다(): void
    {
        // 도메인 제약이 있으므로 포털 라우트로 매칭될 수 없다.
        $this->assertNotSame('teewide.portal', $this->matchedRouteName('http://'.self::YUTIV_HOST.'/'));
        $this->assertNotSame('teewide.live.tenant', $this->matchedRouteName('http://'.self::YUTIV_HOST.'/golfif'));
    }

    public function test_yutiv_에서_golfif_는_TeeWide_로_해석되지_않는다(): void
    {
        $name = $this->matchedRouteName('http://'.self::YUTIV_HOST.'/golfif');

        $this->assertTrue(
            $name === null || ! str_starts_with($name, 'teewide.'),
            'yutiv.com/golfif 가 TeeWide 라우트로 매칭됐습니다: '.var_export($name, true)
        );
    }

    public function test_live_호스트의_루트가_라이브_홈으로_매칭된다(): void
    {
        // Phase 1-A 부터 라이브 홈이 존재한다 — 더 이상 404 가 아니다.
        $url = 'http://'.self::LIVE_HOST.'/';

        $this->assertSame('teewide.live.home', $this->matchedRouteName($url), $this->routingDiagnostics($url));

        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/html; charset=UTF-8');
        $response->assertSee('TeeWide Live', false);
        $response->assertSee('판매 채널');

        // 등록된 채널은 실제로 진입 가능한 링크여야 한다.
        $response->assertSee('/golfif', false);
    }

    public function test_라이브_홈은_없는_수치를_만들어_내지_않는다(): void
    {
        $body = (string) $this->get('http://'.self::LIVE_HOST.'/')->getContent();

        // 시청자 수·주문 수·할인율처럼 근거 없는 숫자를 화면에 두지 않는다.
        foreach (['명 시청', '% 할인', '개 남음', '주문 완료'] as $fabricated) {
            $this->assertStringNotContainsString($fabricated, $body, $fabricated.' 같은 허위 수치가 있습니다');
        }

        $this->assertStringContainsString('준비', $body, '정직한 준비 상태 표기가 없습니다');
    }

    public function test_알_수_없는_업체_slug_는_404(): void
    {
        $this->get('http://'.self::LIVE_HOST.'/unknown-tenant')
            ->assertNotFound();
    }

    public function test_알_수_없는_호스트는_TeeWide_에_접근하지_못한다(): void
    {
        $this->assertNull($this->matchedRouteName('http://evil.example.com/'), 'unknown host 가 포털에 매칭됐습니다');

        $name = $this->matchedRouteName('http://evil.example.com/golfif');
        $this->assertTrue(
            $name === null || ! str_starts_with($name, 'teewide.'),
            'unknown host 가 라이브 라우트에 매칭됐습니다'
        );
    }

    public function test_teewide_유사_호스트는_매칭되지_않는다(): void
    {
        // 접미사·접두사 위조가 통과하면 안 된다.
        foreach ([
            'http://evil-teewide.test/',
            'http://teewide.test.attacker.net/',
            'http://xteewide.test/',
        ] as $url) {
            $this->assertNotSame('teewide.portal', $this->matchedRouteName($url), $url);
        }
    }

    // ── 기존 쇼핑몰 콘텐츠 차단 ─────────────────────────────────────────────

    public function test_teewide_에서_SPA_catch_all_이_노출되지_않는다(): void
    {
        // 쇼핑몰 SPA 셸이 떠서는 안 된다.
        $response = $this->get('http://'.self::ROOT_HOST.'/some-shop-page');

        $response->assertNotFound();
        $this->assertStringNotContainsString('<html', (string) $response->getContent(), 'SPA 셸이 반환됐습니다');
    }

    public function test_teewide_에서_통합검색_API_가_차단된다(): void
    {
        $this->getJson('http://'.self::ROOT_HOST.'/api/search?q=test')->assertNotFound();
    }

    public function test_teewide_에서_이커머스_모듈_API_가_차단된다(): void
    {
        $this->getJson('http://'.self::ROOT_HOST.'/api/modules/sirsoft-ecommerce/products')->assertNotFound();
        $this->getJson('http://'.self::ROOT_HOST.'/api/modules/sirsoft-ecommerce/categories')->assertNotFound();
    }

    public function test_live_에서_yutiv_관리자_경로가_차단된다(): void
    {
        $this->get('http://'.self::LIVE_HOST.'/admin')->assertNotFound();
        $this->getJson('http://'.self::LIVE_HOST.'/api/admin/users')->assertNotFound();
    }

    public function test_차단은_리다이렉트가_아니다(): void
    {
        // 리다이렉트로 yutiv 콘텐츠를 알려주면 안 된다 (정보 노출 · open redirect 표면).
        $response = $this->get('http://'.self::ROOT_HOST.'/some-shop-page');

        $this->assertNotSame(301, $response->getStatusCode());
        $this->assertNotSame(302, $response->getStatusCode());
        $this->assertNull($response->headers->get('Location'));
    }

    // ── 라우트 등록 위생 ────────────────────────────────────────────────────

    public function test_TeeWide_라우트가_중복_등록되지_않는다(): void
    {
        $before = count($this->teeWideRoutes());
        $this->assertSame(count(self::teeWideRouteNames()), $before, 'TeeWide 라우트 수가 기대와 다릅니다');

        // 등록된 **그 프로바이더**를 다시 등록해도 Application::register() 는
        // 재실행하지 않는다(Application.php:885 의 getProvider() 조기 반환).
        //
        // 부모 클래스명으로 물으면 안 된다 — Laravel 12 의 프로바이더 레지스트리는
        // **구상 클래스명이 키**라(Application.php:970-977), 부모 이름으로 register()
        // 하면 조기 반환에 걸리지 않고 별개의 프로바이더가 하나 더 등록된다.
        // 그건 중복 등록 방지 계약이 깨진 게 아니라 다른 클래스를 등록한 것이다.
        $registered = $this->registeredLiveCommerceProviders();
        $this->assertCount(1, $registered, '프로바이더가 정확히 1개여야 합니다');

        foreach (array_keys($registered) as $providerClass) {
            $this->app->register($providerClass);
        }
        $this->refreshRouteLookups();

        $this->assertSame($before, count($this->teeWideRoutes()));
        $this->assertCount(1, $this->registeredLiveCommerceProviders(),
            '재등록으로 프로바이더 인스턴스가 늘었습니다');
        $this->assertSame(1, \Plugins\Yutiv\LiveCommerce\Tests\Support\BootTimeLiveCommerceServiceProvider::$bootCount,
            '재등록이 boot() 을 다시 실행했습니다');
    }

    public function test_TeeWide_라우트가_SPA_catch_all_보다_먼저_등록된다(): void
    {
        // `routes/web.php:51` 의 catch-all 은 무명이고 fallback 도 아니며 도메인 제약도
        // 없다. 즉 `/` 와 `/golfif` 를 모든 호스트에서 삼킨다. RouteCollection 은 먼저
        // 등록된 라우트부터 훑어 첫 일치를 쓰므로, TeeWide 라우트가 그보다 **앞**에
        // 있어야만 도메인 라우팅이 성립한다. 이 순서가 깨지면 위 두 매칭 테스트가
        // 'route name = null' 로 무너진다 — 그 실패의 정확한 원인이 이 단언이다.
        $catchAll = $this->spaCatchAllRoute();

        $this->assertNotNull($catchAll, 'SPA catch-all 을 찾지 못했습니다 — 전제가 바뀌었습니다.');
        $this->assertNull($catchAll->getName(), 'catch-all 에 이름이 생겼습니다 — 진단 전제가 바뀌었습니다.');
        $this->assertFalse($catchAll->isFallback, 'catch-all 이 fallback 이 됐다면 순서 계약이 달라집니다.');

        $catchAllIndex = $this->routeIndex($catchAll);
        $routes = $this->teeWideRoutes();

        $this->assertNotEmpty($routes, $this->routingDiagnostics('http://'.self::ROOT_HOST.'/'));

        foreach ($routes as $route) {
            $this->assertLessThan(
                $catchAllIndex,
                $this->routeIndex($route),
                $route->getName().' 가 SPA catch-all 뒤에 등록됐습니다 — 영영 매칭되지 않습니다.'
                    .$this->routingDiagnostics('http://'.self::ROOT_HOST.'/')
            );
        }
    }

    public function test_TeeWide_라우트는_모두_도메인_제약을_갖는다(): void
    {
        $routes = $this->teeWideRoutes();

        $this->assertNotEmpty($routes, 'TeeWide 라우트가 등록되지 않았습니다');

        foreach ($routes as $route) {
            $this->assertNotNull(
                $route->getDomain(),
                $route->getName().' 에 도메인 제약이 없습니다 — 모든 호스트에서 열립니다.'
            );
            $this->assertContains($route->getDomain(), [self::ROOT_HOST, self::LIVE_HOST]);
        }
    }
}
