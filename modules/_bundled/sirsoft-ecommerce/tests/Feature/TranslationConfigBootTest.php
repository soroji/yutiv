<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class TranslationConfigBootTest extends TestCase
{
    private string $fixture;

    private string $root;

    private Filesystem $files;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 5);
        $this->fixture = sys_get_temp_dir().'/yutiv-translation-'.bin2hex(random_bytes(8));
        $this->files = new Filesystem;
        foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'vendor/composer', 'modules/sirsoft-ecommerce', 'plugins', 'templates', 'resources'] as $path) {
            $this->files->makeDirectory($this->fixture.'/'.$path, 0777, true);
        }
        foreach (['config', 'routes'] as $path) {
            $this->files->copyDirectory($this->root.'/'.$path, $this->fixture.'/'.$path);
        }
        foreach (['composer.json', 'bootstrap/providers.php', 'vendor/composer/installed.json'] as $path) {
            $this->files->copy($this->root.'/'.$path, $this->fixture.'/'.$path);
        }
        $bootstrap = $this->files->get($this->root.'/bootstrap/app.php');
        $bootstrap = str_replace('return $app;', '$app->useAppPath('.var_export($this->root.'/app', true).'); return $app;', $bootstrap);
        $this->files->put($this->fixture.'/bootstrap/app.php', $bootstrap);
        $module = $this->root.'/modules/_bundled/sirsoft-ecommerce';
        foreach (['src', 'config', 'resources'] as $path) {
            $this->files->copyDirectory($module.'/'.$path, $this->fixture.'/modules/sirsoft-ecommerce/'.$path);
        }
        foreach (['module.php', 'module.json'] as $path) {
            $this->files->copy($module.'/'.$path, $this->fixture.'/modules/sirsoft-ecommerce/'.$path);
        }
        // SQLite has no username, but the production Core guard requires a nonprivileged one.
        // This fixture-only config lets the real Core boot/loadModules path execute.
        $this->files->put($this->fixture.'/config/database.php', '<?php $c = require '.var_export($this->root.'/config/database.php', true).'; $c["connections"]["sqlite"]["username"] = "translation_test"; return $c;');
        $this->files->put($this->fixture.'/database.sqlite', '');
        $this->probe('setup');
        // No environment file exists during schema setup. Subsequent boot uses this isolated file.
        $this->files->put($this->fixture.'/.env', "APP_ENV=testing\n");
    }

    protected function tearDown(): void
    {
        // Resolved target is our unique fixture, never the shared workspace/environment.
        if (str_starts_with($this->fixture, sys_get_temp_dir().'/yutiv-translation-')) {
            $this->files->deleteDirectory($this->fixture);
        }
    }

    private function probe(string $mode, bool $configured = false): array
    {
        $env = ['APP_ENV' => 'testing', 'APP_RUNNING_IN_CONSOLE' => $mode === 'http' ? 'false' : 'true', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->fixture.'/database.sqlite',
            'APP_CONFIG_CACHE' => 'bootstrap/cache/config.php', 'APP_SERVICES_CACHE' => 'bootstrap/cache/services.php',
            'APP_PACKAGES_CACHE' => 'bootstrap/cache/packages.php', 'APP_ROUTES_CACHE' => 'bootstrap/cache/routes.php',
            'APP_EVENTS_CACHE' => 'bootstrap/cache/events.php', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'database', 'LOG_CHANNEL' => 'null', 'INSTALLER_COMPLETED' => 'false',
            'YUTIV_TRANSLATION_DRIVER' => $configured ? 'compatible' : false,
            'YUTIV_TRANSLATION_ENDPOINT' => $configured ? 'https://translation.example.test/chat/completions' : false,
            'YUTIV_TRANSLATION_MODEL' => $configured ? 'fake-model' : false, 'YUTIV_TRANSLATION_API_KEY' => $configured ? 'fake-only-key' : false];
        $process = new Process([PHP_BINARY, $this->root.'/modules/_bundled/sirsoft-ecommerce/tests/fixtures/translation-boot.php', $this->root, $this->fixture, $mode], $this->fixture, $env, null, 120);
        $process->run();
        // Fixtures contain no real secrets. Suppress raw process output even on failure.
        $this->assertTrue($process->isSuccessful(), 'Isolated boot process failed (exit '.$process->getExitCode().')');

        return json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    }

    private function assertBoot(array $result, bool $configured, bool $cached): void
    {
        $this->assertTrue($result['limiter_registered']);
        $this->assertTrue($result['before']);
        $this->assertTrue($result['after']);
        $this->assertTrue($result['module_loaded']);
        $this->assertFalse($result['legacy_translation_present']);
        $this->assertSame(['currency', 'product', 'order', 'payment', 'cart', 'review', 'limits', 'dashboard'], $result['base_keys']);
        $this->assertTrue($result['base_values_preserved']);
        $this->assertSame($configured ? 'compatible' : 'disabled', $result['driver']);
        $this->assertSame($configured, $result['configured']);
        $this->assertSame($configured, $result['fake_values_match']);
        $this->assertTrue($result['service_resolved']);
        $this->assertSame('database', $result['queue_driver']);
        $this->assertSame(240, $result['retry_after']);
        $this->assertSame($cached, $result['cached']);
        if ($result['status_command'] !== null) {
            $this->assertSame(['config_present' => true, 'status' => $configured ? 'ready' : 'disabled', 'cache_loaded' => $cached, 'queue_database' => true, 'retry_after_240' => true], $result['status_command']);
        }
    }

    public function test_console_http_cache_and_worker_keep_translation_config_without_environment(): void
    {
        foreach (['console', 'http'] as $mode) {
            $this->assertBoot($this->probe($mode), false, false);
        }
        $this->assertTrue($this->probe('cache')['cached']);
        foreach (['console', 'http', 'worker'] as $mode) {
            // Changing process env after caching must not enable the disabled provider.
            $this->assertBoot($this->probe($mode, true), false, true);
        }
    }

    public function test_fake_environment_is_cached_and_new_process_environment_cannot_replace_it(): void
    {
        foreach (['console', 'http'] as $mode) {
            $this->assertBoot($this->probe($mode, true), true, false);
        }
        $this->assertTrue($this->probe('cache', true)['cached']);
        foreach (['console', 'http', 'worker'] as $mode) {
            $this->assertBoot($this->probe($mode), true, true);
        }
    }

    public function test_old_nested_registration_is_removed_by_real_module_config_loader(): void
    {
        $path = $this->fixture.'/modules/sirsoft-ecommerce/src/Providers/EcommerceServiceProvider.php';
        $this->files->put($path, str_replace("'sirsoft-ecommerce-translation'", "'sirsoft-ecommerce.translation'", $this->files->get($path)));
        foreach (['console', 'http'] as $mode) {
            $result = $this->probe($mode);
            $this->assertTrue($result['legacy_before']);
            $this->assertFalse($result['legacy_translation_present']);
            $this->assertTrue($result['module_loaded']);
            $this->assertSame('database', $result['queue_driver']);
            $this->assertSame(240, $result['retry_after']);
        }
    }
}
