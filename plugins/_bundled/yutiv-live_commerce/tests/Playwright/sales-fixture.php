<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Services\LiveCatalogService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOrderService;
use Plugins\Yutiv\LiveCommerce\Services\LiveReturnService;

$root = dirname(__DIR__, 5);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || ! str_contains(str_replace('\\', '/', base_path()), '/live-workflow-browser')) {
    throw new RuntimeException('격리 사본 필요');
}
$db = DB::connection();
foreach ([$db->getPdo(), $db->getReadPdo()] as $pdo) {
    $row = $pdo->query('SELECT DATABASE() AS db, @@port AS port')->fetch(PDO::FETCH_ASSOC);
    if ($row['db'] !== 'live_workflow_isolated_browser' || (int) $row['port'] !== 33319) {
        throw new RuntimeException('격리 DB 필요');
    }
}
$tenant = LiveTenant::where('slug', 'golfif')->firstOrFail();
$repo = app(LiveRepositoryInterface::class);
if ($repo->query(E::Order, $tenant->id)->exists()) {
    throw new RuntimeException('이미 주문이 있어 합성 시드를 중단합니다.');
}
$actor = TeeWideUser::where('username', 'operator')->firstOrFail()->id;
$catalog = app(LiveCatalogService::class);
$product = $repo->query(E::Product, $tenant->id)->where('code', 'SYNTHETIC-INITIAL')->firstOrFail();
$product->update(['published' => true]);
$option = $repo->query(E::Option, $tenant->id)->where('product_id', $product->id)->firstOrFail();
$channel = $catalog->save(E::Channel, $tenant->id, $actor, ['name' => '합성 검증 채널', 'url' => 'https://www.youtube.com/@synthetic', 'active' => true]);
$host = $catalog->save(E::Showhost, $tenant->id, $actor, ['name' => '합성 쇼호스트', 'active' => true]);
$catalog->save(E::Bank, $tenant->id, $actor, ['bank' => '합성 은행', 'holder' => '격리 검증', 'account_number' => '000000000000', 'active' => true, 'default' => true]);
$broadcast = $catalog->save(E::Broadcast, $tenant->id, $actor, ['name' => '합성 검증 방송', 'channel_id' => $channel->id, 'state' => 'selling', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDay(), 'channel_rate' => 300, 'host_rate' => 500, 'host_shares' => [$host->id => 10000]]);
$catalog->save(E::BroadcastItem, $tenant->id, $actor, ['broadcast_id' => $broadcast->id, 'option_id' => $option->id, 'active' => true]);
$buyer = TeeWideUser::create(['tenant_id' => $tenant->id, 'name' => '격리 합성 구매자', 'email' => 'synthetic-buyer@example.test', 'password' => 'Synthetic-only-1234!', 'status' => 'active']);
$orders = app(LiveOrderService::class);
$cart = $orders->addCart($tenant->id, $buyer->id, 'browser-sales-cart', $broadcast->id, $option->id, 3, null, 'browser-sales-cart');
$result = $orders->create($tenant->id, $buyer->id, [$cart['line']], ['name' => '합성 수령인', 'phone' => '00000000000', 'address' => '격리 검증 주소'], 'browser-sales-order');
$order = $repo->query(E::Order, $tenant->id)->where('uuid', $result['order'])->firstOrFail();
$line = $repo->query(E::OrderLine, $tenant->id)->where('order_id', $order->id)->firstOrFail();
$qrs = $repo->query(E::Unit, $tenant->id)->where('order_line_id', $line->id)->orderBy('id')->pluck('uuid')->all();
$orders->deposit($tenant->id, $actor, $order->id, 27000, 'browser-sales-deposit');
$orders->ship($tenant->id, $actor, $order->id, array_slice($qrs, 0, 2), 'browser-sales-ship');
$orders->cancel($tenant->id, $actor, $line->id, 1, '합성 취소', 'browser-sales-cancel');
app(LiveReturnService::class)->receive($tenant->id, $actor, $line->id, [$qrs[0]], '합성 반품', 'browser-sales-return');
app(LiveReturnService::class)->refundRecord($tenant->id, $actor, $order->id, 18000, '합성 환불 기록, 은행 송금 없음', 'browser-sales-refund');
echo "합성 주문 27,000 / 입금 27,000 / 취소 9,000 / 반품 9,000 / 환불 기록 18,000 / 현재 순매출 9,000원 생성\n";
