<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Services\LiveLedgerService;

$root = dirname(__DIR__, 5);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
$meta = $db->getPdo()->query('SELECT DATABASE() db,@@port port')->fetch(PDO::FETCH_ASSOC);
if (! str_contains(str_replace('\\', '/', base_path()), '/live-workflow-browser') || $meta['db'] !== 'live_workflow_isolated_browser' || (int) $meta['port'] !== 33319) {
    exit(90);
}
$fixture = json_decode(file_get_contents(base_path('ledger040-fixture.json')), true);
$tenant = LiveTenant::where('slug', 'golfif')->firstOrFail();
$count = 0;
DB::listen(function () use (&$count) {
    $count++;
});
memory_reset_peak_usage();
$baseline = memory_get_usage(true);
$begin = hrtime(true);
$service = app(LiveLedgerService::class);
$uuid = $service->save($tenant->id, 0, '2026-01-01', '2026-01-31', 'month', $fixture['product']);
$metrics = ['milliseconds' => round((hrtime(true) - $begin) / 1e6, 2), 'queries' => $count, 'peak_bytes' => memory_get_peak_usage(true), 'peak_increment_bytes' => memory_get_peak_usage(true) - $baseline];
$first = $service->saved($tenant->id, 0, $uuid);
$last = $service->saved($tenant->id, 0, $uuid, $first['parts']);
if ((int) $first['report']->row_count !== 56920 || $first['parts'] !== 570 || (int) $last['rows']->last()->sequence !== 56920) {
    throw new RuntimeException('Large report truncated');
}
echo json_encode(['uuid' => $uuid, 'rows' => 56920, 'parts' => 570, 'first_sequence' => $first['rows']->first()->sequence, 'last_sequence' => $last['rows']->last()->sequence, 'metrics' => $metrics], JSON_PRETTY_PRINT);
