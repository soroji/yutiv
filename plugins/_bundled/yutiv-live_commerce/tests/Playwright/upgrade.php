<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Services\LiveCatalogService;
use Plugins\Yutiv\LiveCommerce\Services\LiveInventoryService;
use Plugins\Yutiv\LiveCommerce\Services\LiveLedgerService;
use Plugins\Yutiv\LiveCommerce\Services\LiveReceiptService;

$root = dirname(__DIR__, 5);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_contains(str_replace('\\', '/', base_path()), '/live-workflow-upgrade')) {
    throw new RuntimeException('격리 사본 필요');
}
$db = DB::connection();
foreach ([$db->getPdo(), $db->getReadPdo()] as $pdo) {
    $row = $pdo->query('SELECT DATABASE() AS db, @@port AS port')->fetch(PDO::FETCH_ASSOC);
    if (! in_array($row['db'], ['live_workflow_isolated_upgrade', 'live_workflow_isolated_upgrade040'], true) || (int) $row['port'] !== 33319) {
        throw new RuntimeException('격리 DB 필요');
    }
}
$repo = app(LiveRepositoryInterface::class);
$tenant = LiveTenant::where('slug', 'golfif')->firstOrFail();
$file = base_path('upgrade-baseline.json');
if (($argv[1] ?? '') === 'before') {
    if (file_exists($file)) {
        throw new RuntimeException('기존 기준 데이터 덮어쓰기 금지');
    }
    if (Schema::hasTable('live_receipts')) {
        throw new RuntimeException('0.2.0 스키마가 아님');
    }
    $product = app(LiveCatalogService::class)->save(E::Product, $tenant->id, 0, ['name' => '기존 합성 상품', 'code' => 'LEGACY-020']);
    $option = app(LiveCatalogService::class)->save(E::Option, $tenant->id, 0, ['name' => '기본', 'product_id' => $product->id, 'price' => 10000]);
    app(LiveInventoryService::class)->receive($tenant->id, 0, $option->id, 3, '기존 창고', 'legacy-020-receive');
    $baseline = ['products' => $repo->query(E::Product, $tenant->id)->get()->toArray(), 'options' => $repo->query(E::Option, $tenant->id)->get()->toArray(), 'units' => $repo->query(E::Unit, $tenant->id)->get()->toArray(), 'movements' => $repo->query(E::Movement, $tenant->id)->get()->toArray()];
    file_put_contents($file, json_encode($baseline, JSON_THROW_ON_ERROR));
    echo "기존 0.2.0 상품·옵션·물품·원장 기준 저장\n";
} else {
    $baseline = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    foreach (['products' => E::Product, 'options' => E::Option, 'units' => E::Unit, 'movements' => E::Movement] as $key => $entity) {
        if ($baseline[$key] !== $repo->query($entity, $tenant->id)->whereIn('id', array_column($baseline[$key], 'id'))->get()->toArray()) {
            throw new RuntimeException($key.' 변경됨');
        }
    }
    if (! Schema::hasTable('live_receipts') || ! Schema::hasTable('live_reports') || ! Schema::hasTable('live_report_rows')) {
        throw new RuntimeException('추가 migration 검증 실패');
    }
    $option = $repo->query(E::Option, $tenant->id)->firstOrFail();
    $result = app(LiveReceiptService::class)->receive($tenant->id, 0, ['operation_key' => 'after-upgrade', 'mode' => 'existing', 'product_id' => $option->product_id, 'location' => '새 입고 창고', 'items' => [['option_id' => $option->id, 'name' => $option->name, 'price' => 10000, 'discount' => 0, 'quantity' => 2]]]);
    if ($result['quantity'] !== 2 || $repo->query(E::Unit, $tenant->id)->count() !== 5) {
        throw new RuntimeException('업데이트 후 입고 실패');
    }
    $ledger = app(LiveLedgerService::class);
    $uuid = $ledger->save($tenant->id, 0, '2026-10-01', '2026-10-31', 'month');
    $saved = $ledger->saved($tenant->id, 0, $uuid);
    if ($saved['report']->result['stock_totals']['closing'] !== 5 || ! $saved['report']->result['stock_totals']['complete']) {
        throw new RuntimeException('업데이트 후 저장 보고서/재고 대사 실패');
    }
    $migration = require base_path('plugins/_bundled/yutiv-live_commerce/database/migrations/2026_10_20_000006_create_live_reports_tables.php');
    try {
        $migration->down();
        throw new RuntimeException('저장 보고서 후진 복구를 허용했습니다.');
    } catch (RuntimeException $e) {
        if (! str_contains($e->getMessage(), '저장 보고서가 있어')) {
            throw $e;
        }
    }
    echo "PASS: 0.2.0 → 0.4.0 추가 migration, 기존 물품 QR·원장 보존, 재입고, 저장 보고서 재출력, 데이터 있는 rollback 거절\n";
}
