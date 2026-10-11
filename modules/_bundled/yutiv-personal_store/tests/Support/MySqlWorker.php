<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Yutiv\PersonalStore\Repositories\StoreRepository;

// 별도 프로세스 잠금 검증 전용. 운영 환경에서는 실행을 거부합니다.
$root = $argv[1];
$loader = require $root.'/vendor/autoload.php';
$loader->addPsr4('Modules\\Yutiv\\PersonalStore\\', $root.'/modules/_bundled/yutiv-personal_store/src');
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
$connection = config('database.connections.'.config('database.default'));
if (! $app->environment('testing') || ($connection['driver'] ?? null) !== 'mysql' || ! str_starts_with($connection['write']['database'] ?? $connection['database'] ?? '', 'yutiv_isolated_') || ! in_array('127.0.0.1', (array) ($connection['write']['host'] ?? $connection['host'] ?? []), true)) {
    fwrite(STDERR, 'Isolated MySQL testing environment required.');
    exit(1);
}
$repository = new StoreRepository;
try {
    if ($argv[2] === 'move') {
        $repository->move((int) $argv[3], (int) $argv[4], (int) $argv[5]);
        echo 'moved';
    } else {
        $result = $repository->processOutbox((int) $argv[3]);
        echo json_encode(['processed' => $result, 'database' => DB::connection()->getDatabaseName(), 'prefix' => DB::connection()->getTablePrefix()]);
    }
} catch (DomainException) {
    echo 'cycle-rejected';
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception));
    exit(1);
}
