<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use App\Enums\ExtensionStatus;
use Plugins\Yutiv\LiveCommerce\Http\Middleware\TeeWideHostGate;
use Plugins\Yutiv\LiveCommerce\Providers\LiveCommerceServiceProvider;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;
use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

/**
 * 안전 계약 — 꺼져 있거나 비활성일 때 기존 사이트에 영향이 없는가.
 *
 * 이 스위트가 통과해야 "번들 상태로 두어도 안전하다" 고 말할 수 있다.
 */
class TeeWideSafetyTest extends PluginTestCase
{
    // ── 기본 비활성 ─────────────────────────────────────────────────────────

    public function test_설정_파일_기본값이_비활성이다(): void
    {
        $config = require base_path('plugins/_bundled/yutiv-live_commerce/config/live-commerce.php');

        $this->assertFalse($config['enabled'], 'TEEWIDE_ENABLED 기본값이 false 가 아닙니다');
    }

    public function test_TEEWIDE_ENABLED_가_false_면_라우트가_등록되지_않는다(): void
    {
        $this->configureTeeWide(['enabled' => false]);
        $this->bootPlugin();

        $this->assertSame([], $this->teeWideRoutes(), '비활성인데 TeeWide 라우트가 등록됐습니다');
        $this->assertNull($this->matchedRouteName('http://'.self::ROOT_HOST.'/'));
    }

    public function test_호스트가_비어_있으면_라우트를_등록하지_않는다(): void
    {
        $this->configureTeeWide(['root_host' => '', 'live_host' => '']);
        $this->bootPlugin();

        $this->assertSame([], $this->teeWideRoutes());
    }

    public function test_두_호스트가_같으면_라우트를_등록하지_않는다(): void
    {
        // root 와 live 가 같으면 역할 판정이 무너진다.
        $this->configureTeeWide(['root_host' => 'same.test', 'live_host' => 'same.test']);
        $this->bootPlugin();

        $this->assertFalse(TeeWideConfig::active());
        $this->assertSame([], $this->teeWideRoutes());
    }

    // ── 플러그인 비활성 ─────────────────────────────────────────────────────

    public function test_플러그인이_비활성이면_라우트가_등록되지_않는다(): void
    {
        $this->seedPluginRow(ExtensionStatus::Inactive->value);
        $this->configureTeeWide();   // 기능 스위치는 켜 두고
        $this->bootPlugin();

        $this->assertSame([], $this->teeWideRoutes(), '비활성 플러그인이 라우트를 등록했습니다');
    }

    // ── 호스트 게이트 no-op ─────────────────────────────────────────────────

    public function test_비활성이면_호스트_게이트가_완전한_no_op(): void
    {
        $this->configureTeeWide(['enabled' => false]);

        $request = \Illuminate\Http\Request::create('http://'.self::ROOT_HOST.'/anything', 'GET');
        $called = false;

        $response = (new TeeWideHostGate)->handle($request, function ($r) use (&$called) {
            $called = true;

            return response('passed-through');
        });

        $this->assertTrue($called, '게이트가 요청을 통과시키지 않았습니다');
        $this->assertSame('passed-through', $response->getContent());
    }

    public function test_비TeeWide_호스트는_게이트를_그대로_통과한다(): void
    {
        $this->configureTeeWide();

        $request = \Illuminate\Http\Request::create('http://'.self::YUTIV_HOST.'/products', 'GET');
        $called = false;

        (new TeeWideHostGate)->handle($request, function ($r) use (&$called) {
            $called = true;

            return response('yutiv');
        });

        $this->assertTrue($called, 'yutiv 요청이 게이트에 막혔습니다');
    }

    // ── 기존 YUTIV 라우트 회귀 ──────────────────────────────────────────────

    public function test_켜져_있어도_yutiv_핵심_라우트는_그대로_동작한다(): void
    {
        $this->configureTeeWide();
        $this->bootPlugin();
        $this->attachHostGate();

        // 통합 검색은 yutiv 호스트에서 정상 응답해야 한다 (404 가 아니어야 한다).
        $response = $this->getJson('http://'.self::YUTIV_HOST.'/api/search?q=test');

        $this->assertNotSame(404, $response->getStatusCode(), 'TeeWide 가 yutiv 검색을 막았습니다');
    }

    public function test_TeeWide_는_기존_라우트를_제거하거나_덮어쓰지_않는다(): void
    {
        $before = count($this->app['router']->getRoutes()->getRoutes());

        $this->configureTeeWide();
        $this->bootPlugin();

        $after = count($this->app['router']->getRoutes()->getRoutes());

        // 라우트는 늘어나기만 해야 한다.
        $this->assertGreaterThanOrEqual($before, $after);
    }

    // ── 캐시 안전성 ─────────────────────────────────────────────────────────

    public function test_설정에_클로저가_없어_config_cache_가_가능하다(): void
    {
        $config = require base_path('plugins/_bundled/yutiv-live_commerce/config/live-commerce.php');

        // config:cache 는 설정을 var_export 로 직렬화한다. 클로저가 하나라도 있으면 실패한다.
        $this->assertNotFalse(
            @var_export($config, true),
            '설정을 직렬화할 수 없습니다 — config:cache 가 실패합니다.'
        );
        $this->assertNoClosures($config);
    }

    public function test_라우트에_클로저_액션이_없어_route_cache_가_가능하다(): void
    {
        $this->configureTeeWide();
        $this->bootPlugin();

        $routes = $this->teeWideRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $action = $route->getAction('uses');

            // route:cache 는 클로저 액션을 직렬화하지 못한다.
            $this->assertIsString(
                $action,
                $route->getName().' 이 클로저 액션입니다 — route:cache 가 실패합니다.'
            );
            $this->assertTrue(
                self::isTeeWideControllerAction($action),
                $route->getName().' 액션이 이 플러그인의 컨트롤러가 아닙니다: '.$action
            );
        }
    }

    public function test_config_cache_이후에도_설정을_읽는다(): void
    {
        // config:cache 는 병합된 배열을 그대로 얼린다. 병합 결과가 기대와 같은지 확인한다.
        $this->app->register(LiveCommerceServiceProvider::class);

        $this->assertIsArray(config(TeeWideConfig::KEY));
        $this->assertArrayHasKey('enabled', config(TeeWideConfig::KEY));
        $this->assertArrayHasKey('root_host', config(TeeWideConfig::KEY));
        $this->assertArrayHasKey('session', config(TeeWideConfig::KEY));
    }

    /**
     * 배열 어디에도 클로저가 없는지 재귀 확인.
     *
     * @param  array<mixed>  $value
     */
    private function assertNoClosures(array $value, string $path = ''): void
    {
        foreach ($value as $key => $item) {
            $childPath = $path === '' ? (string) $key : $path.'.'.$key;

            if ($item instanceof \Closure) {
                $this->fail("설정 {$childPath} 에 클로저가 있습니다 — config:cache 가 실패합니다.");
            }

            if (is_array($item)) {
                $this->assertNoClosures($item, $childPath);
            }
        }

        $this->assertTrue(true);
    }
}
