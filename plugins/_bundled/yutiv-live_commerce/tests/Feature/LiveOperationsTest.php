<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Mail\LivePasswordResetMail;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Services\IncentiveCalculator;
use Plugins\Yutiv\LiveCommerce\Services\LiveAccountService;
use Plugins\Yutiv\LiveCommerce\Services\LiveCatalogService;
use Plugins\Yutiv\LiveCommerce\Services\LiveIncentiveService;
use Plugins\Yutiv\LiveCommerce\Services\LiveInventoryService;
use Plugins\Yutiv\LiveCommerce\Services\LiveLedgerService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOrderAdjustmentService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOrderService;
use Plugins\Yutiv\LiveCommerce\Services\LivePasswordResetService;
use Plugins\Yutiv\LiveCommerce\Services\LiveReturnService;
use Plugins\Yutiv\LiveCommerce\Services\LiveStatisticsService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

class LiveOperationsTest extends TeeWideMainDesignTest
{
    public function test_ledger_deposit_rechecks_are_deltas_and_review_is_not_a_second_deposit(): void
    {
        extract($this->fixture());
        $repo = app(LiveRepositoryInterface::class);
        $orders = app(LiveOrderService::class);
        app(LiveInventoryService::class)->receive($tenant->id, $user->id, $option->id, 2, '격리 창고', 'deposit-delta-stock');
        $cart = $orders->addCart($tenant->id, $user->id, 'deposit-delta-cart', $broadcast->id, $option->id, 2, null, 'deposit-delta-cart');
        $result = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => '합성 고객'], 'deposit-delta-order');
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $result['order'])->firstOrFail();
        $orders->deposit($tenant->id, $user->id, $order->id, 100000, 'deposit-delta-one');
        $orders->deposit($tenant->id, $user->id, $order->id, 200000, 'deposit-delta-two');
        $orders->resolveDeposit($tenant->id, $user->id, $order->id, 200000, '금액 재확인', 'deposit-review');
        $date = now($tenant->timezone)->format('Y-m-d');
        $report = app(LiveLedgerService::class)->report($tenant->id, $user->id, $date, $date, 'day');
        $this->assertSame(200000, array_sum(array_column($report['periods'], 'deposit')));
    }

    public function test_ledger_uses_distinct_order_deposit_cancel_return_refund_dates(): void
    {
        extract($this->fixture());
        $repo = app(LiveRepositoryInterface::class);
        $inventory = app(LiveInventoryService::class);
        $orders = app(LiveOrderService::class);
        $ledger = app(LiveLedgerService::class);
        $this->travelTo(Carbon::parse('2026-10-20 01:00:00', 'UTC'));
        // 방송 판매 가능 시간을 테스트 기간 전체로 명시합니다.
        $broadcast->fill(['sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDays(10)])->save();
        $inventory->receive($tenant->id, $user->id, $option->id, 3, '격리 창고', 'ledger-receive');
        $cart = $orders->addCart($tenant->id, $user->id, 'ledger-cart', $broadcast->id, $option->id, 3, null, 'ledger-cart');
        $result = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => '합성 고객'], 'ledger-order');
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $result['order'])->firstOrFail();
        $order->fill(['expires_at' => now()->addDays(10)])->save();
        $line = $repo->query(E::OrderLine, $tenant->id)->where('order_id', $order->id)->firstOrFail();
        $qrs = $repo->query(E::Unit, $tenant->id)->orderBy('id')->pluck('uuid')->all();
        $this->travel(1)->days();
        $orders->deposit($tenant->id, $user->id, $order->id, 300000, 'ledger-deposit');
        $this->travel(1)->days();
        $orders->ship($tenant->id, $user->id, $order->id, array_slice($qrs, 0, 2), 'ledger-ship');
        $this->travel(1)->days();
        $orders->cancel($tenant->id, $user->id, $line->id, 1, '합성 취소', 'ledger-cancel');
        $this->travel(1)->days();
        app(LiveReturnService::class)->receive($tenant->id, $user->id, $line->id, [$qrs[0]], '합성 반품', 'ledger-return');
        $this->travel(1)->days();
        app(LiveReturnService::class)->refundRecord($tenant->id, $user->id, $order->id, 200000, '합성 환불 기록', 'ledger-refund');
        $report = $ledger->report($tenant->id, $user->id, '2026-10-20', '2026-10-25', 'day');
        $this->assertSame(300000, $report['periods']['2026-10-20']['ordered']);
        $this->assertSame(300000, $report['periods']['2026-10-21']['deposit']);
        $this->assertSame(0, $report['periods']['2026-10-21']['ordered']);
        $this->assertSame(2, $report['periods']['2026-10-22']['shipped']);
        $this->assertSame(100000, $report['periods']['2026-10-23']['cancel']);
        $this->assertSame(100000, $report['periods']['2026-10-24']['return']);
        $this->assertSame(200000, $report['periods']['2026-10-25']['refund']);
        $this->assertSame(100000, array_sum($report['channels']));
        $stock = $report['stock'][$option->id];
        $this->assertSame($stock['closing'], $stock['opening'] + $stock['receive'] + $stock['ship'] + $stock['return'] + $stock['loss'] + $stock['as_in'] + $stock['as_out'] + $stock['adjustment']);
        $this->assertSame(2, $stock['closing']);
        $past = $ledger->report($tenant->id, $user->id, '2026-10-22', '2026-10-22', 'day');
        $this->assertSame(1, $past['stock'][$option->id]['closing']);
        $this->assertSame(2, array_sum(array_intersect_key($past['stock'][$option->id]['states'], array_flip(['available', 'reserved', 'returned', 'as', 'defective']))));
    }

    public function test_ledger_cross_month_orders_cash_and_deductions(): void
    {
        extract($this->fixture());
        $repo = app(LiveRepositoryInterface::class);
        $inventory = app(LiveInventoryService::class);
        $orders = app(LiveOrderService::class);
        $ledger = app(LiveLedgerService::class);
        $this->travelTo(Carbon::parse('2026-09-29 01:00:00', 'UTC'));
        // 방송 판매 가능 시간을 테스트 기간 전체로 명시합니다.
        $broadcast->fill(['sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDays(10)])->save();
        $inventory->receive($tenant->id, $user->id, $option->id, 3, '격리 창고', 'ledger-receive');
        $cart = $orders->addCart($tenant->id, $user->id, 'ledger-cart', $broadcast->id, $option->id, 3, null, 'ledger-cart');
        $result = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => '합성 고객'], 'ledger-order');
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $result['order'])->firstOrFail();
        $order->fill(['expires_at' => now()->addDays(10)])->save();
        $line = $repo->query(E::OrderLine, $tenant->id)->where('order_id', $order->id)->firstOrFail();
        $qrs = $repo->query(E::Unit, $tenant->id)->orderBy('id')->pluck('uuid')->all();
        $this->travel(1)->days();
        $orders->deposit($tenant->id, $user->id, $order->id, 300000, 'ledger-deposit');
        $this->travel(1)->days();
        $orders->ship($tenant->id, $user->id, $order->id, array_slice($qrs, 0, 2), 'ledger-ship');
        $this->travel(1)->days();
        $orders->cancel($tenant->id, $user->id, $line->id, 1, '합성 취소', 'ledger-cancel');
        $this->travel(1)->days();
        app(LiveReturnService::class)->receive($tenant->id, $user->id, $line->id, [$qrs[0]], '합성 반품', 'ledger-return');
        $this->travel(1)->days();
        app(LiveReturnService::class)->refundRecord($tenant->id, $user->id, $order->id, 200000, '합성 환불 기록', 'ledger-refund');
        $report = $ledger->report($tenant->id, $user->id, '2026-09-29', '2026-10-04', 'day');
        $this->assertSame(300000, $report['periods']['2026-09-29']['ordered']);
        $this->assertSame(300000, $report['periods']['2026-09-30']['deposit']);
        $this->assertSame(0, $report['periods']['2026-09-30']['ordered']);
        $this->assertSame(2, $report['periods']['2026-10-01']['shipped']);
        $this->assertSame(100000, $report['periods']['2026-10-02']['cancel']);
        $this->assertSame(100000, $report['periods']['2026-10-03']['return']);
        $this->assertSame(200000, $report['periods']['2026-10-04']['refund']);
        $this->assertSame(100000, array_sum($report['channels']));
        $stock = $report['stock'][$option->id];
        $this->assertSame($stock['closing'], $stock['opening'] + $stock['receive'] + $stock['ship'] + $stock['return'] + $stock['loss'] + $stock['as_in'] + $stock['as_out'] + $stock['adjustment']);
        $this->assertSame(2, $stock['closing']);
        $past = $ledger->report($tenant->id, $user->id, '2026-10-01', '2026-10-01', 'day');
        $this->assertSame(1, $past['stock'][$option->id]['closing']);
        $this->assertSame(2, array_sum(array_intersect_key($past['stock'][$option->id]['states'], array_flip(['available', 'reserved', 'returned', 'as', 'defective']))));
        $september = $ledger->report($tenant->id, $user->id, '2026-09-01', '2026-09-30', 'month');
        $october = $ledger->report($tenant->id, $user->id, '2026-10-01', '2026-10-31', 'month');
        $this->assertSame(300000, $september['periods']['2026-09']['ordered']);
        $this->assertSame(300000, $september['periods']['2026-09']['deposit']);
        $this->assertSame(0, $september['periods']['2026-09']['cancel']);
        $this->assertSame(0, $october['periods']['2026-10']['ordered']);
        $this->assertSame(0, $october['periods']['2026-10']['deposit']);
        $this->assertSame(100000, $october['periods']['2026-10']['cancel']);
        $this->assertSame(100000, $october['periods']['2026-10']['return']);
        $this->assertSame(200000, $october['periods']['2026-10']['refund']);
        $this->assertSame($september['stock_totals']['closing'], $october['stock_totals']['opening']);
        $this->assertSame(100000, $september['sales_totals']['cohort_net_sales']);
        $this->assertSame(0, $october['sales_totals']['cohort_net_sales']);
    }

    protected function fixture(): array
    {
        $tenant = $this->seedGolfifTenant();
        $user = $this->makeTeeWideUser(['tenant_id' => $tenant->id]);
        $catalog = app(LiveCatalogService::class);
        $product = $catalog->save(E::Product, $tenant->id, $user->id, ['name' => '합성 골프 클럽', 'code' => 'SYNTHETIC-ONLY', 'published' => true, 'sellable' => true]);
        $option = $catalog->save(E::Option, $tenant->id, $user->id, ['product_id' => $product->id, 'name' => '합성 옵션', 'price' => 100000, 'discount' => 0]);
        $channel = $catalog->save(E::Channel, $tenant->id, $user->id, ['name' => '합성 채널', 'url' => 'https://www.youtube.com/@synthetic']);
        $host = $catalog->save(E::Showhost, $tenant->id, $user->id, ['name' => '합성 쇼호스트']);
        $bank = $catalog->save(E::Bank, $tenant->id, $user->id, ['bank' => '합성 테스트 은행', 'account_number' => '000000000000', 'holder' => '합성 예금주', 'default' => true, 'active' => true]);
        $broadcast = $catalog->save(E::Broadcast, $tenant->id, $user->id, ['name' => '합성 방송', 'channel_id' => $channel->id, 'state' => 'selling',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'sale_starts_at' => now()->subHour(), 'sale_ends_at' => now()->addHour(),
            'channel_rate' => 300, 'host_rate' => 500, 'host_shares' => [$host->id => 10000]]);
        $catalog->save(E::BroadcastItem, $tenant->id, $user->id, ['broadcast_id' => $broadcast->id, 'option_id' => $option->id]);

        return compact('tenant', 'user', 'product', 'option', 'bank', 'channel', 'host', 'broadcast');
    }

    public function test_isolated_order_inventory_and_bank_snapshot(): void
    {
        extract($this->fixture());
        $inventory = app(LiveInventoryService::class);
        $orders = app(LiveOrderService::class);
        $repo = app(LiveRepositoryInterface::class);
        $receipt = $inventory->receive($tenant->id, $user->id, $option->id, 2, '합성 창고', 'receipt-1');
        $this->assertSame($receipt, $inventory->receive($tenant->id, $user->id, $option->id, 2, '합성 창고', 'receipt-1'));
        $cart = $orders->addCart($tenant->id, $user->id, 'fixture-cart', $broadcast->id, $option->id, 1, null, 'cart-1');
        $result = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => '합성 고객', 'address' => '합성 테스트 주소', 'phone' => '00000000000'], 'order-1');
        $this->assertSame($result, $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => '합성 고객', 'address' => '합성 테스트 주소', 'phone' => '00000000000'], 'order-1'));
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $result['order'])->firstOrFail();
        $line = $repo->query(E::OrderLine, $tenant->id)->where('order_id', $order->id)->firstOrFail();
        $this->assertSame($cart['line'], $line->uuid);
        $this->assertEquals(['channel:'.$channel->id => 3000, 'host:'.$host->id => 5000], $line->snapshot['incentives']);
        $bank->account_number = '111111111111';
        $bank->save();
        $this->assertSame('000000000000', $order->fresh()->bank_snapshot['number']);
        $this->assertSame('paid', $orders->deposit($tenant->id, $user->id, $order->id, 100000, 'deposit-1')['state']);
        $qr = $repo->query(E::Unit, $tenant->id)->where('order_line_id', $line->id)->firstOrFail()->uuid;
        $shipping = $orders->ship($tenant->id, $user->id, $order->id, [$qr], 'ship-1');
        $this->assertSame($shipping, $orders->ship($tenant->id, $user->id, $order->id, [$qr], 'ship-1'));
        $this->assertTrue($inventory->reconcile($tenant->id)['consistent']);
        $this->assertSame(1, $line->fresh()->shipped);
    }

    public function test_return_refund_and_duplicate_recovery_preserve_original_ledger(): void
    {
        extract($this->fixture());
        $inventory = app(LiveInventoryService::class);
        $orders = app(LiveOrderService::class);
        $repo = app(LiveRepositoryInterface::class);
        $incentives = app(LiveIncentiveService::class);
        $returns = app(LiveReturnService::class);
        $inventory->receive($tenant->id, $user->id, $option->id, 3, '합성 창고', 'receipt-return');
        $cart = $orders->addCart($tenant->id, $user->id, 'return-cart', $broadcast->id, $option->id, 3, null, 'return-cart');
        $result = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => '합성 고객'], 'return-order');
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $result['order'])->firstOrFail();
        $line = $repo->query(E::OrderLine, $tenant->id)->where('order_id', $order->id)->firstOrFail();
        $original = $line->snapshot;
        $orders->deposit($tenant->id, $user->id, $order->id, 300000, 'return-deposit');
        $qrs = $repo->query(E::Unit, $tenant->id)->where('order_line_id', $line->id)->orderBy('id')->pluck('uuid')->all();
        $orders->ship($tenant->id, $user->id, $order->id, $qrs, 'return-ship');
        $this->assertSame(9000, $incentives->sync($tenant->id, $line->id)['channel:'.$channel->id]['expected']);
        $orders->delivered($tenant->id, $user->id, $order->id, 'delivered-1');
        $order->refresh()->fill(['confirmed_at' => now()])->save();
        $payment = $incentives->payment($tenant->id, $user->id, $line->id, 'channel:'.$channel->id, 9000, 'payment-1');
        $this->assertSame($payment, $incentives->payment($tenant->id, $user->id, $line->id, 'channel:'.$channel->id, 9000, 'payment-1'));
        $returned = $returns->receive($tenant->id, $user->id, $line->id, [$qrs[0]], '합성 반품', 'return-1');
        $this->assertSame($returned, $returns->receive($tenant->id, $user->id, $line->id, [$qrs[0]], '합성 반품', 'return-1'));
        $before = $incentives->sync($tenant->id, $line->id);
        $this->assertSame(-3000, $before['channel:'.$channel->id]['adjustment']);
        $refund = $returns->refundRecord($tenant->id, $user->id, $order->id, 100000, '합성 금융 기록', 'refund-1');
        $this->assertSame($refund, $returns->refundRecord($tenant->id, $user->id, $order->id, 100000, '합성 금융 기록', 'refund-1'));
        $this->assertSame($before, $incentives->sync($tenant->id, $line->id));
        $count = $repo->query(E::Incentive, $tenant->id)->count();
        $incentives->sync($tenant->id, $line->id);
        $this->assertSame($count, $repo->query(E::Incentive, $tenant->id)->count());
        $adjustments = app(LiveOrderAdjustmentService::class);
        $recovery = $adjustments->recovery($tenant->id, $user->id, $line->id, 'channel:'.$channel->id, 3000, '합성 회수 확인', 'recovery-1');
        $this->assertSame($recovery, $adjustments->recovery($tenant->id, $user->id, $line->id, 'channel:'.$channel->id, 3000, '합성 회수 확인', 'recovery-1'));
        $this->assertSame(0, $incentives->sync($tenant->id, $line->id)['channel:'.$channel->id]['adjustment']);
        $this->assertEquals($original, $line->fresh()->snapshot);
        $this->assertTrue($inventory->reconcile($tenant->id)['consistent']);
    }

    public function test_same_option_distinct_sale_links_keep_line_uuid_when_reordered(): void
    {
        extract($this->fixture());
        $repo = app(LiveRepositoryInterface::class);
        $catalog = app(LiveCatalogService::class);
        $orders = app(LiveOrderService::class);
        app(LiveInventoryService::class)->receive($tenant->id, $user->id, $option->id, 2, '합성 창고', 'two-lines');
        $link = $catalog->save(E::SaleLink, $tenant->id, $user->id, ['broadcast_id' => $broadcast->id, 'channel_id' => $channel->id, 'showhost_id' => $host->id, 'option_id' => $option->id, 'contact' => 'kakao', 'active' => true]);
        $a = $orders->addCart($tenant->id, null, 'merge-cart', $broadcast->id, $option->id, 1, null, 'direct');
        $b = $orders->addCart($tenant->id, null, 'merge-cart', $broadcast->id, $option->id, 1, $link->code, 'linked');
        $orders->mergeCart($tenant->id, $user->id, 'merge-cart');
        $result = $orders->create($tenant->id, $user->id, [$b['line'], $a['line']], ['name' => '합성 고객'], 'ordered-lines');
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $result['order'])->firstOrFail();
        $lines = $repo->query(E::OrderLine, $tenant->id)->where('order_id', $order->id)->get()->keyBy('uuid');
        $this->assertCount(2, $lines);
        $this->assertNull($lines[$a['line']]->snapshot['sale_link_id']);
        $this->assertSame($link->id, $lines[$b['line']]->snapshot['sale_link_id']);
        foreach ($lines as $line) {
            $this->assertSame(1, $repo->query(E::Unit, $tenant->id)->where('order_line_id', $line->id)->where('state', 'reserved')->count());
        }
        $this->assertSame(0, $repo->query(E::Unit, $tenant->id)->where('state', 'available')->count());
    }

    public function test_account_issuance_is_scoped_hashed_and_revokes_session_version(): void
    {
        extract($this->fixture());
        $accounts = app(LiveAccountService::class);
        $repo = app(LiveRepositoryInterface::class);
        $issued = $accounts->issue($tenant->id, 999, ['name' => '합성 운영자', 'username' => 'synthetic-owner', 'role' => 'owner']);
        $staff = TeeWideUser::findOrFail($issued['user_id']);
        $this->assertTrue(Hash::check($issued['temporary_password'], $staff->password));
        $this->assertTrue($staff->must_change_password);
        $this->assertStringNotContainsString($issued['temporary_password'], $repo->query(E::Audit, $tenant->id)->get()->toJson());
        $version = $staff->session_version;
        $accounts->permission($tenant->id, 999, $staff->id, 'sales', true);
        $this->assertSame($version + 1, $staff->fresh()->session_version);
        $accounts->lock($tenant->id, 999, $staff->id, true);
        $this->assertSame('suspended', $staff->fresh()->status);
        $other = LiveTenant::create(['name' => '독립 합성 업체', 'slug' => 'other-synthetic', 'status' => 'active']);
        $same = $accounts->issue($other->id, 999, ['name' => '다른 운영자', 'username' => 'synthetic-owner', 'role' => 'owner']);
        $this->assertNotSame($issued['user_id'], $same['user_id']);
        $this->assertSame($other->id, TeeWideUser::find($same['user_id'])->tenant_id);
    }

    public function test_staff_real_login_force_change_and_permission_revocation(): void
    {
        extract($this->fixture());
        $accounts = app(LiveAccountService::class);
        $issued = $accounts->issue($tenant->id, 999, ['name' => '합성 직원', 'username' => 'synthetic-office', 'role' => 'sales']);
        $login = $this->post('http://'.self::ROOT_HOST.'/login', ['email' => 'synthetic-office', 'password' => $issued['temporary_password']]);
        $login->assertRedirect('http://'.self::ROOT_HOST.'/password/change');
        $cookie = $login->getCookie('teewide_session', false);
        $this->assertNotNull($cookie);
        $this->withUnencryptedCookie('teewide_session', $cookie->getValue())->get('http://'.self::ROOT_HOST.'/seller/products')->assertRedirect('/password/change');
        $changed = $this->withCredentials()->postJson('http://'.self::ROOT_HOST.'/customer/tasks/password', ['current_password' => $issued['temporary_password'], 'password' => 'isolated-new-password', 'password_confirmation' => 'isolated-new-password']);
        $changed->assertOk()->assertJsonPath('data.saved', true);
        $newCookie = $changed->getCookie('teewide_session', false);
        $this->assertNotNull($newCookie);
        $this->withUnencryptedCookie('teewide_session', $newCookie->getValue())->get('http://'.self::ROOT_HOST.'/seller/products')->assertOk()->assertSee('상품관리');
        $this->postJson('http://'.self::ROOT_HOST.'/seller/catalog/banks', ['bank' => '권한 없는 은행'])->assertForbidden();
        $accounts->permission($tenant->id, 999, $issued['user_id'], 'sales', false);
        $this->postJson('http://'.self::ROOT_HOST.'/seller/catalog/products', ['name' => '권한 회수 이후 상품', 'code' => 'BLOCKED'])->assertUnauthorized();
    }

    public function test_customer_registration_cannot_grant_staff_or_cross_tenant_scope(): void
    {
        $tenant = $this->seedGolfifTenant();
        $other = $this->seedLiveTenant(['slug' => 'scope-other', 'name' => '다른 합성 업체']);
        $registered = $this->post('http://'.self::ROOT_HOST.'/register', ['name' => '합성 가입', 'email' => 'synthetic-registration@example.test',
            'password' => 'isolated-customer-password', 'password_confirmation' => 'isolated-customer-password', 'terms' => '1', 'tenant_id' => $other->id, 'role' => 'owner']);
        $registered->assertRedirect();
        $user = TeeWideUser::where('email', 'synthetic-registration@example.test')->firstOrFail();
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertSame(0, app(LiveRepositoryInterface::class)->query(E::Staff, $tenant->id)->where('user_id', $user->id)->count());
        $cookie = $registered->getCookie('teewide_session', false);
        $this->withUnencryptedCookie('teewide_session', $cookie->getValue())->get('http://'.self::ROOT_HOST.'/seller')->assertForbidden();
        $tenant->status = 'suspended';
        $tenant->save();
        $this->get('http://'.self::ROOT_HOST.'/account')->assertNotFound();
    }

    public function test_upgrade_preserves_legacy_account_and_owner_without_auto_assignment(): void
    {
        $tenant = $this->seedGolfifTenant();
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_13_000004_create_live_operations.php';
        $receipts = require dirname(__DIR__, 2).'/database/migrations/2026_10_20_000005_create_live_receipts_table.php';
        $workspace = require dirname(__DIR__, 2).'/database/migrations/2026_10_21_000007_create_live_product_workspace.php';
        $workspace->down();
        $receipts->down();
        $migration->down();
        $id = DB::table('teewide_users')->insertGetId(['name' => '기존 합성 계정', 'email' => 'legacy-only@example.test', 'password' => Hash::make('isolated-legacy-password'), 'status' => 'active', 'uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $legacy = TeeWideUser::findOrFail($id);
        $tenant->refresh()->fill(['owner_user_id' => $legacy->id])->save();
        $hash = $legacy->password;
        $uuid = $legacy->uuid;
        $migration->up();
        $receipts->up();
        $workspace->up();
        $legacy->refresh();
        $this->assertNull($legacy->tenant_id);
        $this->assertSame($hash, $legacy->password);
        $this->assertSame($uuid, $legacy->uuid);
        $this->assertSame($legacy->id, $tenant->fresh()->owner_user_id);
        $this->assertSame(0, app(LiveRepositoryInterface::class)->query(E::Staff, $tenant->id)->count());
    }

    public function test_reset_mail_double_is_branded_scoped_single_use_and_never_real_delivery(): void
    {
        extract($this->fixture());
        Mail::fake();
        config(['yutiv_live_commerce.account_mail_enabled' => true, 'mail.default' => 'smtp', 'mail.from.address' => 'synthetic@example.test']);
        $reset = app(LivePasswordResetService::class);
        $reset->requestReset($tenant->id, $user->email);
        $token = null;
        Mail::assertSent(LivePasswordResetMail::class, function ($mail) use (&$token, $tenant) {
            $this->assertSame($tenant->name, $mail->brand);
            $this->assertStringStartsWith('https://'.self::ROOT_HOST.'/password/reset/', $mail->resetUrl);
            $token = basename(parse_url($mail->resetUrl, PHP_URL_PATH));

            return true;
        });
        $this->assertNotNull($token);
        $this->postJson('http://'.self::ROOT_HOST.'/customer/tasks/reset', ['token' => $token, 'password' => 'isolated-reset-password', 'password_confirmation' => 'isolated-reset-password'])->assertOk();
        $this->postJson('http://'.self::ROOT_HOST.'/customer/tasks/reset', ['token' => $token, 'password' => 'isolated-reset-password', 'password_confirmation' => 'isolated-reset-password'])->assertUnprocessable();
        $this->assertTrue(Hash::check('isolated-reset-password', $user->fresh()->password));
        $this->assertStringNotContainsString($token, app(LiveRepositoryInterface::class)->query(E::Audit, $tenant->id)->get()->toJson());
    }

    public function test_customs_is_encrypted_masked_and_not_available_to_sales(): void
    {
        extract($this->fixture());
        $accounts = app(LiveAccountService::class);
        $accounts->customs($user, 'P123456789012');
        $this->assertStringNotContainsString('P123456789012', $user->getRawOriginal('customs_code'));
        $login = $this->post('http://'.self::ROOT_HOST.'/login', ['email' => $user->email, 'password' => self::TEST_USER_PASSWORD]);
        $cookie = $login->getCookie('teewide_session', false);
        $this->withUnencryptedCookie('teewide_session', $cookie->getValue())->get('http://'.self::ROOT_HOST.'/my')->assertOk()->assertSee('P********9012')->assertDontSee('P123456789012');
        $this->withCredentials()->postJson('http://'.self::ROOT_HOST.'/seller/tasks/customs', ['user_id' => $user->id])->assertForbidden();
        $this->assertStringNotContainsString('P123456789012', app(LiveRepositoryInterface::class)->query(E::Audit, $tenant->id)->get()->toJson());
        $this->assertArrayNotHasKey('customs_code', $user->toArray());
    }

    public function test_inventory_correction_appends_history_and_rejects_stale_movement(): void
    {
        extract($this->fixture());
        $inventory = app(LiveInventoryService::class);
        $repo = app(LiveRepositoryInterface::class);
        $qr = $inventory->receive($tenant->id, $user->id, $option->id, 1, 'A', 'correction-receive')['units'][0];
        $inventory->transition($tenant->id, $user->id, $qr, 'defective', 'B', '합성 실사 분류', 'correction-adjust');
        $unit = $repo->query(E::Unit, $tenant->id)->where('uuid', $qr)->firstOrFail();
        $movement = $repo->query(E::Movement, $tenant->id)->where('unit_id', $unit->id)->latest('id')->firstOrFail();
        $result = $inventory->correct($tenant->id, $user->id, $qr, $movement->id, '합성 오분류 정정', 'correction-once');
        $this->assertSame($result, $inventory->correct($tenant->id, $user->id, $qr, $movement->id, '합성 오분류 정정', 'correction-once'));
        $this->assertSame('available', $unit->fresh()->state);
        $this->assertSame('A', $unit->fresh()->location);
        $this->assertSame('defective', $movement->fresh()->to_state);
        $this->assertSame(3, $repo->query(E::Movement, $tenant->id)->where('unit_id', $unit->id)->count());
        $this->assertTrue($inventory->reconcile($tenant->id)['consistent']);
        try {
            $inventory->correct($tenant->id, $user->id, $qr, $movement->id, '중복 정정 차단', 'different-correction');
            $this->fail('Stale movement accepted');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_expired_reservation_late_deposit_review_and_full_cancellation(): void
    {
        extract($this->fixture());
        $inventory = app(LiveInventoryService::class);
        $orders = app(LiveOrderService::class);
        $repo = app(LiveRepositoryInterface::class);
        $inventory->receive($tenant->id, $user->id, $option->id, 1, '합성', 'late-stock');
        $cart = $orders->addCart($tenant->id, $user->id, 'late', $broadcast->id, $option->id, 1, null, 'late-cart');
        $created = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => '합성'], 'late-order');
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $created['order'])->firstOrFail();
        $line = $repo->query(E::OrderLine, $tenant->id)->where('order_id', $order->id)->firstOrFail();
        $order->fill(['expires_at' => now()->subMinute()])->save();
        $this->assertSame(1, $orders->expire($tenant->id, $order->id)['released']);
        $this->assertSame(0, $orders->expire($tenant->id, $order->id)['released']);
        $this->assertSame('review', $orders->deposit($tenant->id, $user->id, $order->id, 100000, 'late-deposit')['state']);
        $this->assertSame(0, app(LiveIncentiveService::class)->sync($tenant->id, $line->id)['channel:'.$channel->id]['confirmed']);
        $this->assertSame('paid', $orders->resolveDeposit($tenant->id, $user->id, $order->id, 100000, '재고 확인', 'late-resolve')['state']);
        $this->assertSame(1, $repo->query(E::Unit, $tenant->id)->where('state', 'reserved')->count());
        $orders->cancel($tenant->id, $user->id, $line->id, 1, '합성 전량 취소', 'late-cancel');
        $this->assertSame('cancelled', $order->fresh()->delivery_state);
        $this->assertSame(1, $repo->query(E::Unit, $tenant->id)->where('state', 'available')->count());
        $returns = app(LiveReturnService::class);
        $refund = $returns->refundRecord($tenant->id, $user->id, $order->id, 100000, '합성 환불 기록', 'late-refund');
        $this->assertSame($refund, $returns->refundRecord($tenant->id, $user->id, $order->id, 100000, '합성 환불 기록', 'late-refund'));
        $this->assertTrue($inventory->reconcile($tenant->id)['consistent']);
    }

    public function test_parallel_orders_and_duplicate_deposit_shipping_use_one_stock_unit(): void
    {
        $this->assertSame('1', getenv('LIVE_TEST_ALLOW_COMMIT'), 'Run only in a disposable isolated checkout/database with LIVE_TEST_ALLOW_COMMIT=1.');
        extract($this->fixture());
        $repo = app(LiveRepositoryInterface::class);
        $orders = app(LiveOrderService::class);
        app(LiveInventoryService::class)->receive($tenant->id, $user->id, $option->id, 1, 'Synthetic', 'race-stock');
        $a = $orders->addCart($tenant->id, $user->id, 'race-a', $broadcast->id, $option->id, 1, null, 'race-a');
        $b = $orders->addCart($tenant->id, $user->id, 'race-b', $broadcast->id, $option->id, 1, null, 'race-b');
        $connection = DB::connection();
        $database = $connection->getPdo()->query('SELECT DATABASE()')->fetchColumn();
        $this->assertStringContainsString('isolated', $database);
        $this->assertFalse(app()->configurationIsCached());
        $environment = ['APP_ENV' => 'testing', 'LIVE_TEST_ALLOW_COMMIT' => '1', 'LIVE_TEST_DATABASE' => $database,
            'LIVE_TEST_CONNECTION' => json_encode($connection->getConfig(), JSON_THROW_ON_ERROR), 'LIVE_TEST_KEY' => config('app.key')];
        while ($connection->transactionLevel() > 0) {
            $connection->commit();
        }
        RefreshDatabaseState::$migrated = false;
        $run = function (array $inputs) use ($environment) {
            $processes = [];
            foreach ($inputs as $input) {
                $process = new Process([PHP_BINARY, dirname(__DIR__).'/Support/live-concurrency-worker.php', base_path(), json_encode($input, JSON_THROW_ON_ERROR)], base_path(), $environment, null, 40);
                $process->start();
                $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $output = $process->getOutput();
                $offset = strrpos($output, 'LIVE_TEST_RESULT:');
                $this->assertNotFalse($offset, 'Worker did not emit a structured result.');
                $results[] = json_decode(substr($output, $offset + strlen('LIVE_TEST_RESULT:')), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        };
        $common = ['tenant' => $tenant->id, 'user' => $user->id];
        $results = $run([array_merge($common, ['action' => 'order', 'cart' => $a['line'], 'key' => 'race-order-a']), array_merge($common, ['action' => 'order', 'cart' => $b['line'], 'key' => 'race-order-b'])]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $order = $repo->query(E::Order, $tenant->id)->firstOrFail();
        $qr = $repo->query(E::Unit, $tenant->id)->firstOrFail()->uuid;
        foreach (['deposit', 'ship'] as $action) {
            $input = array_merge($common, ['action' => $action, 'order' => $order->id, 'qr' => $qr, 'key' => 'race-'.$action]);
            $result = $run([$input, $input]);
            $this->assertSame(200, $result[0]['status']);
            $this->assertSame($result[0], $result[1]);
        }
        $this->assertSame(1, (int) $repo->query(E::OrderLine, $tenant->id)->sum('shipped'));
        $this->assertSame(1, $repo->query(E::Event, $tenant->id)->where('type', 'deposit')->count());
        $this->assertSame(1, $repo->query(E::Movement, $tenant->id)->where('type', 'ship')->count());
        $this->assertTrue(app(LiveInventoryService::class)->reconcile($tenant->id)['consistent']);
    }

    public function test_timezone_boundaries_joint_host_rounding_and_sale_window_recheck(): void
    {
        extract($this->fixture());
        $orders = app(LiveOrderService::class);
        $repo = app(LiveRepositoryInterface::class);
        app(LiveInventoryService::class)->receive($tenant->id, $user->id, $option->id, 2, '합성', 'timezone-stock');
        $cart = $orders->addCart($tenant->id, $user->id, 'timezone', $broadcast->id, $option->id, 1, null, 'timezone-cart');
        $result = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => '합성'], 'timezone-order');
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $result['order'])->firstOrFail();
        $order->fill(['created_at' => '2026-10-11 15:30:00', 'paid_at' => '2026-10-12 16:30:00', 'payment_state' => 'paid'])->save();
        $stats = app(LiveStatisticsService::class);
        $tenant->update(['timezone' => 'Asia/Seoul']);
        $seoul = $stats->summary($tenant->id, '2026-10-12', '2026-10-12');
        $this->assertSame(100000, $seoul['periods']['2026-10-12']['ordered']);
        $this->assertSame([], $stats->summary($tenant->id, '2026-10-12', '2026-10-12', 'paid_at')['periods']);
        $this->assertArrayHasKey('2026-10-13', $stats->summary($tenant->id, '2026-10-13', '2026-10-13', 'paid_at')['periods']);
        $tenant->update(['timezone' => 'Asia/Shanghai']);
        $this->assertSame([], $stats->summary($tenant->id, '2026-10-12', '2026-10-12')['periods']);
        $this->assertArrayHasKey('2026-10-05', $stats->summary($tenant->id, '2026-10-11', '2026-10-11', 'created_at', 'week')['periods']);
        $amounts = IncentiveCalculator::amounts(101, 300, 500, 1, [8 => 5000, 7 => 5000]);
        $this->assertSame(3, $amounts['channel:1']);
        $this->assertSame(3, $amounts['host:7']);
        $this->assertSame(2, $amounts['host:8']);
        $cart = $orders->addCart($tenant->id, $user->id, 'closed', $broadcast->id, $option->id, 1, null, 'closed-cart');
        $broadcast->update(['state' => 'closed']);
        try {
            $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => '합성'], 'closed-order');
            $this->fail('Closed broadcast accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(1, $repo->query(E::Unit, $tenant->id)->where('state', 'available')->count());
    }

    public function test_maintenance_skips_concurrent_lock_then_releases_expired_stock_once(): void
    {
        extract($this->fixture());
        $orders = app(LiveOrderService::class);
        $repo = app(LiveRepositoryInterface::class);
        app(LiveInventoryService::class)->receive($tenant->id, $user->id, $option->id, 1, 'Synthetic', 'maintenance-stock');
        $cart = $orders->addCart($tenant->id, $user->id, 'maintenance', $broadcast->id, $option->id, 1, null, 'maintenance-cart');
        $created = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => 'Synthetic'], 'maintenance-order');
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $created['order'])->firstOrFail();
        $order->update(['expires_at' => now()->subMinute()]);
        $connection = DB::connection();
        $database = $connection->getPdo()->query('SELECT DATABASE()')->fetchColumn();
        $lock = 'live-maintain:'.substr(hash('sha256', $database.$connection->getTablePrefix()), 0, 40);
        $config = $connection->getConfig();
        config(['database.connections.live_lock_probe' => $config]);
        $probe = DB::connection('live_lock_probe');
        $this->assertSame($database, $probe->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertSame(1, (int) $probe->selectFromWriteConnection('SELECT GET_LOCK(?, 0) AS acquired', [$lock])[0]->acquired);
        try {
            $this->artisan('live:maintain', ['--batch' => 10, '--seconds' => 2])->expectsOutputToContain('이미 처리 중')->assertExitCode(0);
            $this->assertSame('pending', $order->fresh()->payment_state);
        } finally {
            $probe->selectFromWriteConnection('SELECT RELEASE_LOCK(?)', [$lock]);
            DB::purge('live_lock_probe');
        }
        $this->artisan('live:maintain', ['--batch' => 10, '--seconds' => 2])->assertExitCode(0);
        $this->assertSame('expired', $order->fresh()->payment_state);
        $this->artisan('live:maintain', ['--batch' => 10, '--seconds' => 2])->assertExitCode(0);
        $this->assertSame(1, $repo->query(E::Movement, $tenant->id)->where('type', 'expire')->count());
        $this->assertTrue(app(LiveInventoryService::class)->reconcile($tenant->id)['consistent']);
    }

    public function test_after_sale_repair_keeps_order_and_incentives_and_is_idempotent(): void
    {
        extract($this->fixture());
        $inventory = app(LiveInventoryService::class);
        $orders = app(LiveOrderService::class);
        $repo = app(LiveRepositoryInterface::class);
        $qr = $inventory->receive($tenant->id, $user->id, $option->id, 1, 'A', 'as-stock')['units'][0];
        $cart = $orders->addCart($tenant->id, $user->id, 'as', $broadcast->id, $option->id, 1, null, 'as-cart');
        $created = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => 'Synthetic'], 'as-order');
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $created['order'])->firstOrFail();
        $line = $repo->query(E::OrderLine, $tenant->id)->where('order_id', $order->id)->firstOrFail();
        $orders->deposit($tenant->id, $user->id, $order->id, 100000, 'as-deposit');
        $orders->ship($tenant->id, $user->id, $order->id, [$qr], 'as-ship');
        $ledger = app(LiveIncentiveService::class);
        $before = $ledger->sync($tenant->id, $line->id);
        $received = $inventory->serviceUnit($tenant->id, $user->id, $qr, false, 'Repair', 'Synthetic repair', 'as-receive');
        $this->assertSame($received, $inventory->serviceUnit($tenant->id, $user->id, $qr, false, 'Repair', 'Synthetic repair', 'as-receive'));
        $this->assertSame('as', $repo->query(E::Unit, $tenant->id)->firstOrFail()->state);
        $inventory->serviceUnit($tenant->id, $user->id, $qr, true, 'Customer', 'Synthetic completion', 'as-dispatch');
        $this->assertSame('shipped', $repo->query(E::Unit, $tenant->id)->firstOrFail()->state);
        $this->assertSame(1, $line->fresh()->shipped);
        $this->assertSame(0, $line->fresh()->returned);
        $this->assertSame($before, $ledger->sync($tenant->id, $line->id));
        $this->assertTrue($inventory->reconcile($tenant->id)['consistent']);
    }
}
