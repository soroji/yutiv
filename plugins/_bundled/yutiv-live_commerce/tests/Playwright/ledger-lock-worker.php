<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Services\LiveInventoryService;

$root = dirname(__DIR__, 5);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
$meta = $db->getPdo()->query('SELECT DATABASE() db,@@port port')->fetch(PDO::FETCH_ASSOC);
if (! str_contains(str_replace('\\', '/', base_path()), '/live-workflow-browser') || $meta['db'] !== 'live_workflow_isolated_browser' || (int) $meta['port'] !== 33319) {
    exit(90);
}
$db->statement('SET SESSION innodb_lock_wait_timeout=2');
$input = json_decode(file_get_contents(base_path('ledger040-fixture.json')), true);
$tenant = LiveTenant::where('slug', 'golfif')->firstOrFail();
$result = app(LiveInventoryService::class)->receive($tenant->id, 0, $input['option'], 1, '합성 병행 입고', 'ledger-lock-'.($argv[1] ?? ''));
echo json_encode(['ok' => true, 'units' => count($result['units'] ?? [])]);
