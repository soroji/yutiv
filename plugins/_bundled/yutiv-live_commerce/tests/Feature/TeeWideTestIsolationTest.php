<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Plugins\Yutiv\LiveCommerce\Providers\LiveCommerceServiceProvider;
use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;
use Plugins\Yutiv\LiveCommerce\Tests\Support\BootTimeLiveCommerceServiceProvider;

/**
 * 테스트 격리 계약 — 테스트가 프로젝트 상태를 건드리지 않는가.
 *
 * `RegisterProviders::merge()` 로 프로바이더 목록을 바꾸면 `ProviderRepository` 가
 * services manifest 를 다시 쓴다. 그 대상이 프로젝트의 `bootstrap/cache/services.php`
 * 라면, 테스트가 끝난 뒤에도 운영 부팅이 테스트 전용 프로바이더를 읽으려 든다.
 * git status 는 깨끗해 보인다 — 그 파일은 ignore 대상이기 때문이다.
 *
 * 그래서 나중에 되돌리는 대신 **처음부터 다른 파일을 보게** 만들고, 그 사실을 여기서
 * 실제로 확인한다.
 */
class TeeWideTestIsolationTest extends PluginTestCase
{
    protected function teeWideBootConfig(): ?array
    {
        // 부팅 시점 등록 경로를 실제로 태워야 manifest 재작성 조건이 재현된다.
        return static::teeWideConfigValues();
    }

    // ── 실제 manifest 보호 ──────────────────────────────────────────────────

    public function test_실제_services_manifest_경로를_보지_않는다(): void
    {
        $used = $this->app->getCachedServicesPath();
        $real = $this->realServicesManifestPath();

        $this->assertNotSame(
            $this->normalizePath($real),
            $this->normalizePath($used),
            '테스트 앱이 프로젝트의 실제 services manifest 를 사용하고 있습니다.'
        );

        $this->assertStringContainsString(
            'storage/framework/testing/teewide',
            $this->normalizePath($used),
            '임시 manifest 가 격리 디렉토리 밖에 있습니다: '.$used
        );
    }

    public function test_부팅_전후로_실제_manifest_의_존재와_해시가_같다(): void
    {
        // setUp 이 부팅 전 상태를 기록했고, 지금은 부팅이 끝난 뒤다.
        $real = $this->realServicesManifestPath();

        $this->assertSame(
            $this->manifestFingerprintBeforeBootstrap(),
            $this->fingerprintOf($real),
            '앱 부팅이 실제 services manifest 를 변경했습니다: '.$real
        );
    }

    public function test_실제_manifest_에_테스트_프로바이더가_남지_않는다(): void
    {
        $real = $this->realServicesManifestPath();

        if (! is_file($real)) {
            // 파일 자체가 없으면 오염될 수 없다 — 그 사실을 고정한다.
            $this->assertFalse(is_file($real));

            return;
        }

        $this->assertStringNotContainsString(
            'BootTimeLiveCommerceServiceProvider',
            (string) file_get_contents($real),
            '실제 services manifest 에 테스트 전용 프로바이더가 기록됐습니다.'
        );
    }

    public function test_임시_manifest_는_이_프로세스_고유_이름을_쓴다(): void
    {
        $path = $this->isolatedServicesManifestPath();

        $this->assertNotNull($path, '임시 manifest 경로가 설정되지 않았습니다.');
        $this->assertMatchesRegularExpression(
            '#/services-'.getmypid().'-[0-9a-f]{16}\.php$#',
            $this->normalizePath($path),
            '고정된 공용 파일명은 병렬 실행에서 충돌합니다: '.$path
        );
    }

    // ── 환경 상태 ───────────────────────────────────────────────────────────

    public function test_APP_SERVICES_CACHE_는_격리_경로를_가리킨다(): void
    {
        // 상대경로여야 한다 — normalizeCachePath() 는 `/` `\` 로 시작할 때만 절대경로로
        // 보므로(Application.php:1379-1388), Windows 절대경로를 주면 basePath 뒤에 붙는다.
        $value = $_ENV['APP_SERVICES_CACHE'] ?? null;

        $this->assertIsString($value);
        $this->assertStringStartsWith('storage/framework/testing/teewide/', $value);
        $this->assertSame($value, $_SERVER['APP_SERVICES_CACHE'] ?? null);
    }

    // ── 후속 앱 누수 ────────────────────────────────────────────────────────

    public function test_뒤이어_부팅한_앱에는_테스트_프로바이더가_없다(): void
    {
        $before = $this->fingerprintOf($this->realServicesManifestPath());

        // createApplication() 이 하는 것과 같은 순서 — merge 잔재를 먼저 비운다.
        // 이 flush 가 없으면 static::$merge 가 남아 다음 앱까지 테스트 프로바이더를
        // 물려받는다. 그게 바로 막으려는 누수다.
        RegisterProviders::flushState();

        $fresh = require Application::inferBasePath().'/bootstrap/app.php';
        $fresh->make(Kernel::class)->bootstrap();

        $this->assertSame(
            [],
            $fresh->getProviders(LiveCommerceServiceProvider::class),
            '테스트 프로바이더가 후속 앱까지 따라왔습니다.'
        );

        $this->assertNull(
            $fresh->getProvider(BootTimeLiveCommerceServiceProvider::class),
            '후속 앱에 부팅용 테스트 프로바이더가 등록됐습니다.'
        );

        // 후속 앱의 부팅도 실제 manifest 를 건드리지 않아야 한다.
        $this->assertSame(
            $before,
            $this->fingerprintOf($this->realServicesManifestPath()),
            '후속 앱 부팅이 실제 services manifest 를 변경했습니다.'
        );
    }

    public function test_격리를_풀면_환경변수가_원래_상태로_돌아온다(): void
    {
        $hadEnv = array_key_exists('APP_SERVICES_CACHE', $_ENV);
        $path = $this->isolatedServicesManifestPath();

        $this->assertNotNull($path);
        $this->assertTrue($hadEnv, '격리 중에는 APP_SERVICES_CACHE 가 설정돼 있어야 합니다.');

        $this->releaseIsolatedServicesManifest();

        // 이 프로세스는 원래 APP_SERVICES_CACHE 를 갖고 있지 않았다 —
        // 따라서 해제 후에는 키 자체가 없어야 한다 (빈 문자열이 아니라).
        $this->assertArrayNotHasKey('APP_SERVICES_CACHE', $_ENV);
        $this->assertArrayNotHasKey('APP_SERVICES_CACHE', $_SERVER);

        $this->assertFileDoesNotExist($path, '해제 후에도 임시 manifest 가 남아 있습니다.');

        // 다음 테스트가 정상 동작하도록 격리를 다시 건다.
        $this->isolateServicesManifestPath();
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * @return array{exists: bool, hash: string|null}
     */
    private function fingerprintOf(string $path): array
    {
        if (! is_file($path)) {
            return ['exists' => false, 'hash' => null];
        }

        $hash = @hash_file('sha256', $path);

        return ['exists' => true, 'hash' => $hash === false ? null : $hash];
    }
}
