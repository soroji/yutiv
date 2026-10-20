<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
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
$tenant = LiveTenant::where('slug', 'golfif')->firstOrFail();
$r = app(LiveRepositoryInterface::class);
$product = $r->query(E::Product, $tenant->id)->where('code', 'SCALE-ALL')->firstOrFail();
$options = $r->query(E::Option, $tenant->id)->where('product_id', $product->id)->select('id');
$units = $r->query(E::Unit, $tenant->id)->whereIn('option_id', clone $options)->select('id');
$lines = $r->query(E::OrderLine, $tenant->id)->whereIn('option_id', clone $options);
$orders = $r->query(E::Order, $tenant->id)->whereIn('id', (clone $lines)->select('order_id'));
$sizes = ['options' => (clone $options)->count(), 'units' => (clone $units)->count(), 'movements' => $r->query(E::Movement, $tenant->id)->whereIn('unit_id', clone $units)->count(), 'orders' => (clone $orders)->count(), 'lines' => (clone $lines)->count(), 'events' => $r->query(E::Event, $tenant->id)->whereIn('order_id', (clone $orders)->select('id'))->count()];
$count = 0;
DB::listen(function () use (&$count) {
    $count++;
});
$service = app(LiveLedgerService::class);
$metrics = [];
foreach (['day' => '2026-10-01', 'week' => '2026-10-07', 'month' => '2026-10-31'] as $period => $to) {
    $count = 0;
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);
    $begin = hrtime(true);
    $result = $service->report($tenant->id, 0, '2026-10-01', $to, $period, $product->id);
    $metrics[$period] = ['from' => '2026-10-01', 'to' => $to, 'milliseconds' => round((hrtime(true) - $begin) / 1e6, 2), 'queries' => $count, 'peak_bytes' => memory_get_peak_usage(true), 'peak_increment_bytes' => memory_get_peak_usage(true) - $baseline, 'opening' => $result['stock_totals']['opening'], 'closing' => $result['stock_totals']['closing']];
}
$count = 0;
memory_reset_peak_usage();
$baseline = memory_get_usage(true);
$begin = hrtime(true);
$uuid = $service->save($tenant->id, 0, '2026-10-01', '2026-10-31', 'month', $product->id);
$metrics['save'] = ['milliseconds' => round((hrtime(true) - $begin) / 1e6, 2), 'queries' => $count, 'peak_bytes' => memory_get_peak_usage(true), 'peak_increment_bytes' => memory_get_peak_usage(true) - $baseline];
$saved = $service->saved($tenant->id, 0, $uuid);
echo json_encode(['source_sizes' => $sizes, 'metrics' => $metrics, 'saved_rows' => $saved['report']->row_count, 'saved_parts' => $saved['parts'], 'php' => PHP_VERSION, 'mysql' => $db->selectOne('SELECT VERSION() version')->version], JSON_PRETTY_PRINT);
