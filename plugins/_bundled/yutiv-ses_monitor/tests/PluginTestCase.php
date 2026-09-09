<?php

namespace Plugins\Yutiv\SesMonitor\Tests;

use App\Enums\ExtensionStatus;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Plugin as PluginModel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Plugins\Yutiv\SesMonitor\Providers\SesMonitorServiceProvider;
use Plugins\Yutiv\SesMonitor\Support\SesConfig;
use Plugins\Yutiv\SesMonitor\Tests\Support\TestableSesMonitorServiceProvider;
use Symfony\Component\Mime\Header\Headers;
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

    /** 관리자 API 목록 라우트 URI (운영 prefix 포함). */
    protected const ADMIN_API_URI = 'api/plugins/yutiv-ses_monitor/admin/ses-events';

    /**
     * 이 플러그인 테스트 전용 암호화 키 (결정적, 운영과 무관).
     *
     * 32바이트 평문을 base64 로 감싼 값이라 AES-256-CBC / AES-256-GCM 어느 쪽에도 맞는다.
     * 운영 APP_KEY 를 읽거나 복사하지 않고, .env 파일도 건드리지 않는다.
     */
    protected const TEST_APP_KEY_PLAINTEXT = 'yutiv-ses_monitor-testing-key000';

    protected SnsFixtureBag $bag;

    /** 프로바이더 생명주기 재실행을 한 번만 하기 위한 플래그 (중복 등록 방지). */
    private bool $pluginRegistered = false;

    protected function setUp(): void
    {
        // 앱이 만들어지자마자(테스트 본문 이전) 암호화 키를 고정한다.
        // encrypter 는 지연 해석되므로 이 시점이면 최초 resolve 보다 앞선다.
        $this->afterApplicationCreated(function () {
            $this->useDeterministicTestAppKey();
        });

        parent::setUp();

        // 바깥으로 나가는 HTTP 는 전부 막는다. SubscribeURL 호출을 검사하려면
        // "실수로 진짜 요청이 나가는" 경로가 없어야 한다.
        Http::preventStrayRequests();
        Http::fake();

        // 기본 설정 — 각 테스트가 필요에 따라 덮어쓴다.
        $this->configureSes();

        $this->bag = new SnsFixtureBag;

        // 실제 SMTP 로 나가지 않도록 전송기를 먼저 격리한다 (아래 주석 참조).
        $this->forceArrayMailer();

        $this->activatePlugin();
        $this->bootPluginAsActive();

        // ★ 순서 주의: 프로바이더 register() 가 SnsMessageValidator 를 **실제 인증서
        //   fetcher** 로 바인딩하므로, fixture 바인딩은 그 뒤에 덮어써야 한다.
        //   앞에 두면 서명 검증이 네트워크로 진짜 인증서를 가지러 간다.
        $this->bindFixtureValidator();

        $this->registerPluginApiRoutes();
    }

    /**
     * 메일 전송기를 array 로 강제한다.
     *
     * ── 왜 phpunit.xml 의 MAIL_MAILER=array 로 충분하지 않은가 ─────────────
     * `App\Providers\SettingsServiceProvider::applyMailConfig()` 가 부팅 중
     * 설정 저장소(g7_settings 의 mail 카테고리)를 읽어 `Config::set('mail.default', ...)`
     * 로 **env 값을 덮어쓴다.** 운영 설정이 smtp 면 테스트 앱도 smtp 가 된다.
     * 게다가 `tests/TestCase` 의 settings 디스크 페이크는 `afterApplicationCreated`
     * 시점이라 그 프로바이더보다 **뒤에** 실행되어 이미 읽힌 값을 되돌리지 못한다.
     *
     * 그래서 config 를 다시 array 로 고정하고, **이미 만들어진 mailer 인스턴스를
     * 버린다.** MailManager 는 mailer 를 이름별로 캐시하므로 config 만 바꾸면
     * 앞서 resolve 된 smtp transport 가 그대로 쓰인다.
     *
     * Mail::fake() 를 쓰지 않는 이유: fake 는 Mailer 자체를 대체해
     * MessageSending 이벤트와 실제 Symfony 헤더 조립을 우회한다. 우리가 검사하려는
     * 것이 바로 그 경로라 array transport 로 **진짜 전송 파이프라인**을 태운다.
     */
    protected function forceArrayMailer(): void
    {
        config([
            'mail.default' => 'array',
            'mail.mailers.array' => ['transport' => 'array'],
        ]);

        // 이미 resolve 된 매니저·mailer 를 버려 다음 접근에서 새 config 로 다시 만들게 한다.
        if ($this->app->resolved('mail.manager')) {
            $manager = $this->app->make('mail.manager');
            if (method_exists($manager, 'forgetMailers')) {
                $manager->forgetMailers();
            }
        }

        $this->app->forgetInstance('mail.manager');
        $this->app->forgetInstance('mailer');
        Mail::clearResolvedInstances();
    }

    /**
     * 지금 유효한 전송기가 정말 array 인지 단언한다.
     *
     * 메일을 보내는 테스트는 **보내기 전에** 이걸 불러 환경 오염을 즉시 드러낸다.
     * config 값과 실제 transport 인스턴스를 둘 다 본다 — config 만 보면 캐시된
     * smtp mailer 가 남아 있는 상황을 놓친다.
     */
    protected function assertArrayMailerActive(): void
    {
        $this->assertSame(
            'array',
            config('mail.default'),
            'mail.default 가 array 가 아닙니다 — 테스트가 실제 SMTP 로 나갈 수 있습니다.'
        );

        $this->assertInstanceOf(
            ArrayTransport::class,
            Mail::mailer()->getSymfonyTransport(),
            '전송기가 ArrayTransport 가 아닙니다 — 캐시된 smtp mailer 가 남아 있습니다.'
        );
    }

    /**
     * 운영 부팅 경로를 그대로 다시 태운다.
     *
     * ── 왜 테스트가 이걸 직접 해야 하는가 ────────────────────────────────
     * `App\Providers\PluginServiceProvider` 는 `base_path('plugins')` 의 **바로 아래**
     * 디렉토리만 훑는다(비재귀). 설치된 플러그인은 `plugins/<identifier>` 에 복사되고,
     * 원본인 `plugins/_bundled/<identifier>` 는 그 스캔 대상이 아니다. 이 저장소에는
     * `plugins/_bundled` 와 `plugins/_pending` 만 있어 **어떤 플러그인 프로바이더도
     * 자동 등록되지 않는다.**
     *
     * 그래서 register() 가 한 번도 돌지 않았고, register() 에서 하는 컨테이너 바인딩
     * (SesEventParser · SnsMessageValidator · SubscriptionConfirmer · PendingSentMessage)
     * 이 전부 없는 상태였다. `app(SesEventParser::class)` 는 생성자의 필수 배열 인자를
     * 채울 수 없어 `Unresolvable dependency` 로 터진다.
     *
     * `Application::register()` 는 register() 를 부르고, 앱이 이미 부팅돼 있으면 boot()
     * 까지 이어서 부른다 — 운영과 **같은 순서**다. 게다가 같은 프로바이더가 이미
     * 등록돼 있으면 재실행하지 않으므로(getProvider 검사) 리스너·명령·라우트가
     * 중복 등록되지 않는다.
     */
    protected function bootPluginAsActive(): void
    {
        if ($this->pluginRegistered) {
            return;
        }

        // 운영과 동일한 생명주기: register() → boot().
        $this->app->register(SesMonitorServiceProvider::class);
        $this->pluginRegistered = true;

        $this->refreshRouteLookups();
    }

    /**
     * 플러그인 관리자 API 라우트를 테스트 앱에 등록한다.
     *
     * ── 왜 404 였는가 ────────────────────────────────────────────────────
     * `App\Providers\PluginRouteServiceProvider` 는 boot 시점에 **plugins 테이블의
     * 활성 목록**을 읽어 라우트를 로드한다. 테스트 앱은 RefreshDatabase 가 DB 를
     * 비우기 **전에** 부팅되므로 그 목록이 비어 있고, `api/plugins/...` 라우트가
     * 아예 등록되지 않는다 → 권한 미들웨어에 닿기도 전에 404.
     *
     * 그래서 운영과 **동일한 prefix / name / middleware** 로 같은 라우트 파일을
     * 다시 로드한다 (PluginRouteServiceProvider::loadPluginRoutes() 와 같은 값).
     * 이미 등록돼 있으면 건너뛰어 중복 등록을 만들지 않는다.
     */
    protected function registerPluginApiRoutes(): void
    {
        $routeFile = base_path('plugins/_bundled/yutiv-ses_monitor/src/routes/api.php');

        if (! is_file($routeFile)) {
            return;
        }

        if ($this->countRoutesForUri(self::ADMIN_API_URI, 'GET') > 0) {
            return;
        }

        Route::prefix('api/plugins/yutiv-ses_monitor')
            ->name('api.plugins.yutiv-ses_monitor.')
            ->middleware('api')
            ->group($routeFile);

        $this->refreshRouteLookups();
    }

    /**
     * 테스트 전용 APP_KEY 를 강제한다.
     *
     * ── 왜 필요한가 ──────────────────────────────────────────────────────
     * webhook 라우트는 `web` 그룹이라 세션·쿠키 암호화를 거친다. 앱에 키가 없으면
     * `MissingAppKeyException` 으로 요청 자체가 깨진다. 플러그인 테스트는 루트
     * `.env` 상태와 무관하게 단독으로도 돌아야 하므로 여기서 결정적 키를 넣는다.
     *
     * 주변 환경에 키가 있어도 **덮어쓴다** — 운영 키를 테스트에 끌어다 쓰지 않기
     * 위해서다. 파일을 쓰거나 `key:generate` 를 돌리지 않는다.
     */
    protected function useDeterministicTestAppKey(): void
    {
        config(['app.key' => 'base64:'.base64_encode(self::TEST_APP_KEY_PLAINTEXT)]);

        // 이미 encrypter 가 만들어졌다면 옛 키를 들고 있으므로 버린다.
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();
    }

    /**
     * 특정 헤더의 값들을 **배열로 확정해서** 돌려준다.
     *
     * `Symfony\Component\Mime\Header\Headers::all()` 은 `iterable` 을 선언하고 실제로는
     * Generator 를 돌려준다. PHPUnit 11 은 Generator 를 assertion haystack 으로 받지
     * 않고 `GeneratorNotSupportedException` 을 던진다(소비하면 되감을 수 없어서다).
     * 그래서 단언 **전에** 여기서 materialize 한다. 반환 타입이 배열인 버전도 있으므로
     * 양쪽을 모두 안전하게 다룬다.
     *
     * @return array<int, \Symfony\Component\Mime\Header\HeaderInterface>
     */
    protected function headerValues(Headers $headers, string $name): array
    {
        $all = $headers->all($name);

        return is_array($all) ? array_values($all) : iterator_to_array($all, false);
    }

    /**
     * 특정 헤더의 본문 문자열 목록. 개수와 값을 한 번에 단언할 때 쓴다.
     *
     * @return array<int, string>
     */
    protected function headerBodies(Headers $headers, string $name): array
    {
        return array_map(
            static fn ($header) => $header->getBodyAsString(),
            $this->headerValues($headers, $name)
        );
    }

    private function refreshRouteLookups(): void
    {
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
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

        SesMonitorServiceProvider::invalidatePluginStatusCache();
    }

    /**
     * 플러그인을 비활성으로 만든다 (라우트 미등록 검사용).
     */
    protected function deactivatePlugin(): void
    {
        PluginModel::where('identifier', 'yutiv-ses_monitor')
            ->update(['status' => ExtensionStatus::Inactive->value]);

        SesMonitorServiceProvider::invalidatePluginStatusCache();
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

        $this->refreshRouteLookups();
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
