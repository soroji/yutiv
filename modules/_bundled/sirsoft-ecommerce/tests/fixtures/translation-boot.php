<?php

use App\Extension\ModuleManager;
use App\Models\Module;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Services\Translation\CatalogTranslationService;
use Modules\Sirsoft\Ecommerce\Services\Translation\TranslationProviderInterface;

// Invoked only by TranslationConfigBootTest, in a disposable application and SQLite DB.
$root = $argv[1];
$fixture = $argv[2];
$mode = $argv[3];
$loader = require $root.'/vendor/autoload.php';
$loader->addPsr4('Modules\\Sirsoft\\Ecommerce\\', $fixture.'/modules/sirsoft-ecommerce/src', true);
require $fixture.'/modules/sirsoft-ecommerce/module.php';

$app = require $fixture.'/bootstrap/app.php';
$app->afterResolving(Factory::class, fn ($http) => $http->preventStrayRequests());
$before = null;
$legacyBefore = null;
$app->booting(function () use (&$before, &$legacyBefore) {
    $before = is_array(config('sirsoft-ecommerce-translation'));
    $legacyBefore = is_array(config('sirsoft-ecommerce.translation'));
});
if ($mode === 'http') {
    $app->instance('request', Request::create('/admin/ecommerce/products', 'GET'));
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
} else {
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
}
Http::preventStrayRequests();

if ($mode === 'setup') {
    $paths = array_filter(glob($root.'/database/migrations/2026_04_01_*.php'), fn ($path) => preg_match('/000(\d+)_/', basename($path), $m) && ((int) $m[1] <= 20 || (int) $m[1] === 30));
    $paths = array_merge(array_values($paths), glob($root.'/database/migrations/*identity_policies*.php'));
    if ($kernel->call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]) !== 0) {
        throw new RuntimeException('Fixture migration failed');
    }
    Module::create(['identifier' => 'sirsoft-ecommerce', 'vendor' => 'sirsoft', 'name' => ['ko' => 'Test'], 'version' => '1.2.2', 'status' => 'active']);
    echo json_encode(['setup' => true]);
    exit;
}
if ($mode === 'cache') {
    $code = $kernel->call('config:cache');
    echo json_encode(['cached' => $code === 0 && is_file($app->getCachedConfigPath())]);
    exit;
}
if ($mode === 'worker') {
    // Actual WorkCommand bootstrap/connection; empty SQLite queue, no provider call.
    if ($kernel->call('queue:work', ['connection' => 'ecommerce-translation', '--queue' => 'ecommerce-translation', '--once' => true, '--sleep' => 0, '--timeout' => 180]) !== 0) {
        throw new RuntimeException('Worker boot failed');
    }
}
$translation = config('sirsoft-ecommerce-translation');
$provider = $app->make(TranslationProviderInterface::class);
$statusCommand = null;
if ($mode !== 'http' && ! $legacyBefore) {
    if ($kernel->call('ecommerce:translation-status') !== 0) {
        throw new RuntimeException('Translation status command failed');
    }
    $statusCommand = json_decode(trim($kernel->output()), true, 16, JSON_THROW_ON_ERROR);
}
// Output booleans/safe enums only. Never serialize configuration or a credential.
echo json_encode([
    'limiter_registered' => is_callable(RateLimiter::limiter('ecommerce-catalog-translation')),
    'before' => $before,
    'legacy_before' => $legacyBefore,
    'after' => is_array($translation),
    'legacy_translation_present' => is_array(config('sirsoft-ecommerce.translation')),
    'module_loaded' => $app->make(ModuleManager::class)->getModule('sirsoft-ecommerce') !== null,
    'base_keys' => array_keys(config('sirsoft-ecommerce', [])),
    'base_values_preserved' => config('sirsoft-ecommerce') === require $fixture.'/modules/sirsoft-ecommerce/config/ecommerce.php',
    'driver' => $translation['driver'] ?? 'missing',
    'fake_values_match' => ($translation['endpoint'] ?? null) === 'https://translation.example.test/chat/completions'
        && ($translation['model'] ?? null) === 'fake-model' && ($translation['key'] ?? null) === 'fake-only-key',
    'configured' => $provider->configured(),
    'service_resolved' => $app->make(CatalogTranslationService::class) !== null,
    'queue_driver' => config('queue.connections.ecommerce-translation.driver'),
    'retry_after' => config('queue.connections.ecommerce-translation.retry_after'),
    'cached' => $app->configurationIsCached(),
    'status_command' => $statusCommand,
]);
