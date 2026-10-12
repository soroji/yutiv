<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Repositories\LiveRepository;
use Plugins\Yutiv\LiveCommerce\Services\LiveOrderService;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Isolated test helper: no migration, no environment-file writes, no external IO.
if (getenv('APP_ENV') !== 'testing' || getenv('LIVE_TEST_ALLOW_COMMIT') !== '1') {
    exit(90);
}
$root = $argv[1] ?? '';
if (! is_file($root.'/.env.testing') || is_file($root.'/bootstrap/cache/config.php')) {
    exit(91);
}
// Each PHP process owns its provider manifest; Windows rename is not safe across shared writers.
$manifest = 'storage/framework/testing/live-worker-'.getmypid().'-services.php';
$_ENV['APP_SERVICES_CACHE'] = $_SERVER['APP_SERVICES_CACHE'] = $manifest;
putenv('APP_SERVICES_CACHE='.$manifest);
$loader = require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
try {
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).' '.basename($e->getFile()).':'.$e->getLine());
    exit(95);
}
$loader->addPsr4('Plugins\\Yutiv\\LiveCommerce\\', dirname(__DIR__, 2).'/src', true);
// Official browser installation may have left an optimized classmap. Pin worker classes to this tested source.
$source = dirname(__DIR__, 2).'/src';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source)) as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($source) + 1, -4);
    $loader->addClassMap(['Plugins\\Yutiv\\LiveCommerce\\'.str_replace(['/', '\\'], '\\', $relative) => $file->getPathname()]);
}
$config = json_decode(getenv('LIVE_TEST_CONNECTION'), true, 512, JSON_THROW_ON_ERROR);
config(['database.default' => 'mysql', 'database.connections.mysql' => $config, 'app.key' => getenv('LIVE_TEST_KEY')]);
DB::purge('mysql');
$connection = DB::connection();
foreach ([$connection->getPdo(), $connection->getReadPdo()] as $pdo) {
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== getenv('LIVE_TEST_DATABASE')) {
        exit(92);
    }
}
if (! $app->environment('testing') || ! str_contains(getenv('LIVE_TEST_DATABASE'), 'isolated')) {
    exit(93);
}
$app->bind(LiveRepositoryInterface::class, LiveRepository::class);
$input = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
try {
    $service = $app->make(LiveOrderService::class);
    $result = match ($input['action']) {
        'order' => $service->create($input['tenant'], $input['user'], [$input['cart']], ['name' => 'Synthetic concurrency'], $input['key']),
        'deposit' => $service->deposit($input['tenant'], $input['user'], $input['order'], 100000, $input['key']),
        'ship' => $service->ship($input['tenant'], $input['user'], $input['order'], [$input['qr']], $input['key']),
    };
    echo PHP_EOL.'LIVE_TEST_RESULT:'.json_encode(['status' => 200, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (HttpException $e) {
    echo PHP_EOL.'LIVE_TEST_RESULT:'.json_encode(['status' => $e->getStatusCode()], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).' '.basename($e->getFile()).':'.$e->getLine().($e instanceof ErrorException ? ' '.$e->getMessage() : ''));
    exit(94);
}
