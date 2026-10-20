<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Services\LiveCatalogService;
use Plugins\Yutiv\LiveCommerce\Services\LiveInventoryService;
use Plugins\Yutiv\LiveCommerce\Services\LiveLedgerService;

$root = dirname(__DIR__, 5);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$meta = DB::connection()->getPdo()->query('SELECT DATABASE() db,@@port port')->fetch(PDO::FETCH_ASSOC);
if (! str_contains(str_replace('\\', '/', base_path()), '/live-workflow-browser') || $meta['db'] !== 'live_workflow_isolated_browser' || (int) $meta['port'] !== 33319) {
    exit(90);
}
$tenant = LiveTenant::where('slug', 'golfif')->firstOrFail();
$r = app(LiveRepositoryInterface::class);
$catalog = app(LiveCatalogService::class);
$product = $r->query(E::Product, $tenant->id)->where('code', 'CASE-SENSITIVE')->first();
if (! $product) {
    $product = $catalog->save(E::Product, $tenant->id, 0, ['name' => '바이트 비교 합성 상품', 'code' => 'CASE-SENSITIVE']);
    $option = $catalog->save(E::Option, $tenant->id, 0, ['product_id' => $product->id, 'name' => '기본', 'price' => 0]);
    app(LiveInventoryService::class)->receive($tenant->id, 0, $option->id, 1, 'Case-Shelf', 'case-stock');
}
$option = $r->query(E::Option, $tenant->id)->where('product_id', $product->id)->firstOrFail();
$unit = $r->query(E::Unit, $tenant->id)->where('option_id', $option->id)->firstOrFail();
$result = [];
try {
    foreach (['location' => ['location' => 'case-shelf', 'state' => 'available'], 'state' => ['location' => 'Case-Shelf', 'state' => 'Available']] as $key => $change) {
        $unit->update($change);
        $report = app(LiveLedgerService::class)->report($tenant->id, 0, '2026-10-01', '2026-10-31', 'month', $product->id);
        $result[$key] = $report['stock'][$option->id]['complete'];
    }
} finally {
    $unit->update(['state' => 'available', 'location' => 'Case-Shelf']);
}
echo json_encode($result, JSON_PRETTY_PRINT);
if ($result !== ['location' => false, 'state' => false]) {
    throw new RuntimeException('SQL collation weakened strict ledger validation');
}
