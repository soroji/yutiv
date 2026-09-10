<?php

namespace Plugins\Yutiv\LiveCommerce\Tests;

use App\Enums\ExtensionStatus;
use App\Models\Plugin as PluginModel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Hash;
use Plugins\Yutiv\LiveCommerce\Http\Middleware\TeeWideHostGate;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Providers\LiveCommerceServiceProvider;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideSessionScope;
use Plugins\Yutiv\LiveCommerce\Tests\Support\BootTimeLiveCommerceServiceProvider;
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
 * ── 플러그인 부팅: 두 가지 경로 ─────────────────────────────────────────────
 * `App\Providers\PluginServiceProvider` 는 `plugins/` 바로 아래만 훑으므로(비재귀)
 * `plugins/_bundled/` 의 이 플러그인은 테스트 앱에서 자동 등록되지 않는다. 그래서
 * 생명주기를 테스트가 직접 태우는데, 검증하려는 대상에 따라 **시점이 달라야 한다.**
 *
 * ① 부팅 시점 등록 — `teeWideBootConfig()` 를 재정의한 스위트
 *    `createApplication()` 이 `RegisterProviders::merge()` 로 코어 프로바이더 목록에 넣는다.
 *    운영과 같은 자리라 라우트가 `routes/web.php` 보다 **먼저** 올라간다.
 *    라우트 우선순위·도메인 매칭·세션을 다루는 스위트는 반드시 이 경로를 쓴다.
 *
 * ② 부팅 후 등록 — `bootPlugin()`
 *    활성 시드 → 설정 주입 → `Application::register()`. 케이스마다 다른 설정(비활성,
 *    빈 호스트, 같은 호스트…)을 시험할 수 있어 안전 계약 스위트가 쓴다. 이 경로에서는
 *    라우트가 SPA catch-all 뒤에 붙으므로 **매칭 결과를 단언하지 않는다.**
 *    (yutiv-ses_monitor 에서 검증된 패턴)
 *
 * 왜 ①이 필요한지는 `createApplication()` 과
 * `Tests\Support\BootTimeLiveCommerceServiceProvider` 주석에 적었다 — 서버 1차 실행의
 * "기대 teewide.portal, 실제 null" 이 바로 ②로만 등록한 탓이었다.
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    protected const ROOT_HOST = 'teewide.test';

    protected const LIVE_HOST = 'live.teewide.test';

    protected const YUTIV_HOST = 'yutiv.test';

    /**
     * 이 플러그인 테스트 전용 암호화 키 (결정적, 운영과 무관).
     *
     * 32바이트 평문이라 AES-256-CBC / GCM 어느 쪽에도 맞는다. 운영 APP_KEY 를 읽거나
     * 복사하지 않고, .env 파일도 건드리지 않으며, key:generate 도 돌리지 않는다.
     *
     * ── SES 플러그인과 공유하지 않는 이유 ──────────────────────────────
     * yutiv-ses_monitor 의 PluginTestCase 에 같은 패턴이 있지만 상속·재사용하지 않는다.
     * 코어의 확장 테스트 격리(`ExtensionTestAllowlist`)는 테스트 클래스가 속한 확장만
     * allowlist 에 넣는다. TeeWide 테스트가 SES 테스트 클래스를 상속하면 두 플러그인이
     * 서로를 끌어들여, SES 를 제거하는 순간 TeeWide 테스트가 통째로 깨진다.
     * 키 값도 일부러 다르게 두어 한쪽 상태가 다른 쪽에 새지 않게 한다.
     */
    protected const TEST_APP_KEY_PLAINTEXT = 'teewide-live_commerce-testing-32';

    /** 테스트 회원의 기본 비밀번호 (테스트 전용 — 운영 시드는 계정을 만들지 않는다). */
    protected const TEST_USER_PASSWORD = 'teewide-secret-1234';

    /** 프로바이더 생명주기 재실행을 한 번만 하기 위한 플래그. */
    private bool $pluginRegistered = false;

    /** 임시 services manifest 를 두는 디렉토리 (프로젝트 base 기준 상대경로). */
    private const ISOLATED_SERVICES_DIR = 'storage/framework/testing/teewide';

    /** 프로세스 종료 시 잔여 임시 manifest 를 지우는 훅을 한 번만 건다. */
    private static bool $shutdownCleanupRegistered = false;

    /**
     * 실제 프로젝트 services manifest 의 부팅 전 상태.
     *
     * @var array{exists: bool, hash: string|null}|null
     */
    private ?array $realServicesManifestState = null;

    /** 이 테스트가 쓰는 임시 manifest 의 절대 경로. */
    private ?string $isolatedServicesManifestPath = null;

    /**
     * APP_SERVICES_CACHE 의 원래 상태 (superglobal 별로 정확히 보존).
     *
     * "없음" 과 "빈 문자열" 과 "실제 값" 은 서로 다른 상태다 — 복원할 때 뭉뚱그리면
     * 원래 없던 키를 빈 문자열로 남겨 다음 부팅의 경로 판정을 바꿔 버린다.
     *
     * @var array<string, array{set: bool, value: mixed}>
     */
    private array $servicesCacheEnvBackup = [];

    /**
     * bootstrap() 이 끝난 직후의 전체 라우트 수.
     *
     * 프로바이더 boot 종료 시점 수와 비교해 "늦은 등록이 아님" 을 증명한다.
     * 부팅이 끝난 뒤 등록했다면 두 값이 같아진다.
     */
    private ?int $routeCountAfterBootstrap = null;

    /**
     * 앱 부팅 시점에 주입할 TeeWide 설정. `null` 이면 부팅 시점 등록을 하지 않는다.
     *
     * 라우트 **우선순위**를 다루는 스위트만 이 훅을 재정의한다. 우선순위와 무관한
     * 스위트(안전 계약·게이트 판정)는 기존대로 `bootPlugin()` 으로 부팅 뒤에 등록해
     * 케이스마다 다른 설정을 시험한다.
     *
     * @return array<string, mixed>|null
     */
    protected function teeWideBootConfig(): ?array
    {
        return null;
    }

    /** 부팅 시점 플러그인 활성 판정 (BootTimeLiveCommerceServiceProvider 주석 참조). */
    protected function teeWideBootPluginActive(): bool
    {
        return true;
    }

    /**
     * 운영과 같은 단계에서 플러그인 프로바이더가 등록되도록 앱을 만든다.
     *
     * 상위 구현(`Illuminate\Foundation\Testing\TestCase::createApplication()`, v12.62.0)
     * 과 다른 점은 하나 — `bootstrap()` 전에 `RegisterProviders::merge()` 로 테스트용
     * 프로바이더를 **코어 프로바이더 목록에 넣는다.** Testbench 의 `getPackageProviders()`
     * 가 쓰는 것과 같은 경로다(이 프로젝트엔 Testbench 미설치라 직접 호출).
     *
     * 그래서 실제 순서가 이렇게 된다:
     *   RegisterProviders → (config 주입 + provider register)
     *   → BootProviders → Application::boot()
     *       ├ booting 콜백 → withRouting() 이 **이때서야** 라우트 프로바이더를 등록
     *       └ boot 루프 → 우리 provider boot(TeeWide 라우트) → … → routes/web.php
     *   → HTTP 요청
     *
     * 설정은 이벤트 훅이 아니라 프로바이더 자신의 `register()` 에서 심는다
     * (`BootTimeLiveCommerceServiceProvider::$config`) — config→register→boot 순서가
     * 한 곳에서 결정돼 훅 발화 시점에 의존하지 않는다.
     *
     * 부팅 뒤에 설정을 바꾸고 라우트만 따로 복제하는 방식은 쓰지 않는다 — 그러면
     * 검증하려는 등록 순서 자체가 재현되지 않는다.
     *
     * ⚠ 이전 구현은 `beforeBootstrapping(BootProviders::class)` 이벤트 훅을 썼고,
     *   서버 2차 실행에서 TeeWide 라우트가 #336, SPA catch-all 이 #327 로 나왔다.
     *   즉 그 경로로는 **routes/web.php 뒤에** 붙었다. 왜 그랬는지는 아직 실행으로
     *   증명되지 않았고(`lifecycleTrace()` 가 다음 실행에서 기록한다), 이 구현은
     *   그 답에 의존하지 않는다.
     *
     * ⚠ 가시성은 `public` 이어야 한다. 부모(v12.62.0 `Illuminate\Foundation\Testing\`
     *   `TestCase::createApplication()`)가 public 이므로 protected 로 좁히면 클래스 로딩
     *   시점에 Fatal 이 난다 — SES 첫 서버 실행의
     *   "Access level to ...::post() must be public" 이 같은 계열의 실패였다.
     */
    public function createApplication(): Application
    {
        // 앞 테스트가 남긴 merge 목록을 먼저 비운다. 비우지 않으면 부팅 시점 등록을
        // 쓰지 않는 스위트(안전 계약)까지 이 프로바이더를 물려받는다.
        // 뒤이은 Application::configure() 의 withProviders() 가 bootstrap/providers.php
        // 경로를 다시 세워 주므로, 여기서 비워도 코어 프로바이더 목록은 그대로다.
        RegisterProviders::flushState();
        BootTimeLiveCommerceServiceProvider::resetTestState();

        // 세션 스코프는 프로세스 전역 정적 상태다 — 앞 앱의 Store 를 물려받지 않도록
        // 새 앱을 만들기 전에 비운다.
        TeeWideSessionScope::reset();

        // 앱을 만들기 **전에** services manifest 경로를 테스트 전용 파일로 돌린다.
        // 이 줄이 없으면 ProviderRepository 가 프로젝트의 bootstrap/cache/services.php 를
        // 테스트 프로바이더가 포함된 내용으로 다시 쓴다 (RegisterProviders::merge 로
        // 프로바이더 목록이 달라져 shouldRecompile() 이 참이 되기 때문).
        // 나중에 되돌리는 방식이 아니라 처음부터 다른 파일을 보게 만든다.
        $this->realServicesManifestState = $this->snapshotRealServicesManifest();
        $this->isolateServicesManifestPath();

        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $bootConfig = $this->teeWideBootConfig();

        if ($bootConfig !== null) {
            BootTimeLiveCommerceServiceProvider::$config = $bootConfig;
            BootTimeLiveCommerceServiceProvider::$pluginActive = $this->teeWideBootPluginActive();

            // 코어 프로바이더와 같은 목록·같은 단계에 넣는다. 두 번째 인자로 원래
            // bootstrap/providers.php 경로를 그대로 넘겨야 한다 — 생략하면
            // RegisterProviders 가 그 경로를 null 로 덮어써 코어 프로바이더가 통째로
            // 빠진다 (RegisterProviders::merge / mergeAdditionalProviders 참조).
            RegisterProviders::merge(
                [BootTimeLiveCommerceServiceProvider::class],
                $app->getBootstrapProvidersPath()
            );

            BootTimeLiveCommerceServiceProvider::$trace[] = 'RegisterProviders::merge 호출됨';
        }

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $this->routeCountAfterBootstrap = count($app['router']->getRoutes()->getRoutes());

        BootTimeLiveCommerceServiceProvider::$trace[] = 'bootstrap 완료 (라우트 '
            .$this->routeCountAfterBootstrap.'개)';

        if ($bootConfig !== null) {
            // RegisterProviders 는 설정이 캐시에서 온 경우 mergeAdditionalProviders() 를
            // 통째로 건너뛴다(v12.62.0). 그러면 위 merge() 가 조용히 무시되므로,
            // 그 사실이 실패 메시지에 드러나도록 기록한다.
            // (해결: 서버에서 `php artisan config:clear` 후 재실행)
            $cached = $app->bound('config_loaded_from_cache')
                && $app->make('config_loaded_from_cache') === true;

            BootTimeLiveCommerceServiceProvider::$trace[] = 'config 캐시에서 로드됨: '
                .var_export($cached, true)
                .' / 등록된 프로바이더: '
                .(implode(', ', array_keys($app->getProviders(LiveCommerceServiceProvider::class))) ?: '(없음)');
        }

        return $app;
    }

    protected function setUp(): void
    {
        // 앱이 만들어지자마자(테스트 본문 이전) 암호화 키를 고정한다.
        // encrypter 는 지연 해석되므로 이 시점이면 최초 resolve 보다 앞선다.
        // 이게 없으면 세션·쿠키를 쓰는 모든 HTTP 테스트가
        // MissingAppKeyException 으로 죽는다 (서버 1차 실행에서 12건).
        $this->afterApplicationCreated(function () {
            $this->useDeterministicTestAppKey();
        });

        parent::setUp();

        $this->seedPluginRow(ExtensionStatus::Active->value);
        $this->seedGolfifTenant();
    }

    /**
     * 공개 채널 fixture — Phase 1-B 부터 채널은 **DB 가 권위 소스**다.
     *
     * 설정의 `known_tenants` 만 믿던 시절에는 아무 것도 준비하지 않아도 `/golfif` 가
     * 열렸다. 이제는 행이 있어야 열리므로, 그 사실을 테스트가 명시적으로 만든다.
     * (마이그레이션 시더가 이미 넣지만, 무엇에 기대는지 코드에 드러나야 한다)
     */
    protected function seedGolfifTenant(): LiveTenant
    {
        return $this->seedLiveTenant([
            'slug' => 'golfif',
            'name' => '골프이프',
            'description' => '골프 용품과 라운드 준비물을 라이브로 소개하는 채널입니다.',
            'initials' => 'GI',
            'status' => LiveTenant::STATUS_ACTIVE,
        ]);
    }

    /**
     * 임의의 채널을 만든다 (slug 기준 멱등).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function seedLiveTenant(array $attributes = []): LiveTenant
    {
        $attributes = array_merge([
            'slug' => 'sample-shop',
            'name' => '샘플 채널',
            'description' => '테스트용 채널입니다.',
            'initials' => 'SC',
            'status' => LiveTenant::STATUS_ACTIVE,
        ], $attributes);

        $slug = $attributes['slug'];
        unset($attributes['slug']);

        return LiveTenant::query()->updateOrCreate(['slug' => $slug], $attributes);
    }

    /**
     * TeeWide 회원 fixture.
     *
     * 비밀번호는 반드시 해시해서 넣는다 — 평문이 들어가면 로그인 테스트가
     * 통과하지 못하고, 통과한다면 그게 더 큰 문제다.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTeeWideUser(array $attributes = []): TeeWideUser
    {
        $password = $attributes['password'] ?? self::TEST_USER_PASSWORD;
        unset($attributes['password']);

        return TeeWideUser::query()->create(array_merge([
            'email' => 'member@teewide.test',
            'name' => '테스트 회원',
            'status' => TeeWideUser::STATUS_ACTIVE,
            'password' => Hash::make($password),
        ], $attributes));
    }

    /**
     * 프로젝트 루트 경로 (앱이 아직 없을 때도 쓸 수 있어야 한다).
     */
    protected function projectBasePath(): string
    {
        return Application::inferBasePath();
    }

    /**
     * 실제 프로젝트의 services manifest 경로.
     *
     * Laravel 12 는 `.laravel/` 디렉토리가 있으면 그쪽을 bootstrap 경로로 쓴다
     * (Application::useBootstrapPath 호출부, Application.php:425-429). 같은 규칙을 따른다.
     */
    protected function realServicesManifestPath(): string
    {
        $base = $this->projectBasePath();
        $bootstrap = is_dir($base.'/.laravel') ? $base.'/.laravel' : $base.'/bootstrap';

        return $bootstrap.'/cache/services.php';
    }

    /**
     * 실제 manifest 의 존재 여부와 내용 해시.
     *
     * @return array{exists: bool, hash: string|null}
     */
    protected function snapshotRealServicesManifest(): array
    {
        $path = $this->realServicesManifestPath();

        if (! is_file($path)) {
            return ['exists' => false, 'hash' => null];
        }

        $hash = @hash_file('sha256', $path);

        return ['exists' => true, 'hash' => $hash === false ? null : $hash];
    }

    /**
     * services manifest 경로를 이 테스트 전용 임시 파일로 돌린다.
     *
     * ── 왜 APP_SERVICES_CACHE 인가 ──────────────────────────────────────────
     * `Application::getCachedServicesPath()` 는 `normalizeCachePath('APP_SERVICES_CACHE',
     * 'cache/services.php')` 를 부른다(Application.php:1283-1286). 경로를 바꾸는 공식
     * setter 는 없고 이 환경변수가 유일한 지점이다.
     *
     * ── 왜 상대경로인가 ────────────────────────────────────────────────────
     * `normalizeCachePath()` 는 값이 `/` 또는 `\` 로 시작할 때만 절대경로로 보고,
     * 아니면 `basePath()` 기준으로 붙인다(1379-1388, `$absoluteCachePathPrefixes`).
     * 즉 Windows 절대경로(`C:\...`)를 주면 basePath 뒤에 이어 붙어 깨진다.
     * 상대경로를 주면 두 플랫폼에서 모두 프로젝트 안의 같은 자리로 해석된다.
     *
     * ── 왜 고유 파일명인가 ─────────────────────────────────────────────────
     * 병렬 실행에서 프로세스끼리 같은 파일을 두고 다투면 안 된다. PID + 난수로
     * 테스트마다 새 파일을 쓴다.
     */
    protected function isolateServicesManifestPath(): void
    {
        $this->registerShutdownCleanup();

        $directory = $this->projectBasePath().'/'.self::ISOLATED_SERVICES_DIR;

        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        $filename = 'services-'.getmypid().'-'.bin2hex(random_bytes(8)).'.php';

        $this->isolatedServicesManifestPath = $directory.'/'.$filename;

        $this->setServicesCacheEnv(self::ISOLATED_SERVICES_DIR.'/'.$filename);
    }

    /**
     * APP_SERVICES_CACHE 를 설정하고 원래 값을 정확히 기록한다.
     *
     * `bootstrap/app.php` 가 `Env::disablePutenv()` 를 부르므로 putenv 는 읽히지 않는다.
     * `Illuminate\Support\Env` 의 기본 어댑터가 보는 `$_ENV` / `$_SERVER` 를 직접 쓴다.
     */
    private function setServicesCacheEnv(string $relativePath): void
    {
        $this->servicesCacheEnvBackup = [];

        foreach (['ENV', 'SERVER'] as $bucket) {
            $this->servicesCacheEnvBackup[$bucket] = $bucket === 'ENV'
                ? ['set' => array_key_exists('APP_SERVICES_CACHE', $_ENV), 'value' => $_ENV['APP_SERVICES_CACHE'] ?? null]
                : ['set' => array_key_exists('APP_SERVICES_CACHE', $_SERVER), 'value' => $_SERVER['APP_SERVICES_CACHE'] ?? null];
        }

        $_ENV['APP_SERVICES_CACHE'] = $relativePath;
        $_SERVER['APP_SERVICES_CACHE'] = $relativePath;
    }

    /**
     * 임시 manifest 를 지우고 APP_SERVICES_CACHE 를 원래 상태로 되돌린다.
     *
     * "원래 없었음" 은 키 삭제로, "원래 빈 문자열" 은 빈 문자열로 되돌린다.
     */
    protected function releaseIsolatedServicesManifest(): void
    {
        if ($this->isolatedServicesManifestPath !== null && is_file($this->isolatedServicesManifestPath)) {
            @unlink($this->isolatedServicesManifestPath);
        }

        $this->isolatedServicesManifestPath = null;

        foreach ($this->servicesCacheEnvBackup as $bucket => $state) {
            if ($state['set']) {
                if ($bucket === 'ENV') {
                    $_ENV['APP_SERVICES_CACHE'] = $state['value'];
                } else {
                    $_SERVER['APP_SERVICES_CACHE'] = $state['value'];
                }

                continue;
            }

            if ($bucket === 'ENV') {
                unset($_ENV['APP_SERVICES_CACHE']);
            } else {
                unset($_SERVER['APP_SERVICES_CACHE']);
            }
        }

        $this->servicesCacheEnvBackup = [];
    }

    /**
     * 실제 프로젝트 manifest 가 이 테스트 동안 바뀌지 않았음을 확인한다.
     *
     * 존재 여부와 해시가 모두 같아야 한다. 파일이 없었다면 계속 없어야 한다.
     */
    protected function guardRealServicesManifestUnchanged(): void
    {
        if ($this->realServicesManifestState === null) {
            return;
        }

        $before = $this->realServicesManifestState;
        $after = $this->snapshotRealServicesManifest();

        $this->realServicesManifestState = null;

        if ($before === $after) {
            return;
        }

        throw new \RuntimeException(sprintf(
            '테스트가 실제 services manifest 를 변경했습니다 (%s). 이전: %s / 이후: %s',
            $this->realServicesManifestPath(),
            $before['exists'] ? 'hash '.substr((string) $before['hash'], 0, 12) : '없음',
            $after['exists'] ? 'hash '.substr((string) $after['hash'], 0, 12) : '없음'
        ));
    }

    /**
     * 프로세스가 어떻게 끝나든 이 프로세스가 만든 임시 manifest 를 지운다.
     *
     * tearDown 이 마지막 방어선이 아니다 — 치명적 오류나 강제 종료로 tearDown 이
     * 건너뛰어질 수 있으므로 종료 훅을 하나 걸어 둔다. 자기 PID 파일만 건드리므로
     * 병렬 실행 중인 다른 프로세스의 파일을 지우지 않는다.
     */
    private function registerShutdownCleanup(): void
    {
        if (self::$shutdownCleanupRegistered) {
            return;
        }

        self::$shutdownCleanupRegistered = true;

        $pattern = $this->projectBasePath().'/'.self::ISOLATED_SERVICES_DIR.'/services-'.getmypid().'-*.php';

        register_shutdown_function(static function () use ($pattern) {
            foreach (glob($pattern) ?: [] as $leftover) {
                @unlink($leftover);
            }
        });
    }

    /**
     * 이 테스트가 쓰는 임시 manifest 의 절대 경로 (없으면 null).
     */
    protected function isolatedServicesManifestPath(): ?string
    {
        return $this->isolatedServicesManifestPath;
    }

    /**
     * 앱을 부팅하기 **전에** 찍어 둔 실제 manifest 의 지문.
     *
     * @return array{exists: bool, hash: string|null}
     */
    protected function manifestFingerprintBeforeBootstrap(): array
    {
        return $this->realServicesManifestState ?? ['exists' => false, 'hash' => null];
    }

    /**
     * 테스트 전용 APP_KEY 를 강제한다.
     *
     * 주변 환경에 키가 있어도 **덮어쓴다** — 운영 키를 테스트에 끌어다 쓰지 않기 위해서다.
     */
    protected function useDeterministicTestAppKey(): void
    {
        config(['app.key' => 'base64:'.base64_encode(self::TEST_APP_KEY_PLAINTEXT)]);

        // 이미 encrypter 가 만들어졌다면 옛 키를 들고 있으므로 버린다.
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();
    }

    /**
     * 테스트가 건드린 전역 상태를 되돌린다.
     *
     * 앱은 테스트마다 새로 만들어지지만 **파사드의 정적 resolved 인스턴스**는 프로세스
     * 전역이라 여기서 명시적으로 푼다. 파일 단독 실행과 전체 실행, 실행 순서가 달라져도
     * 결과가 같아야 한다.
     */
    protected function tearDown(): void
    {
        Crypt::clearResolvedInstances();
        Route::clearResolvedInstances();

        // 프로바이더 목록에 대한 전역 개입을 되돌린다 — 다음 테스트 클래스가
        // 이 스위트의 부팅 시점 등록을 물려받지 않도록.
        RegisterProviders::flushState();
        BootTimeLiveCommerceServiceProvider::resetTestState();
        TeeWideSessionScope::reset();

        // 임시 manifest 삭제 + APP_SERVICES_CACHE 원상 복구. 테스트가 실패하거나
        // 예외로 끝나도 tearDown 은 실행되므로 여기서 되돌린다(마지막 방어선은
        // registerShutdownCleanup() 의 종료 훅).
        $this->releaseIsolatedServicesManifest();

        // 실제 프로젝트 manifest 가 이 테스트 때문에 바뀌지 않았는지 확인한다.
        // 단언이 아니라 예외로 알린다 — 격리가 깨진 채로 다음 테스트가 이어지면
        // 오염이 누적되므로 그 자리에서 드러나야 한다.
        $this->guardRealServicesManifestUnchanged();

        $this->pluginRegistered = false;

        parent::tearDown();
    }

    /**
     * TeeWide 설정을 주입한다. 인자를 주지 않으면 "켜진 상태" 기본값.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function configureTeeWide(array $overrides = []): void
    {
        config([TeeWideConfig::KEY => static::teeWideConfigValues($overrides)]);
    }

    /**
     * "켜진 상태" 설정값. 부팅 시점 주입과 부팅 후 주입이 같은 값을 쓰도록 한 곳에 둔다.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function teeWideConfigValues(array $overrides = []): array
    {
        return array_merge([
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
            // 설정 전체를 교체하므로 표시 정보도 함께 넣는다 — 빠뜨리면
            // TeeWideConfig::tenantProfile() 이 fallback(슬러그 그대로)을 돌려줘
            // 화면이 '골프이프' 대신 'golfif' 를 출력한다.
            'tenant_profiles' => [
                'golfif' => [
                    'name' => '골프이프',
                    'description' => '골프 용품과 라운드 준비물을 라이브로 소개하는 채널입니다.',
                    'initials' => 'GI',
                ],
            ],
        ], $overrides);
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

        // 부팅 시점 등록을 기대한 스위트는 여기서 다시 등록하지 않는다 — 또 등록하면
        // 라우트가 SPA catch-all 뒤에 한 벌 더 쌓인다. 대신 부팅이 실제로 올바르게
        // 일어났는지 검사한다. 실패하면 조용히 늦게 등록하지 않고 즉시 드러낸다.
        if ($this->teeWideBootConfig() !== null) {
            $this->assertBootTimeLifecycle();
            $this->pluginRegistered = true;

            return;
        }

        if ($this->providerIsRegistered()) {
            $this->pluginRegistered = true;

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
     * 주어진 method/URL 로 실제 매칭되는 라우트 (없으면 null).
     */
    protected function matchedRoute(string $url, string $method = 'GET'): ?\Illuminate\Routing\Route
    {
        $request = \Illuminate\Http\Request::create($url, $method);

        try {
            return $this->app['router']->getRoutes()->match($request);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 주어진 method/URL 로 실제 매칭되는 라우트 이름 (없으면 null).
     *
     * ⚠ `null` 은 두 가지를 뜻할 수 있다 — 매칭 실패, 또는 **무명 라우트가 매칭됨**.
     *   `routes/web.php:29,51` 의 SPA catch-all 이 무명이라 이 구분이 중요하다.
     *   단언 실패 메시지에는 반드시 `routingDiagnostics()` 를 붙여 어느 쪽인지 남긴다.
     */
    protected function matchedRouteName(string $url, string $method = 'GET'): ?string
    {
        return $this->matchedRoute($url, $method)?->getName();
    }

    /**
     * 라우팅 상태 전체를 사람이 읽는 문자열로 — 단언 실패 원인을 한 번에 가려내기 위한 것.
     *
     * 요청 Host / 프로바이더 등록 여부 / 설정 실제 값 / 등록된 TeeWide 라우트 /
     * 실제 매칭된 라우트 / 컬렉션 내 순서를 모두 담는다.
     */
    protected function routingDiagnostics(string $url, string $method = 'GET'): string
    {
        $request = \Illuminate\Http\Request::create($url, $method);
        $lines = [];

        $lines[] = "요청: {$method} {$url} (host={$request->getHost()})";
        $lines[] = '프로바이더 등록됨: '.($this->providerIsRegistered() ? 'YES' : 'NO');
        $registered = $this->registeredLiveCommerceProviders();
        $lines[] = '프로바이더 클래스: '.($registered === []
            ? '(없음)'
            : implode(', ', array_keys($registered)));
        $lines[] = 'register/boot 횟수: '
            .BootTimeLiveCommerceServiceProvider::$registerCount.'/'
            .BootTimeLiveCommerceServiceProvider::$bootCount;
        $catchAll = $this->spaCatchAllRoute();
        $lines[] = 'SPA catch-all 순서: '.var_export($this->routeIndex($catchAll), true);
        $lines[] = 'config enabled: '.var_export(config(TeeWideConfig::KEY.'.enabled'), true);
        $lines[] = 'config root_host: '.var_export(config(TeeWideConfig::KEY.'.root_host'), true);
        $lines[] = 'config live_host: '.var_export(config(TeeWideConfig::KEY.'.live_host'), true);
        $lines[] = 'TeeWideConfig::active(): '.var_export(TeeWideConfig::active(), true);

        $teewide = $this->teeWideRoutes();
        $lines[] = 'TeeWide 라우트 '.count($teewide).'개:';
        foreach ($teewide as $route) {
            $lines[] = sprintf(
                '  #%s domain=%s uri=%s name=%s action=%s',
                var_export($this->routeIndex($route), true),
                var_export($route->getDomain(), true),
                $route->uri(),
                var_export($route->getName(), true),
                (string) $route->getActionName()
            );
        }

        $matched = $this->matchedRoute($url, $method);
        if ($matched === null) {
            $lines[] = '매칭된 라우트: 없음 (NotFound)';
        } else {
            $lines[] = sprintf(
                '매칭된 라우트: #%s domain=%s uri=%s name=%s action=%s',
                var_export($this->routeIndex($matched), true),
                var_export($matched->getDomain(), true),
                $matched->uri(),
                var_export($matched->getName(), true),
                (string) $matched->getActionName()
            );
        }

        return "\n".implode("\n", $lines)."\n".$this->lifecycleTrace();
    }

    /**
     * 부팅 시점 생명주기가 실제로 올바르게 일어났는지 증명한다.
     *
     * ⚠ `$trace` 문자열은 **판정에 쓰지 않는다.** 문자열은 손으로 써넣을 수 있어
     *   증명이 되지 못한다. 아래는 전부 구조화된 카운터와 살아 있는 레지스트리·라우터를
     *   읽는다.
     *
     * 증명하는 것:
     *   ① RegisterProviders 단계에서 등록된 인스턴스가 정확히 하나 존재한다
     *   ② 그 인스턴스가 BootTimeLiveCommerceServiceProvider 이며 동시에 운영
     *      LiveCommerceServiceProvider 다 (상속 — 운영 register/boot 경로를 그대로 탄다)
     *   ③ register/boot 이 각각 정확히 1회 실행됐다 (0회=미실행, 2회=중복)
     *   ④ boot 종료 전에 TeeWide 라우트 4개가 등록됐다
     *   ⑤ 그 등록이 bootstrap 완료보다 앞이다 (늦은 등록이 아니다)
     *   ⑥ 지금 남아 있는 라우트가 그때 그 4개이며, 이름·도메인·액션이 운영 계약대로다
     *   ⑦ 네 라우트 모두 SPA catch-all 보다 앞선다
     */
    protected function assertBootTimeLifecycle(): void
    {
        $diagnostics = $this->lifecycleTrace();

        // ① · ② 등록된 인스턴스 — 정확 키가 아니라 instanceof 로 찾는다.
        $registered = $this->registeredLiveCommerceProviders();

        $this->assertCount(
            1,
            $registered,
            'LiveCommerceServiceProvider 계열 프로바이더가 정확히 1개여야 합니다. 실제: '
                .(implode(', ', array_keys($registered)) ?: '(없음)').$diagnostics
        );

        $this->assertArrayHasKey(
            BootTimeLiveCommerceServiceProvider::class,
            $registered,
            '레지스트리 키가 구상 클래스명이어야 합니다 (Laravel 12 는 클래스명 키).'.$diagnostics
        );

        $provider = $registered[BootTimeLiveCommerceServiceProvider::class];
        $this->assertInstanceOf(LiveCommerceServiceProvider::class, $provider,
            '테스트 지원 프로바이더가 운영 프로바이더를 상속하지 않습니다 — 운영 경로를 타지 않습니다.'
                .$diagnostics);

        // ③ 실행 횟수
        $this->assertSame(1, BootTimeLiveCommerceServiceProvider::$registerCount,
            'register() 실행 횟수가 1이 아닙니다.'.$diagnostics);
        $this->assertSame(1, BootTimeLiveCommerceServiceProvider::$bootCount,
            'boot() 실행 횟수가 1이 아닙니다.'.$diagnostics);

        // ④ boot 중에 라우트가 실제로 늘었다
        $start = BootTimeLiveCommerceServiceProvider::$routeCountAtBootStart;
        $end = BootTimeLiveCommerceServiceProvider::$routeCountAtBootEnd;

        $this->assertNotNull($start, 'boot 진입 시점이 기록되지 않았습니다.'.$diagnostics);
        $this->assertNotNull($end, 'boot 종료 시점이 기록되지 않았습니다.'.$diagnostics);
        $this->assertSame($this->expectedTeeWideRouteNames(), BootTimeLiveCommerceServiceProvider::$routeNamesAtBootEnd,
            'boot 종료 시점의 TeeWide 라우트 구성이 기대와 다릅니다.'.$diagnostics);
        $this->assertSame(count($this->expectedTeeWideRouteNames()), $end - $start,
            'provider boot 중 등록된 라우트 수가 기대와 다릅니다.'.$diagnostics);

        // ⑤ 늦은 등록이 아니다 — 부팅이 끝난 뒤 등록했다면 두 값이 같아진다.
        $this->assertNotNull($this->routeCountAfterBootstrap,
            'bootstrap 완료 시점이 기록되지 않았습니다.'.$diagnostics);
        $this->assertLessThan(
            $this->routeCountAfterBootstrap,
            $end,
            'TeeWide 라우트가 bootstrap 완료 이후에 등록됐습니다 (늦은 등록).'.$diagnostics
        );

        // ⑥ 지금 남아 있는 라우트가 그때 그것들이다 — 이름·도메인·액션까지
        $expected = $this->expectedTeeWideRouteNames();

        $current = [];
        foreach ($this->teeWideRoutes() as $route) {
            $current[] = $route->getName();

            $this->assertContains($route->getDomain(), [self::ROOT_HOST, self::LIVE_HOST],
                $route->getName().' 에 TeeWide 도메인 제약이 없습니다.'.$diagnostics);
            $this->assertTrue(
                self::isTeeWideControllerAction((string) $route->getActionName()),
                $route->getName().' 액션이 운영 컨트롤러가 아닙니다: '.$route->getActionName().$diagnostics
            );
        }
        sort($current);

        $this->assertSame($expected, $current,
            '현재 라우트가 boot 시점 스냅샷과 다릅니다 (중복 등록이거나 늦게 덧붙었습니다).'.$diagnostics);

        // ⑦ SPA catch-all 보다 앞선다
        $catchAll = $this->spaCatchAllRoute();
        $this->assertNotNull($catchAll, 'SPA catch-all 을 찾지 못했습니다 — 전제가 바뀌었습니다.'.$diagnostics);

        $catchAllIndex = $this->routeIndex($catchAll);
        foreach ($this->teeWideRoutes() as $route) {
            $this->assertLessThan(
                $catchAllIndex,
                $this->routeIndex($route),
                $route->getName().' 가 SPA catch-all 뒤에 있습니다 — 영영 매칭되지 않습니다.'.$diagnostics
            );
        }
    }

    /**
     * 진단이 켜진 상태에서 등록돼야 하는 TeeWide 라우트 이름 (정렬됨).
     *
     * 제품 화면 3종 + 인증·계정 6종 + 진단 2종 = 11종.
     * 제품 화면과 인증 화면은 `TEEWIDE_DIAGNOSTICS` 와 무관하게 등록된다.
     *
     * @return array<int, string>
     */
    protected static function teeWideRouteNames(): array
    {
        return [
            'teewide.account',
            'teewide.live.home',
            'teewide.live.session',
            'teewide.live.tenant',
            'teewide.login',
            'teewide.login.store',
            'teewide.logout',
            'teewide.portal',
            'teewide.portal.session',
            'teewide.register',
            'teewide.register.store',
        ];
    }

    /**
     * 진단을 끈 상태에서 등록돼야 하는 라우트 이름.
     *
     * 인증 라우트는 진단 스위치와 무관하므로 그대로 남는다 — 빠지는 것은
     * `/_teewide/session` 두 개뿐이다.
     *
     * @return array<int, string>
     */
    protected static function teeWideProductRouteNames(): array
    {
        return [
            'teewide.account',
            'teewide.live.home',
            'teewide.live.tenant',
            'teewide.login',
            'teewide.login.store',
            'teewide.logout',
            'teewide.portal',
            'teewide.register',
            'teewide.register.store',
        ];
    }

    /**
     * 이 스위트의 부팅 설정에서 등록돼야 하는 라우트 이름.
     *
     * 진단 스위치가 꺼진 채로 부팅한 스위트는 제품 화면만 갖는다.
     *
     * @return array<int, string>
     */
    protected function expectedTeeWideRouteNames(): array
    {
        $boot = $this->teeWideBootConfig() ?? [];
        $diagnostics = ! array_key_exists('diagnostics_enabled', $boot)
            || (bool) $boot['diagnostics_enabled'];

        return $diagnostics ? self::teeWideRouteNames() : self::teeWideProductRouteNames();
    }

    /**
     * 액션이 이 플러그인의 운영 컨트롤러인가.
     *
     * 클로저 액션(route:cache 불가)과 남의 컨트롤러를 둘 다 걸러낸다.
     */
    protected static function isTeeWideControllerAction(string $action): bool
    {
        foreach ([
            'PortalController',
            'LiveController',
            'DiagnosticsController',
            'AccountController',
            // 인증 컨트롤러는 Auth\ 하위 네임스페이스다.
            'Auth\\LoginController',
            'Auth\\RegisterController',
        ] as $controller) {
            if (str_contains($action, 'Plugins\\Yutiv\\LiveCommerce\\Http\\Controllers\\'.$controller)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 부팅 생명주기 추적 기록.
     *
     * 로컬에는 vendor/ 가 없어 부팅 순서를 실행으로 확인할 수 없다. 그래서 순서를
     * 주장하는 대신 서버 실행이 스스로 기록하게 하고, 실패 메시지에 그대로 싣는다.
     * 각 항목의 라우트 수를 SPA catch-all 순서와 비교하면 routes/web.php 적재 전인지
     * 후인지가 바로 드러난다.
     */
    protected function lifecycleTrace(): string
    {
        $trace = BootTimeLiveCommerceServiceProvider::$trace;

        if ($trace === []) {
            return "\n생명주기 추적: (기록 없음 — 부팅 시점 등록이 전혀 시도되지 않았습니다)\n";
        }

        return "\n생명주기 추적:\n  ".implode("\n  ", $trace)."\n";
    }

    /**
     * 라우트 컬렉션 내 순서 (없으면 null).
     *
     * Laravel 은 등록 순서대로 훑어 **첫 일치**를 쓴다. 그래서 "우리 라우트가 SPA
     * catch-all 보다 앞에 있는가" 가 곧 매칭 결과를 결정한다.
     */
    protected function routeIndex(?\Illuminate\Routing\Route $target): ?int
    {
        if ($target === null) {
            return null;
        }

        $index = 0;
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route === $target) {
                return $index;
            }
            $index++;
        }

        return null;
    }

    /**
     * YUTIV SPA catch-all 라우트 (무명, `/{any?}`).
     */
    protected function spaCatchAllRoute(): ?\Illuminate\Routing\Route
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->uri() === '{any?}' && in_array('GET', $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * 프로바이더가 이 앱에 등록됐는가.
     */
    /**
     * LiveCommerceServiceProvider 계열 프로바이더가 앱에 등록돼 있는가.
     *
     * ⚠ 반드시 `getProviders()` 여야 한다. Laravel 12 의 `$serviceProviders` 는
     *   **구상 클래스명이 키**인 배열이고(Application.php:970-977),
     *   `getProvider()` 는 그 키를 정확히 찾는 조회다(933-938). 그래서 부모 클래스명으로
     *   물으면 서브클래스로 등록된 인스턴스를 **놓친다** — 3차 서버 실행의 28건 전부가
     *   이 한 줄 때문이었다(프로바이더는 정상 register/boot 됐는데 판정만 false).
     *   `getProviders()` 는 `Arr::where(..., instanceof)` 라 서브클래스를 찾는다(946-951).
     */
    protected function providerIsRegistered(): bool
    {
        return $this->registeredLiveCommerceProviders() !== [];
    }

    /**
     * 등록된 LiveCommerceServiceProvider 계열 인스턴스들 (구상 클래스명 => 인스턴스).
     *
     * @return array<string, \Illuminate\Support\ServiceProvider>
     */
    protected function registeredLiveCommerceProviders(): array
    {
        return $this->app->getProviders(LiveCommerceServiceProvider::class);
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
