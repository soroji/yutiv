<?php

namespace Plugins\Yutiv\LiveCommerce\Tests;

use App\Enums\ExtensionStatus;
use App\Models\Plugin as PluginModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Plugins\Yutiv\LiveCommerce\Http\Middleware\TeeWideHostGate;
use Plugins\Yutiv\LiveCommerce\Providers\LiveCommerceServiceProvider;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;
use Tests\TestCase;

/**
 * TeeWide 라이브커머스 테스트 베이스 (Phase 0).
 *
 * ── 로컬 실행 불가 안내 ─────────────────────────────────────────────────────
 * 이 스위트는 프로젝트 PHPUnit 관례를 따르지만 **작성 환경에서는 실행되지 않았다.**
 * 로컬 PHP 가 7.4 이고 `vendor/` 가 비어 있어 Laravel 부팅 자체가 불가능하다.
 * 호스트 정규화 규칙만 PHP 7.4 로도 도는 독립 하네스가 실행 검증한다:
 *
 *     php tests/TeeWide/yutiv-live-commerce-check.php --verbose
 *
 * 나머지(라우트 매칭·세션·차단)는 **서버에서만** 증명된다.
 *
 * ── 플러그인 부팅 ───────────────────────────────────────────────────────────
 * `App\Providers\PluginServiceProvider` 는 `plugins/` 바로 아래만 훑으므로(비재귀)
 * `plugins/_bundled/` 의 이 플러그인은 테스트 앱에서 자동 등록되지 않는다. 또 앱 부팅은
 * RefreshDatabase 보다 먼저라 plugins 행도 없다. 그래서 활성 시드 → 설정 주입 →
 * `Application::register()` 순서로 **운영과 같은 생명주기**를 다시 태운다.
 * (yutiv-ses_monitor 에서 검증된 패턴)
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    protected const ROOT_HOST = 'teewide.test';

    protected const LIVE_HOST = 'live.teewide.test';

    protected const YUTIV_HOST = 'yutiv.test';

    /** 프로바이더 생명주기 재실행을 한 번만 하기 위한 플래그. */
    private bool $pluginRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPluginRow(ExtensionStatus::Active->value);
    }

    /**
     * TeeWide 설정을 주입한다. 인자를 주지 않으면 "켜진 상태" 기본값.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function configureTeeWide(array $overrides = []): void
    {
        config([
            TeeWideConfig::KEY => array_merge([
                'enabled' => true,
                'root_host' => self::ROOT_HOST,
                'live_host' => self::LIVE_HOST,
                'session' => [
                    'cookie' => 'teewide_session',
                    'domain' => '.teewide.test',
                ],
                'block_status' => 404,
                'diagnostics_enabled' => true,
                'tenant_slug_pattern' => '[a-z0-9][a-z0-9-]{0,62}',
                'known_tenants' => ['golfif'],
            ], $overrides),
        ]);
    }

    /**
     * 플러그인 행을 만들어 활성/비활성 상태를 만든다.
     */
    protected function seedPluginRow(string $status): void
    {
        PluginModel::updateOrCreate(
            ['identifier' => 'yutiv-live_commerce'],
            [
                'vendor' => 'yutiv',
                'name' => ['ko' => 'TeeWide', 'en' => 'TeeWide'],
                'version' => '0.1.0',
                'status' => $status,
            ]
        );

        LiveCommerceServiceProvider::invalidatePluginStatusCache();
    }

    /**
     * 운영과 동일한 생명주기(register → boot)로 프로바이더를 태운다.
     *
     * `Application::register()` 는 이미 등록된 프로바이더면 재실행하지 않으므로
     * 라우트가 중복 등록되지 않는다.
     */
    protected function bootPlugin(): void
    {
        if ($this->pluginRegistered) {
            return;
        }

        $this->app->register(LiveCommerceServiceProvider::class);
        $this->pluginRegistered = true;

        $this->refreshRouteLookups();
    }

    /**
     * 호스트 게이트를 web/api 그룹에 붙인다.
     *
     * 운영에서는 코어의 `ExtensionMiddlewareGate` 가 `Plugin::getMiddleware()` 선언을
     * 읽어 이 미들웨어를 실행한다. 그 수집 경로는 `ExtensionMiddlewareRegistry` 가
     * 캐시된 인덱스와 `PluginManager::getActivePlugins()` 에 의존해 테스트 앱에서
     * 재현하기 어렵다. 그래서 HTTP 레벨 검사는 **같은 미들웨어를 같은 그룹·같은 시점
     * (그룹 선두)에** 직접 붙여 동작을 검증하고, "선언이 올바른가" 는 별도로 단언한다.
     * (J. 미증명 항목에 이 경계를 명시했다)
     */
    protected function attachHostGate(): void
    {
        foreach (['web', 'api'] as $group) {
            $this->app['router']->prependMiddlewareToGroup($group, TeeWideHostGate::class);
        }
    }

    protected function refreshRouteLookups(): void
    {
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
    }

    /**
     * 주어진 method/URL 로 실제 매칭되는 라우트 이름 (없으면 null).
     *
     * "404 가 났다" 와 "우리 라우트가 매칭됐다" 는 다른 사실이라, 응답 코드가 아니라
     * 라우트 이름으로 확인해야 한다.
     */
    protected function matchedRouteName(string $url, string $method = 'GET'): ?string
    {
        $request = \Illuminate\Http\Request::create($url, $method);

        try {
            return $this->app['router']->getRoutes()->match($request)->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 주어진 URI 로 등록된 라우트 수 (도메인 무관).
     */
    protected function countRoutesForUri(string $uri, string $method = 'GET'): int
    {
        $count = 0;

        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 이름이 `teewide.` 로 시작하는 라우트 목록.
     *
     * @return array<int, \Illuminate\Routing\Route>
     */
    protected function teeWideRoutes(): array
    {
        $routes = [];

        foreach ($this->app['router']->getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name) && str_starts_with($name, TeeWideHostGate::ROUTE_PREFIX)) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * 플러그인 마이그레이션까지 포함해 DB 를 만든다.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        $paths = ['database/migrations'];
        foreach (glob(base_path('modules/_bundled/*/database/migrations'), GLOB_ONLYDIR) as $p) {
            $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $p);
        }
        foreach (glob(base_path('plugins/_bundled/*/database/migrations'), GLOB_ONLYDIR) as $p) {
            $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $p);
        }

        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => false,
            '--path' => $paths,
        ];
    }
}
