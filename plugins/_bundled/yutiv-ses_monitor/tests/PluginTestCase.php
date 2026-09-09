<?php

namespace Plugins\Yutiv\SesMonitor\Tests;

use App\Enums\ExtensionStatus;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Plugin as PluginModel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Plugins\Yutiv\SesMonitor\Providers\SesMonitorServiceProvider;
use Plugins\Yutiv\SesMonitor\Support\SesConfig;
use Plugins\Yutiv\SesMonitor\Tests\Support\TestableSesMonitorServiceProvider;
use Tests\TestCase;

/**
 * YUTIV SES 모니터 플러그인 테스트 베이스.
 *
 * ── 로컬 실행 불가 안내 ─────────────────────────────────────────────────────
 * 이 스위트는 프로젝트 PHPUnit 관례를 따르지만 **작성 환경에서는 실행되지 않았다.**
 * 로컬 PHP 가 7.4 이고 `vendor/` 가 비어 있어 Laravel 부팅 자체가 불가능하다.
 * 서명 검증·이벤트 파싱은 PHP 7.4 로도 돌아가는 독립 하네스가 **실행 검증**한다:
 *
 *     php tests/Ses/yutiv-ses-monitor-check.php --verbose
 *
 * 그 하네스는 이 스위트와 **같은 검증기·파서·fixture 팩토리**를 쓰므로 로직이
 * 갈라지지 않는다. 서버(PHP 8.3 + vendor)에서는 이 파일들을 그대로 실행하면 된다.
 *
 *     php artisan test --testsuite=Plugin --filter=SesMonitor
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    protected SnsFixtureBag $bag;

    protected function setUp(): void
    {
        parent::setUp();

        // 바깥으로 나가는 HTTP 는 전부 막는다. SubscribeURL 호출을 검사하려면
        // "실수로 진짜 요청이 나가는" 경로가 없어야 한다.
        Http::preventStrayRequests();
        Http::fake();

        // 기본 설정 — 각 테스트가 필요에 따라 덮어쓴다.
        $this->configureSes();

        $this->bag = new SnsFixtureBag;
        $this->bindFixtureValidator();

        $this->activatePlugin();
        $this->registerWebhookRoute();
    }

    /**
     * 플러그인을 활성 상태로 만든다.
     *
     * 프로바이더는 활성 여부를 DB 에서 읽는다. RefreshDatabase 로 비워진 테스트 DB 에는
     * plugins 행이 없으므로, 활성 전제를 쓰는 테스트는 여기서 행을 만들어 준다.
     */
    protected function activatePlugin(): void
    {
        PluginModel::updateOrCreate(
            ['identifier' => 'yutiv-ses_monitor'],
            [
                'vendor' => 'yutiv',
                'name' => ['ko' => 'SES 모니터', 'en' => 'SES Monitor'],
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
            ]
        );

        PluginsYutivSesMonitorProvidersSesMonitorServiceProvider::invalidatePluginStatusCache();
    }

    /**
     * 플러그인을 비활성으로 만든다 (라우트 미등록 검사용).
     */
    protected function deactivatePlugin(): void
    {
        PluginModel::where('identifier', 'yutiv-ses_monitor')
            ->update(['status' => ExtensionStatus::Inactive->value]);

        PluginsYutivSesMonitorProvidersSesMonitorServiceProvider::invalidatePluginStatusCache();
    }

    /**
     * 프로바이더의 라우트 등록을 현재 설정으로 다시 실행한다.
     *
     * 부팅 시점에는 테스트가 설정을 주입하기 전이라 라우트가 없다. 등록 자체를
     * 검사 대상으로 삼기 위해, 운영 API 를 넓히는 대신 테스트 전용 서브클래스로
     * 같은 로직을 호출한다.
     */
    protected function registerWebhookRoute(): void
    {
        $this->testableProvider()->attemptRouteRegistration();

        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
    }

    /**
     * 테스트 전용 프로바이더 (protected 메서드 접근용).
     */
    protected function testableProvider(): TestableSesMonitorServiceProvider
    {
        return new TestableSesMonitorServiceProvider($this->app);
    }

    /**
     * 등록된 라우트 중 주어진 URI 와 정확히 일치하는 것들을 센다.
     */
    protected function countRoutesForUri(string $uri, string $method = 'POST'): int
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
     * SES 설정을 주입한다. 인자를 주지 않으면 "정상 운영" 기본값.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function configureSes(array $overrides = []): void
    {
        config([
            SesConfig::KEY => array_merge([
                'configuration_set' => 'yutiv-production',
                'topic_arn' => SnsFixtureBag::TOPIC_ARN,
                'region' => 'ap-northeast-2',
                'auto_confirm' => false,
                'control_max_age_seconds' => 3600,
                'max_future_skew_seconds' => 300,
                'raw_payload_enabled' => false,
                'max_body_bytes' => 262144,
                'retention_days' => 90,
                'endpoint_path' => 'webhooks/aws/ses',
                'allowed_sns_types' => ['SubscriptionConfirmation', 'Notification', 'UnsubscribeConfirmation'],
                'allowed_event_types' => ['send', 'reject', 'bounce', 'complaint', 'delivery', 'deliveryDelay', 'renderingFailure'],
            ], $overrides),
        ]);
    }

    /**
     * 검증기를 fixture 인증서를 쓰도록 바인딩한다 (네트워크 없음).
     */
    protected function bindFixtureValidator(): void
    {
        $bag = $this->bag;

        $this->app->bind(\Plugins\Yutiv\SesMonitor\Support\SnsMessageValidator::class, function () use ($bag) {
            return new \Plugins\Yutiv\SesMonitor\Support\SnsMessageValidator(
                SesConfig::topicArn(),
                SesConfig::region(),
                SesConfig::controlMaxAgeSeconds(),
                SesConfig::maxFutureSkewSeconds(),
                SesConfig::allowedSnsTypes(),
                $bag->factory()->certificateFetcher(),
            );
        });
    }

    /**
     * `core.notification-logs.read` 를 가진 관리자.
     */
    protected function createNotificationLogReader(): User
    {
        return $this->createUserWithPermissions(['admin.access', 'core.notification-logs.read']);
    }

    /**
     * 발송 이력 권한이 **없는** 관리자 (권한 게이트 확인용).
     */
    protected function createAdminWithoutLogPermission(): User
    {
        return $this->createUserWithPermissions(['admin.access']);
    }

    /**
     * @param  array<int, string>  $identifiers
     */
    private function createUserWithPermissions(array $identifiers): User
    {
        $role = Role::firstOrCreate(
            ['identifier' => 'ses-test-'.md5(implode(',', $identifiers))],
            ['name' => ['ko' => 'SES 테스트', 'en' => 'SES Test'], 'description' => ['ko' => 'test', 'en' => 'test']]
        );

        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
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
