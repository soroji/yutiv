<?php

require __DIR__.'/guards.php';
$count = 0;
function caseCheck(callable $run, bool $accept): void
{
    global $count;
    try {
        $run();
        $actual = true;
    } catch (StillformGuardFailure $error) {
        $actual = false;
    }
    if ($actual !== $accept) {
        fwrite(STDERR, "FAIL: guard regression\n");
        exit(1);
    }
    $count++;
}
// Synthetic values only. Shape matches config/database.php; no environment file is read.
$common = ['driver' => 'mysql', 'database' => 'yutiv_g7_testing', 'host' => 'fixture.invalid', 'username' => 'fixture'];
$inherited = $common + ['read' => ['host' => ['read.fixture.invalid']], 'write' => ['host' => ['write.fixture.invalid']]];
caseCheck(static function () use ($inherited) { sfDatabaseConfig($inherited); }, true);
caseCheck(static function () use ($common) { sfDatabaseConfig($common); }, true);
caseCheck(static function () use ($common) { sfResolvedDatabaseConfig($common); }, true);
caseCheck(static function () use ($inherited) { sfResolvedDatabaseConfig($inherited); }, false);
foreach ([['read' => [], 'write' => []], ['read' => [[], ['host' => ['candidate.fixture.invalid']]], 'write' => [[], []]]] as $sides) {
    $config = $common + $sides;
    caseCheck(static function () use ($config) { sfDatabaseConfig($config); }, true);
}
foreach (['read', 'write'] as $side) {
    foreach ([['database' => 'different_database'], ['database' => null], ['url' => 'mysql://fixture.invalid/other'], ['url' => false], ['driver' => 'sqlite'], ['host' => []], ['host' => ['valid.fixture.invalid', null]], ['username' => []], ['read' => []]] as $override) {
        $config = $inherited;
        $config[$side] = $override;
        caseCheck(static function () use ($config) { sfDatabaseConfig($config); }, false);
        $config[$side] = [[], $override]; // Every candidate must pass, not one random choice.
        caseCheck(static function () use ($config) { sfDatabaseConfig($config); }, false);
    }
    foreach ([null, 'invalid', [null], [1 => []], [[], 'host' => 'mixed.fixture.invalid']] as $invalid) {
        $config = $inherited;
        $config[$side] = $invalid;
        caseCheck(static function () use ($config) { sfDatabaseConfig($config); }, false);
    }
}
foreach ([['driver' => 'pgsql'], ['url' => 'mysql://fixture.invalid/other'], ['database' => 'different_database'], ['host' => [['nested']]]] as $override) {
    $config = array_merge($common, $override);
    caseCheck(static function () use ($config) { sfDatabaseConfig($config); }, false);
    caseCheck(static function () use ($config) { sfResolvedDatabaseConfig($config); }, false);
}
$writeOnly = $common + ['write' => []];
caseCheck(static function () use ($writeOnly) { sfDatabaseConfig($writeOnly); }, false);
$nullUrl = $common + ['url' => null];
caseCheck(static function () use ($nullUrl) { sfDatabaseConfig($nullUrl); }, true);
$emptyUrl = $common + ['url' => ''];
caseCheck(static function () use ($emptyUrl) { sfDatabaseConfig($emptyUrl); }, true);
$good = ['driver' => 'mysql', 'read' => ['database' => 'yutiv_g7_testing', 'host' => ['localhost'], 'username' => 'fixture'], 'write' => ['database' => 'yutiv_g7_testing', 'host' => ['localhost'], 'username' => 'fixture']];
caseCheck(static function () use ($good) { sfDatabaseConfig($good); }, true);
// ConnectionFactory's actual transformation for this project's explicit-side shape.
$flatWrite = array_merge($good, $good['write']);
unset($flatWrite['read'], $flatWrite['write']);
caseCheck(static function () use ($flatWrite) { sfResolvedDatabaseConfig($flatWrite); }, true);
$original = $inherited;
sfDatabaseConfig($inherited);
caseCheck(static function () use ($inherited, $original) { sfRequire($inherited === $original, 'config-not-mutated'); }, true);
foreach (['read', 'write'] as $side) {
    $bad = $good;
    $bad[$side]['database'] = 'not_the_test_database';
    caseCheck(static function () use ($bad) { sfDatabaseConfig($bad); }, false);
}
$bad = $good;
$bad['url'] = 'mysql://fixture';
caseCheck(static function () use ($bad) { sfDatabaseConfig($bad); }, false);
$payload = ['success' => true, 'data' => array_fill_keys(['company', 'representative', 'business_number', 'mail_order_number', 'address', 'phone', 'email', 'hosting', 'verification_url'], null)];
caseCheck(static function () use ($payload) { sfPublicPayload($payload); }, true);
$payload['data']['company'] = 'fixture';
caseCheck(static function () use ($payload) { sfPublicPayload($payload); }, true);
$bad = $payload;
$bad['data']['secret'] = 'fixture';
caseCheck(static function () use ($bad) { sfPublicPayload($bad); }, false);
$bad = $payload;
unset($bad['data']['company']);
caseCheck(static function () use ($bad) { sfPublicPayload($bad); }, false);
$bad = $payload;
$bad['data']['company'] = 123;
caseCheck(static function () use ($bad) { sfPublicPayload($bad); }, false);
$bad = $payload;
$bad['success'] = false;
caseCheck(static function () use ($bad) { sfPublicPayload($bad); }, false);
caseCheck(static function () { sfInside(realpath(__DIR__), __FILE__); }, true);
caseCheck(static function () { sfInside(realpath(__DIR__), dirname(__DIR__)); }, false);
foreach ([['GET', '/', true], ['POST', '/api/modules/sirsoft-ecommerce/cart/query', true], ['POST', '/api/modules/sirsoft-ecommerce/cart', false], ['POST', '/api/auth/login', false], ['DELETE', '/api/modules/sirsoft-ecommerce/cart/query', false]] as [$method, $path, $accept]) {
    caseCheck(static function () use ($method, $path) { sfRequire(sfPreviewMethod($method, $path), 'method'); }, $accept);
}
echo "PASS: {$count} guard regression cases (no Laravel, database or network).\n";
