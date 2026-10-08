<?php

// Server-side parity check against the INSTALLED Laravel factory; no PDO is resolved.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();
try {
    require __DIR__.'/guards.php';
    sfRequire(PHP_VERSION_ID >= 80200, 'php-8.2-required');
    require dirname(__DIR__, 3).'/vendor/autoload.php';
    $factory = new Illuminate\Database\Connectors\ConnectionFactory(new Illuminate\Container\Container);
    $common = ['driver' => 'mysql', 'database' => 'yutiv_g7_testing', 'host' => ['fixture.invalid'], 'username' => 'fixture'];
    $fixtures = [
        $common,
        $common + ['read' => [], 'write' => []],
        $common + ['read' => ['host' => ['read.fixture.invalid']], 'write' => ['host' => ['write.fixture.invalid']]],
        ['driver' => 'mysql', 'sticky' => true, 'url' => null, 'read' => $common, 'write' => $common],
        $common + ['read' => [[], ['host' => ['read.fixture.invalid']]], 'write' => [[], ['host' => ['write.fixture.invalid']]]],
    ];
    foreach ($fixtures as $fixture) {
        sfDatabaseConfig($fixture);
        $connection = $factory->make($fixture, 'fixture');
        sfResolvedDatabaseConfig($connection->getConfig());
        sfRequire($connection->getRawPdo() instanceof Closure, 'pdo-must-remain-lazy');
        if (isset($fixture['read'])) {
            sfRequire($connection->getRawReadPdo() instanceof Closure, 'read-pdo-must-remain-lazy');
        }
    }
    ob_end_clean();
    echo "PASS: 5 installed Laravel factory fixtures; no PDO resolved.\n";
} catch (Throwable $error) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, "FAIL: installed factory parity check (details suppressed)\n");
    exit(1);
}
