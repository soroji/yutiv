<?php

// 실행: 격리 사본 루트에서 php plugins/_bundled/yutiv-live_commerce/tests/Playwright/fixture.php
$root = dirname(__DIR__, 5);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || ! str_contains(str_replace('\\', '/', base_path()), '/live-workflow-browser')) {
    throw new RuntimeException('격리 사본만 허용');
}
$db = DB::connection();
foreach ([$db->getPdo(), $db->getReadPdo()] as $pdo) {
    $row = $pdo->query('SELECT DATABASE() AS db, @@port AS port')->fetch(PDO::FETCH_ASSOC);
    if ($row['db'] !== 'live_workflow_isolated_browser' || (int) $row['port'] !== 33319) {
        throw new RuntimeException('격리 DB만 허용');
    }
}
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Services\LiveAccountService;
use Plugins\Yutiv\LiveCommerce\Services\LiveReceiptService;

$tenant = LiveTenant::where('slug', 'golfif')->firstOrFail();
$tenant->fill(['name' => 'Golfif', 'status' => 'active'])->save();
if (TeeWideUser::where('username', 'operator')->exists()) {
    throw new RuntimeException('기존 합성 데이터를 덮어쓰지 않습니다.');
}
$issued = app(LiveAccountService::class)->issue($tenant->id, 0, ['name' => '격리 운영자', 'username' => 'operator', 'email' => 'operator@example.test', 'role' => 'owner']);
app(LiveAccountService::class)->changePassword(TeeWideUser::findOrFail($issued['user_id']), 'Synthetic-only-1234!');
app(LiveReceiptService::class)->receive($tenant->id, $issued['user_id'], ['operation_key' => 'browser-initial', 'mode' => 'new', 'name' => '합성 골프 셔츠', 'code' => 'SYNTHETIC-INITIAL', 'description' => '격리 검증용', 'published' => false, 'sellable' => true, 'location' => '합성 창고', 'items' => [['name' => '색상: 검정 / 사이즈: M', 'price' => 10000, 'discount' => 1000, 'quantity' => 3]]]);
// 여러 페이지 재고 장부를 실제 데이터로 검증합니다.
for ($i = 1; $i <= 38; $i++) {
    app(LiveReceiptService::class)->receive($tenant->id, $issued['user_id'], ['operation_key' => 'browser-print-'.$i, 'mode' => 'new', 'name' => '다중 페이지 검증 상품 '.$i, 'code' => 'PRINT-'.$i, 'published' => false, 'sellable' => true, 'location' => '합성 창고', 'items' => [['name' => '기본', 'price' => 10000, 'discount' => 0, 'quantity' => 1]]]);
}
$hq = User::where('email', 'hq@example.test')->firstOrFail();
$role = Role::where('identifier', 'admin')->firstOrFail();
$permission = Permission::where('identifier', 'yutiv-live_commerce.management.manage')->firstOrFail();
$role->permissions()->syncWithoutDetaching([$permission->id]);
$hq->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now()]]);
echo json_encode(['tenant' => $tenant->id, 'operator' => $issued['user_id'], 'php' => PHP_VERSION, 'mysql' => $db->selectOne('SELECT VERSION() AS version')->version], JSON_UNESCAPED_UNICODE).PHP_EOL;
