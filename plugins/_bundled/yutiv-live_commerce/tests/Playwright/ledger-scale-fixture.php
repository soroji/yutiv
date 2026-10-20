<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Tests\Support\LedgerSynthetic;

$root = dirname(__DIR__, 5);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_contains(str_replace('\\', '/', base_path()), '/live-workflow-browser') || ! $app->environment('local')) {
    throw new RuntimeException('Isolated checkout required');
}
foreach ([DB::connection()->getPdo(), DB::connection()->getReadPdo()] as $pdo) {
    $meta = $pdo->query('SELECT DATABASE() db,@@port port')->fetch(PDO::FETCH_ASSOC);
    if ($meta['db'] !== 'live_workflow_isolated_browser' || (int) $meta['port'] !== 33319) {
        throw new RuntimeException('Isolated database required');
    }
}
require dirname(__DIR__).'/Support/LedgerSynthetic.php';
$tenant = LiveTenant::where('slug', 'golfif')->firstOrFail();
$repo = app(LiveRepositoryInterface::class);
if ($repo->query(E::Product, $tenant->id)->where('code', 'SCALE-ALL')->exists()) {
    throw new RuntimeException('Synthetic fixture already exists');
}
$fixture = LedgerSynthetic::seed($tenant->id);
file_put_contents(base_path('ledger040-fixture.json'), json_encode($fixture, JSON_PRETTY_PRINT));
echo json_encode($fixture,JSON_PRETTY_PRINT);
