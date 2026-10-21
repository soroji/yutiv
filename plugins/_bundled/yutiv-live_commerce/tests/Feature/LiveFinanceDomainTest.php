<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use App\Contracts\Extension\StorageInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Services\LiveAccountService;
use Plugins\Yutiv\LiveCommerce\Services\LiveCashService;
use Plugins\Yutiv\LiveCommerce\Services\LiveCatalogService;
use Plugins\Yutiv\LiveCommerce\Services\LiveExpenseService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFifoService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFinanceAssetService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFinanceEvidenceService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFinanceReportService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFinanceSetupService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFundService;
use Plugins\Yutiv\LiveCommerce\Services\LiveInventoryService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOrderService;
use Plugins\Yutiv\LiveCommerce\Services\LiveReceiptService;
use Plugins\Yutiv\LiveCommerce\Services\LiveReturnService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

class LiveFinanceDomainTest extends TeeWideMainDesignTest
{
    public function test_finance_parallel_shipments_consume_layers_once_across_distinct_qrs(): void
    {
        $this->assertSame('1', getenv('LIVE_TEST_ALLOW_COMMIT'));
        extract($this->fixture());
        $this->activate($tenant->id, $user->id);
        $repo = app(LiveRepositoryInterface::class);
        $fund = $this->fund($tenant->id, $user->id, 'KRW');
        $bank->update(['fund_id' => $fund]);
        $orders = app(LiveOrderService::class);
        $inputs = [];
        $date = now($tenant->timezone)->format('Y-m-d');
        foreach ([1000, 5000] as $n => $cost) {
            app(LiveReceiptService::class)->receive($tenant->id, $user->id, ['operation_key' => 'race-cost-'.$n, 'mode' => 'existing', 'product_id' => $product->id, 'location' => 'Race shelf', 'items' => [['option_id' => $option->id, 'name' => $option->name, 'price' => 100000, 'discount' => 0, 'quantity' => 1, 'unit_cost' => $cost]]]);
            $cart = $orders->addCart($tenant->id, $user->id, 'finance-race-'.$n, $broadcast->id, $option->id, 1, null, 'finance-race-cart-'.$n);
            $created = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => 'Synthetic FIFO race'], 'finance-race-order-'.$n);
            $order = $repo->query(E::Order, $tenant->id)->where('uuid', $created['order'])->first();
            app(LiveCashService::class)->receipt($tenant->id, $user->id, ['operation_key' => 'finance-race-cash-'.$n, 'order_id' => $order->id, 'fund_id' => $fund, 'bank_account_id' => $bank->id, 'amount' => 100000, 'occurred_on' => $date, 'reason' => 'Actual synthetic receipt']);
            $line = $repo->query(E::OrderLine, $tenant->id)->where('order_id', $order->id)->first();
            $unit = $repo->query(E::Unit, $tenant->id)->where('order_line_id', $line->id)->first();
            $inputs[] = ['tenant' => $tenant->id, 'user' => $user->id, 'action' => 'ship', 'order' => $order->id, 'qr' => $unit->uuid, 'key' => 'finance-race-ship-'.$n];
        }
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $this->assertStringContainsString('isolated', $database);
        $environment = ['APP_ENV' => 'testing', 'LIVE_TEST_ALLOW_COMMIT' => '1', 'LIVE_TEST_DATABASE' => $database, 'LIVE_TEST_CONNECTION' => json_encode($connection->getConfig(), JSON_THROW_ON_ERROR), 'LIVE_TEST_KEY' => config('app.key')];
        while ($connection->transactionLevel() > 0) {
            $connection->commit();
        }RefreshDatabaseState::$migrated = false;
        for ($round = 0; $round < 2; $round++) {
            $workers = [];
            foreach ($inputs as $input) {
                $worker = new Process([PHP_BINARY, dirname(__DIR__).'/Support/live-concurrency-worker.php', base_path(), json_encode($input, JSON_THROW_ON_ERROR)], base_path(), $environment, null, 40);
                $worker->start();
                $workers[] = $worker;
            }foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $output = $worker->getOutput();
                $marker = strrpos($output, 'LIVE_TEST_RESULT:');
                $this->assertNotFalse($marker);
                $result = json_decode(substr($output, $marker + strlen('LIVE_TEST_RESULT:')), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(200, $result['status']);
            }
        }
        $this->assertSame(2, $repo->query(E::FifoAllocation, $tenant->id)->where('kind', 'sale')->count());
        $this->assertSame(6000, (int) $repo->query(E::FifoAllocation, $tenant->id)->sum('amount_krw'));
        $this->assertSame(0, (int) $repo->query(E::FifoLayer, $tenant->id)->sum('remaining_quantity'));
        $this->assertSame(0, (int) $repo->query(E::FifoLayer, $tenant->id)->sum('remaining_krw'));
    }

    public function test_finance_recognition_and_cash_follow_different_days_without_double_deductions(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 03:00:00', 'UTC'));
        extract($this->fixture());
        $this->activate($tenant->id, $user->id);
        $repo = app(LiveRepositoryInterface::class);
        $fund = $this->fund($tenant->id, $user->id, 'KRW');
        $bank->update(['fund_id' => $fund]);
        app(LiveReceiptService::class)->receive($tenant->id, $user->id, ['operation_key' => 'timeline-receipt', 'mode' => 'existing', 'product_id' => $product->id, 'location' => 'Timeline shelf', 'items' => [['option_id' => $option->id, 'name' => $option->name, 'price' => 100000, 'discount' => 0, 'quantity' => 2, 'unit_cost' => 2000]]]);
        $orders = app(LiveOrderService::class);
        $cart = $orders->addCart($tenant->id, $user->id, 'timeline-cart', $broadcast->id, $option->id, 2, null, 'timeline-reserve');
        $created = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => 'Synthetic timeline'], 'timeline-order');
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $created['order'])->first();
        $line = $repo->query(E::OrderLine, $tenant->id)->where('order_id', $order->id)->first();
        $unit = $repo->query(E::Unit, $tenant->id)->where('order_line_id', $line->id)->first();
        $cash = app(LiveCashService::class);
        foreach ([2 => 60000, 3 => 160000] as $day => $amount) {
            $this->travelTo(Carbon::parse('2026-10-0'.$day.' 03:00:00', 'UTC'));
            $cash->receipt($tenant->id, $user->id, ['operation_key' => 'timeline-cash-'.$day, 'order_id' => $order->id, 'fund_id' => $fund, 'bank_account_id' => $bank->id, 'amount' => $amount, 'occurred_on' => '2026-10-0'.$day, 'reason' => 'Actual partial receipt']);
        }
        $this->travelTo(Carbon::parse('2026-10-04 03:00:00', 'UTC'));
        $orders->ship($tenant->id, $user->id, $order->id, [$unit->uuid], 'timeline-ship');
        $this->travelTo(Carbon::parse('2026-10-05 03:00:00', 'UTC'));
        $orders->cancel($tenant->id, $user->id, $line->id, 1, 'Unshipped cancellation', 'timeline-cancel');
        $this->travelTo(Carbon::parse('2026-10-06 03:00:00', 'UTC'));
        app(LiveReturnService::class)->receive($tenant->id, $user->id, $line->id, [$unit->uuid], 'Actual return', 'timeline-return');
        $this->travelTo(Carbon::parse('2026-10-07 03:00:00', 'UTC'));
        $cash->refund($tenant->id, $user->id, ['operation_key' => 'timeline-refund', 'order_id' => $order->id, 'fund_id' => $fund, 'bank_account_id' => $bank->id, 'amount' => 220000, 'occurred_on' => '2026-10-07', 'reason' => 'Actual refund']);
        $reports = app(LiveFinanceReportService::class);
        $daily = $reports->report($tenant->id, $user->id, '2026-10-01', '2026-10-07', 'day');
        $this->assertSame(200000, $daily['periods']['2026-10-01']['ordered']);
        $this->assertSame(60000, $daily['periods']['2026-10-02']['deposit']);
        $this->assertSame(160000, $daily['periods']['2026-10-03']['deposit']);
        $this->assertSame(100000, $daily['periods']['2026-10-04']['revenue']);
        $this->assertSame(2000, $daily['periods']['2026-10-04']['cogs']);
        $this->assertSame(100000, $daily['periods']['2026-10-05']['cancel']);
        $this->assertSame(-100000, $daily['periods']['2026-10-06']['revenue']);
        $this->assertSame(-2000, $daily['periods']['2026-10-06']['cogs']);
        $this->assertSame(220000, $daily['periods']['2026-10-07']['refund']);
        foreach (['week', 'month'] as $period) {
            $r = $reports->report($tenant->id, $user->id, '2026-10-01', '2026-10-07', $period);
            $this->assertSame(0, $r['totals']['revenue']);
            $this->assertSame(0, $r['totals']['cogs']);
            $this->assertSame(0, $r['totals']['cash_net']);
            $this->assertSame(0, $r['totals']['known_management_subtotal']);$this->assertNull($r['totals']['management_subtotal']);
        }$this->travelBack();
    }

    public function test_finance_legacy_missing_cost_stays_unknown_in_opening_and_loss(): void
    {
        extract($this->fixture());
        $inventory = app(LiveInventoryService::class);
        $inventory->receive($tenant->id, $user->id, $option->id, 2, 'Legacy shelf', 'pre-finance-unpriced');
        $this->activate($tenant->id, $user->id);
        app(LiveFifoService::class)->prepare($tenant->id, $user->id, $option->id, 'legacy-opening');
        $repo = app(LiveRepositoryInterface::class);
        $layer = $repo->query(E::FifoLayer, $tenant->id)->first();
        $this->assertNull($layer->amount_krw);
        $this->assertNull($layer->remaining_krw);
        $unit = $repo->query(E::Unit, $tenant->id)->first();
        $inventory->transition($tenant->id, $user->id, $unit->uuid, 'lost', 'Legacy shelf', 'Identified legacy loss', 'legacy-loss');
        $this->assertNull($repo->query(E::FifoAllocation, $tenant->id)->first()->amount_krw);
        $date = now($tenant->timezone)->format('Y-m-d');
        $r = app(LiveFinanceReportService::class)->report($tenant->id, $user->id, $date, $date);
        $this->assertFalse($r['complete']);$this->assertNull($r['totals']['management_subtotal']);
        $this->assertArrayHasKey('unknown_allocations', $r['flags']);
        $this->assertSame(1, $r['assets_now']['actual']['unknown_quantity']);
    }

    public function test_finance_proofs_are_encrypted_idempotent_and_tenant_scoped(): void
    {
        $tenant = $this->seedGolfifTenant();
        $this->activate($tenant->id, 0);
        $repo = app(LiveRepositoryInterface::class);
        $category = $repo->query(E::ExpenseCategory, $tenant->id)->first();
        $date = now($tenant->timezone)->format('Y-m-d');
        $expense = app(LiveExpenseService::class)->expense($tenant->id, 0, ['operation_key' => 'proof-expense', 'category_id' => $category->id, 'occurred_on' => $date, 'currency' => 'KRW', 'amount_original' => '1000', 'exchange_rate' => '1', 'rate_date' => $date, 'rate_source' => 'synthetic', 'payment_status' => 'unpaid']);
        $blobs = [];
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('put')->willReturnCallback(function ($category, $path, $content) use (&$blobs) {
            $blobs[$path] = $content;

            return true;
        });
        $storage->method('get')->willReturnCallback(function ($category, $path) use (&$blobs) {
            return $blobs[$path] ?? null;
        });
        $storage->method('delete')->willReturnCallback(function ($category, $path) use (&$blobs) {
            unset($blobs[$path]);

            return true;
        });
        $service = new LiveFinanceEvidenceService($repo, $storage);
        $raw = "%PDF-1.4\nSynthetic private proof";
        $file = UploadedFile::fake()->createWithContent('synthetic-proof.pdf', $raw);
        $data = ['source_kind' => 'expense', 'source_id' => $expense['expense_id'], 'operation_key' => 'same-proof'];
        $created = $service->upload($tenant->id, 0, $data, $file);
        $this->assertSame($created, $service->upload($tenant->id, 0, $data, $file));
        $this->assertCount(1, $blobs);
        $this->assertStringNotContainsString($raw, array_values($blobs)[0]);
        $this->assertSame($raw, $service->download($tenant->id, $created['attachment_id'])->getContent());
        $other = $this->seedLiveTenant(['slug' => 'other-finance']);
        try {
            $service->download($other->id, $created['attachment_id']);
            $this->fail('foreign proof leaked');
        } catch (ModelNotFoundException $e) {
            $this->assertNotNull($e);
        }
    }

    public function test_finance_backdated_cash_and_cancelled_transfers_do_not_invent_available_money(): void
    {
        $tenant = $this->seedGolfifTenant();
        $this->activate($tenant->id, 0);
        $funds = app(LiveFundService::class);
        $krw = $this->fund($tenant->id, 0, 'KRW');
        $cny = $this->fund($tenant->id, 0, 'CNY', '100', '195');
        $date = now($tenant->timezone)->format('Y-m-d');
        $transfer = $funds->transfer($tenant->id, 0, ['operation_key' => 'cancel-transfer', 'from_fund_id' => $krw, 'to_fund_id' => $cny, 'amount_out' => '195000', 'amount_in' => '1000', 'exchange_rate' => '195', 'fee_original' => '500', 'fee_currency' => 'KRW', 'fee_fund_id' => $krw, 'requested_on' => $date]);
        $funds->transferStep($tenant->id, 0, $transfer['transfer_id'], 'withdraw', ['operation_key' => 'withdraw-cancel', 'occurred_on' => $date, 'reason' => 'actual withdrawal']);
        $funds->transferStep($tenant->id, 0, $transfer['transfer_id'], 'cancel', ['operation_key' => 'request-cancel', 'occurred_on' => $date, 'reason' => 'pending return']);
        $this->assertSame(80450000, $funds->balance($tenant->id, $krw));
        $this->assertSame(10000, $funds->balance($tenant->id, $cny));
        $funds->transferStep($tenant->id, 0, $transfer['transfer_id'], 'cancel-confirm', ['operation_key' => 'returned-principal', 'occurred_on' => $date, 'amount_original' => '195000', 'reason' => 'principal returned']);
        $this->assertSame(99950000, $funds->balance($tenant->id, $krw));
        try {
            $funds->entry($tenant->id, 0, $krw, '2000-01-01', 'payment', -100, -1, 'invalid-backdated', 'synthetic rejection');
            $this->fail('future opening funds used in past');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_finance_http_sections_permissions_and_saved_print(): void
    {
        $tenant = $this->seedGolfifTenant();
        $accounts = app(LiveAccountService::class);
        $issued = $accounts->issue($tenant->id, 0, ['name' => 'Isolated finance', 'username' => 'finance-operator', 'role' => 'finance']);
        $user = TeeWideUser::findOrFail($issued['user_id']);
        $accounts->changePassword($user, 'Synthetic-only-1234!');
        $login = $this->post('http://'.self::ROOT_HOST.'/login', ['email' => $issued['username'], 'password' => 'Synthetic-only-1234!']);
        $login->assertRedirect();
        $this->withUnencryptedCookie('teewide_session', $login->getCookie('teewide_session', false)->getValue());
        $this->activate($tenant->id, 0);
        $fund=$this->fund($tenant->id, 0, 'KRW');
        foreach (['overview', 'sales', 'expenses', 'accounts', 'assets', 'reports'] as $section) {
            $this->get('http://'.self::ROOT_HOST.'/seller/accounting/'.$section)->assertOk();
        }
        $this->withCredentials()->postJson('http://'.self::ROOT_HOST.'/seller/accounting/actions/close', ['operation_key' => 'unauthorized-close'])->assertForbidden();
        $date = now($tenant->timezone)->format('Y-m-d');
        $this->postJson('http://'.self::ROOT_HOST.'/seller/accounting/actions/fund-adjust',['operation_key'=>'invalid-krw-rate','fund_id'=>$fund,'kind'=>'adjustment','direction'=>'in','amount_original'=>'10','exchange_rate'=>'2','occurred_on'=>$date,'reason'=>'Invalid native rate'])->assertStatus(422);
        $this->postJson('http://'.self::ROOT_HOST.'/seller/accounting/actions/payment',['operation_key'=>'invalid-krw-fraction','fund_id'=>$fund,'expense_id'=>999999,'amount_original'=>'1.5','paid_on'=>$date])->assertStatus(422);
        $this->postJson('http://'.self::ROOT_HOST.'/seller/accounting/actions/transfer',['operation_key'=>'missing-fee-fund','from_fund_id'=>$fund,'to_fund_id'=>999999,'amount_out'=>'1000','amount_in'=>'999','exchange_rate'=>'1','fee_original'=>'1','fee_currency'=>'KRW','requested_on'=>$date])->assertStatus(422);
        $category=app(LiveRepositoryInterface::class)->query(E::ExpenseCategory,$tenant->id)->first();
        $this->postJson('http://'.self::ROOT_HOST.'/seller/accounting/actions/recurring',['operation_key'=>'invalid-zero-rule','category_id'=>$category->id,'currency'=>'KRW','amount_original'=>'0','day'=>31,'starts_on'=>$date,'active'=>true])->assertStatus(422);
        $saved = $this->postJson('http://'.self::ROOT_HOST.'/seller/accounting/actions/report', ['operation_key' => 'save-ui-report', 'from' => $date, 'to' => $date, 'period' => 'day'])->assertOk()->json('data');
        $this->get('http://'.self::ROOT_HOST.$saved['return_url'])->assertOk()->assertSee('FIFO')->assertSee('업무용 관리 자료');
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

    private function activate(int $tenant, int $actor): void
    {
        app(LiveFinanceSetupService::class)->activate($tenant, $actor, ['operation_key' => 'activate-finance', 'reason' => '출고 기준과 실제 보유 기초 확정']);
    }

    private function fund(int $tenant, int $actor, string $currency, string $amount = '1000000', string $rate = '1'): int
    {
        $funds = app(LiveFundService::class);
        $id = $funds->fund($tenant, $actor, ['operation_key' => 'fund-'.$currency, 'name' => '합성 '.$currency, 'currency' => $currency, 'active' => true])['fund_id'];
        $funds->adjust($tenant, $actor, ['operation_key' => 'opening-'.$currency, 'fund_id' => $id, 'kind' => 'opening', 'direction' => 'in', 'amount_original' => $amount, 'exchange_rate' => $rate, 'occurred_on' => now()->format('Y-m-d'), 'reason' => '격리 기초 잔액']);

        return $id;
    }

    public function test_finance_expense_partial_payments_recoveries_and_transfers_are_separate(): void
    {
        $tenant = $this->seedGolfifTenant();
        $this->activate($tenant->id, 0);
        $repo = app(LiveRepositoryInterface::class);
        $funds = app(LiveFundService::class);
        $expenses = app(LiveExpenseService::class);
        $krw = $this->fund($tenant->id, 0, 'KRW');
        $cny = $this->fund($tenant->id, 0, 'CNY', '1000', '195');
        $category = $repo->query(E::ExpenseCategory, $tenant->id)->where('code', 'salary')->first();
        $date = now($tenant->timezone)->format('Y-m-d');
        $expense = $expenses->expense($tenant->id, 0, ['operation_key' => 'expense-cny', 'category_id' => $category->id, 'occurred_on' => $date, 'currency' => 'CNY', 'amount_original' => '0.03', 'exchange_rate' => '3333.333333', 'rate_date' => $date, 'rate_source' => 'manual-test', 'payment_status' => 'unpaid']);
        $payments = [];
        for ($n = 0; $n < 3; $n++) {
            $payments[] = $expenses->pay($tenant->id, 0, ['operation_key' => 'part-'.$n, 'expense_id' => $expense['expense_id'], 'fund_id' => $cny, 'paid_on' => $date, 'amount_original' => '0.01']);
        }$this->assertSame([33, 34, 33], $repo->query(E::ExpensePayment, $tenant->id)->orderBy('id')->pluck('amount_krw')->all());
        $this->assertSame(99997, $funds->balance($tenant->id, $cny));
        foreach ($payments as $n => $payment) {
            $expenses->recover($tenant->id, 0, ['operation_key' => 'recover-'.$n, 'payment_id' => $payment['payment_id'], 'fund_id' => $cny, 'recovered_on' => $date, 'amount_original' => '0.01', 'reason' => '부분 회수 원금 대사']);
        }$this->assertSame(100000, $funds->balance($tenant->id, $cny));
        $this->assertSame(100, (int) $repo->query(E::ExpenseRecovery, $tenant->id)->sum('amount_krw'));
        $transfer = $funds->transfer($tenant->id, 0, ['operation_key' => 'transfer-one', 'from_fund_id' => $krw, 'to_fund_id' => $cny, 'amount_out' => '195000', 'amount_in' => '1000', 'exchange_rate' => '195', 'fee_original' => '500', 'fee_currency' => 'KRW', 'fee_fund_id' => $krw, 'requested_on' => $date]);
        $this->assertSame(100000, $funds->balance($tenant->id, $cny));
        $funds->transferStep($tenant->id, 0, $transfer['transfer_id'], 'withdraw', ['operation_key' => 'withdraw-one', 'occurred_on' => $date, 'reason' => '수동 출금']);
        $this->assertSame(80450000, $funds->balance($tenant->id, $krw));
        $this->assertSame(100000, $funds->balance($tenant->id, $cny));
        $funds->transferStep($tenant->id, 0, $transfer['transfer_id'], 'receive', ['operation_key' => 'receive-one', 'occurred_on' => $date, 'amount_original' => '999.99', 'exchange_rate' => '195', 'reason' => '실제 수령']);
        $this->assertSame(199999, $funds->balance($tenant->id, $cny));
        $this->assertSame(1, $repo->query(E::Expense, $tenant->id)->count());
        $this->assertSame(1, $repo->query(E::FundEntry, $tenant->id)->where('kind', 'fee')->count());
        $receiver=$funds->fund($tenant->id,0,['operation_key'=>'receiver-fee-fund','name'=>'Net CNY receiver','currency'=>'CNY','active'=>true])['fund_id'];
        $transfer=$funds->transfer($tenant->id,0,['operation_key'=>'receiver-fee-transfer','from_fund_id'=>$krw,'to_fund_id'=>$receiver,'amount_out'=>'195000','amount_in'=>'999','exchange_rate'=>'195','fee_original'=>'1','fee_currency'=>'CNY','fee_fund_id'=>$receiver,'requested_on'=>$date]);
        $funds->transferStep($tenant->id,0,$transfer['transfer_id'],'withdraw',['operation_key'=>'receiver-fee-withdraw','occurred_on'=>$date,'reason'=>'Actual source withdrawal']);$this->assertSame(0,$funds->balance($tenant->id,$receiver));
        $confirmation=['operation_key'=>'receiver-fee-receive','occurred_on'=>$date,'amount_original'=>'999','exchange_rate'=>'195','reason'=>'Actual net receiver confirmation'];$funds->transferStep($tenant->id,0,$transfer['transfer_id'],'receive',$confirmation);$funds->transferStep($tenant->id,0,$transfer['transfer_id'],'receive',$confirmation);$this->assertSame(99900,$funds->balance($tenant->id,$receiver));$this->assertSame(-195,(int)$repo->query(E::FundEntry,$tenant->id)->where('source_key','transfer-fee:'.$transfer['transfer_id'])->value('amount_krw'));$this->assertSame(1,$repo->query(E::Expense,$tenant->id)->count());
    }

    public function test_finance_fifo_is_separate_from_actual_qr_and_returns_restore_original_allocation(): void
    {
        extract($this->fixture());
        $this->activate($tenant->id, $user->id);
        $repo = app(LiveRepositoryInterface::class);
        $cash = app(LiveCashService::class);
        $orders = app(LiveOrderService::class);
        $fund = $this->fund($tenant->id, $user->id, 'KRW');
        $bank->update(['fund_id' => $fund]);
        foreach ([1000, 5000] as $index => $cost) {
            app(LiveReceiptService::class)->receive($tenant->id, $user->id, ['operation_key' => 'fifo-batch-'.$index, 'mode' => 'existing', 'product_id' => $product->id, 'location' => '합성 창고', 'items' => [['option_id' => $option->id, 'name' => $option->name, 'price' => 100000, 'discount' => 0, 'quantity' => 2, 'unit_cost' => $cost]]]);
        }
        $cart = $orders->addCart($tenant->id, $user->id, 'old-reservation', $broadcast->id, $option->id, 2, null, 'reserve-old');
        $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => '먼저 예약한 고객'], 'old-order');
        $cart = $orders->addCart($tenant->id, $user->id, 'new-sale', $broadcast->id, $option->id, 1, null, 'reserve-new');
        $created = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => '다른 실제 QR'], 'new-order');
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $created['order'])->first();
        $line = $repo->query(E::OrderLine, $tenant->id)->where('order_id', $order->id)->first();
        $unit = $repo->query(E::Unit, $tenant->id)->where('order_line_id', $line->id)->first();
        $this->assertSame(5000, (int) $unit->original_cost_krw);
        $date = now($tenant->timezone)->format('Y-m-d');
        $d = ['operation_key' => 'partial-one', 'order_id' => $order->id, 'fund_id' => $fund, 'bank_account_id' => $bank->id, 'amount' => 40000, 'occurred_on' => $date, 'reason' => '부분 입금'];
        $cash->receipt($tenant->id, $user->id, $d);
        $this->assertSame('review', $order->fresh()->payment_state);
        $d['operation_key'] = 'partial-two';
        $d['amount'] = 70000;
        $cash->receipt($tenant->id, $user->id, $d);
        $cash->receipt($tenant->id, $user->id, $d);
        $this->assertSame(110000, (int) $order->fresh()->received);
        $this->assertSame('paid', $order->fresh()->payment_state);
        $actualReceipt=$repo->query(E::FundEntry,$tenant->id)->where('kind','deposit')->first();$originalBank=$bank->bank;$originalNumber=$bank->account_number;$this->assertSame($originalNumber,$actualReceipt->bank_snapshot['number']);$this->assertStringNotContainsString($originalNumber,$actualReceipt->getRawOriginal('bank_snapshot'));
        $bank->update(['bank'=>'Changed current bank','holder'=>'Changed current holder','account_number'=>'9999999999999']);$bankReport=app(LiveFinanceReportService::class)->report($tenant->id,$user->id,$date,$date);$this->assertSame($originalBank,array_values($bankReport['bank_names'])[0]['bank']);$this->assertSame(substr($originalNumber,-4),array_values($bankReport['bank_names'])[0]['last4']);$this->assertSame($originalNumber,$order->fresh()->bank_snapshot['number']);
        $balances = app(LiveFinanceReportService::class)->currentBalances($tenant->id);
        $this->assertSame(10000, $balances['customers']['customer_credit_krw']);
        $orders->ship($tenant->id, $user->id, $order->id, [$unit->uuid], 'ship-newer');
        $sale = $repo->query(E::FifoAllocation, $tenant->id)->where('kind', 'sale')->first();
        $this->assertSame($unit->uuid, $sale->unit_uuid);
        $this->assertSame(1000, (int) $sale->amount_krw);
        $this->assertSame(100000, (int) $sale->revenue_krw);
        $assets = app(LiveFinanceAssetService::class)->totals($tenant->id);
        $this->assertSame(7000, $assets['actual']['known_krw']);
        $this->assertSame(11000, $assets['fifo']['known_krw']);
        $inventory = app(LiveInventoryService::class);
        $inventory->serviceUnit($tenant->id, $user->id, $unit->uuid, false, 'Customer repair', 'Customer-owned repair intake', 'finance-as-in');
        $this->assertSame(3, app(LiveFinanceAssetService::class)->totals($tenant->id)['actual']['quantity']);
        $this->assertSame(1, $repo->query(E::FifoAllocation, $tenant->id)->count());
        $inventory->serviceUnit($tenant->id, $user->id, $unit->uuid, true, 'Customer repair', 'Return customer repair', 'finance-as-out');
        $this->assertSame(1, $repo->query(E::FifoAllocation, $tenant->id)->count());
        app(LiveReturnService::class)->receive($tenant->id, $user->id, $line->id, [$unit->uuid], '고객 반품', 'return-one');
        $this->assertSame(0, (int) $repo->query(E::FifoAllocation, $tenant->id)->sum('amount_krw'));
        $this->assertSame(0, (int) $repo->query(E::FifoAllocation, $tenant->id)->sum('revenue_krw'));
        $this->assertSame(4, (int) $repo->query(E::FifoLayer, $tenant->id)->sum('remaining_quantity'));
        $this->assertSame(12000, (int) $repo->query(E::FifoLayer, $tenant->id)->sum('remaining_krw'));
        $refund = ['operation_key' => 'actual-refund', 'order_id' => $order->id, 'fund_id' => $fund, 'bank_account_id' => $bank->id, 'amount' => 100000, 'occurred_on' => $date, 'reason' => 'Returned product funds'];
        $cash->refund($tenant->id, $user->id, $refund);
        $cash->refund($tenant->id, $user->id, $refund);
        $this->assertSame(10000, app(LiveFinanceReportService::class)->currentBalances($tenant->id)['customers']['customer_credit_krw']);
    }

    public function test_finance_cny_receipt_partial_payment_and_loss_restore_reconcile_exactly(): void
    {
        extract($this->fixture());
        $this->activate($tenant->id, $user->id);
        $repo = app(LiveRepositoryInterface::class);
        $date = now($tenant->timezone)->format('Y-m-d');
        $receipt = app(LiveReceiptService::class)->receive($tenant->id, $user->id, ['operation_key' => 'cny-three', 'mode' => 'existing', 'product_id' => $product->id, 'location' => 'CNY shelf', 'currency' => 'CNY', 'exchange_rate' => '3333.333333', 'rate_date' => $date, 'rate_source' => 'synthetic manual', 'items' => [['option_id' => $option->id, 'name' => $option->name, 'price' => 100000, 'discount' => 0, 'quantity' => 3, 'unit_cost' => '0.01']]]);
        $units = $repo->query(E::Unit, $tenant->id)->where('option_id', $option->id)->orderBy('id')->get();
        $this->assertSame([33, 34, 33], $units->pluck('original_cost_krw')->all());
        $fund = $this->fund($tenant->id, $user->id, 'CNY', '1', '3333.333333');
        for ($n = 0; $n < 3; $n++) {
            app(LiveExpenseService::class)->pay($tenant->id, $user->id, ['operation_key' => 'receipt-part-'.$n, 'receipt_id' => $receipt['receipt_id'], 'fund_id' => $fund, 'paid_on' => $date, 'amount_original' => '0.01']);
        }$this->assertSame(1, $repo->query(E::Expense, $tenant->id)->where('receipt_id', $receipt['receipt_id'])->count());
        $this->assertSame(100, (int) $repo->query(E::ExpensePayment, $tenant->id)->sum('amount_krw'));
        $inventory = app(LiveInventoryService::class);
        $inventory->transition($tenant->id, $user->id, $units[1]->uuid, 'lost', 'CNY shelf', 'identified loss', 'cny-loss');
        $this->assertSame(33, (int) $repo->query(E::FifoAllocation, $tenant->id)->where('kind', 'loss')->sum('amount_krw'));
        $inventory->transition($tenant->id, $user->id, $units[1]->uuid, 'available', 'CNY shelf', 'identified recovery', 'cny-restore');
        $this->assertSame(0, (int) $repo->query(E::FifoAllocation, $tenant->id)->sum('amount_krw'));
        $this->assertSame(100, (int) $repo->query(E::FifoLayer, $tenant->id)->sum('remaining_krw'));
    }

    public function test_finance_reports_preserve_snapshots_and_recurring_month_end(): void
    {
        $tenant = $this->seedGolfifTenant();
        $this->activate($tenant->id, 0);
        $repo = app(LiveRepositoryInterface::class);
        $expenses = app(LiveExpenseService::class);
        $reports = app(LiveFinanceReportService::class);
        $date = now($tenant->timezone)->format('Y-m-d');
        $category = $repo->query(E::ExpenseCategory, $tenant->id)->where('code', 'salary')->first();
        $d = ['operation_key' => 'report-expense', 'category_id' => $category->id, 'occurred_on' => $date, 'currency' => 'KRW', 'amount_original' => '5000', 'exchange_rate' => '1', 'rate_date' => $date, 'rate_source' => 'manual', 'payment_status' => 'unpaid'];
        $expense = $expenses->expense($tenant->id, 0, $d);
        $result = $reports->report($tenant->id, 0, $date, $date);
        $this->assertSame(5000, $result['totals']['fixed']);
        $saveInput = ['operation_key' => 'same-report-key', 'from' => $date, 'to' => $date, 'period' => 'day'];
        $uuid = $reports->saveOnce($tenant->id, 0, $saveInput);
        $this->assertSame($uuid, $reports->saveOnce($tenant->id, 0, $saveInput));
        $saved = $reports->saved($tenant->id, 0, $uuid);
        $digest = $saved['report']->digest;
        $data = $saved['rows']->pluck('data')->all();
        $d['operation_key'] = 'later-expense';
        $expenses->expense($tenant->id, 0, $d);
        $again = $reports->saved($tenant->id, 0, $uuid);
        $this->assertSame($digest, $again['report']->digest);
        $this->assertSame($data, $again['rows']->pluck('data')->all());
        $this->assertSame(5000, $again['report']->result['totals']['fixed']);
        try {
            $reports->close($tenant->id, 0, ['operation_key' => 'stale-close', 'report_uuid' => $uuid, 'reason' => 'stale', 'acknowledge_incomplete' => true]);
            $this->fail('stale report closed');
        } catch (HttpException $e) {
            $this->assertSame(409,$e->getStatusCode());
        }
        $new = $reports->save($tenant->id,0,$date,$date,'month');
        $this->assertNotSame($uuid,$new);
        $closed = $reports->close($tenant->id,0,['operation_key' => 'fresh-close', 'report_uuid' => $new, 'reason' => 'management only', 'acknowledge_incomplete' => true]);
        $d['operation_key'] = 'closed-expense';
        try {
            $expenses->expense($tenant->id,0,$d);
            $this->fail('closed period edited');
        } catch (HttpException $e) {
            $this->assertSame(409,$e->getStatusCode());
        }
        $reports->reopen($tenant->id,0,['operation_key' => 'reopen', 'id' => $closed['closing_id'], 'reason' => 'documented correction']);
        $expenses->expense($tenant->id,0,$d);
        $rule = $expenses->recurring($tenant->id,0,['operation_key' => 'month-end', 'category_id' => $category->id, 'currency' => 'CNY', 'amount_original' => '10.03', 'day' => 31, 'starts_on' => '2026-01-31', 'ends_on' => null, 'active' => true, 'memo' => 'month-end']);
        foreach (['2026-01-31', '2026-02-28', '2026-03-31'] as $through) {
            $this->assertTrue($expenses->generateRecurring($tenant->id,$rule['rule_id'],$through)['generated']);
        }$this->assertFalse($expenses->generateRecurring($tenant->id,$rule['rule_id'],'2026-03-31')['generated']);
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'],$repo->query(E::Expense,$tenant->id)->where('source_kind','recurring')->orderBy('id')->pluck('occurred_on')->all());
        $this->assertSame(3,$repo->query(E::Expense,$tenant->id)->where('status','pending')->whereNull('amount_krw')->count());
        for($run=0;$run<3;$run++)$this->artisan('live:maintain',['--batch'=>1,'--seconds'=>2])->assertExitCode(0);
        $this->assertGreaterThanOrEqual(4,$repo->query(E::Expense,$tenant->id)->where('status','pending')->whereNull('amount_krw')->count());
        $this->assertSame($repo->query(E::Expense,$tenant->id)->where('source_kind','recurring')->count(),$repo->query(E::Expense,$tenant->id)->where('source_kind','recurring')->distinct()->count('source_key'));
    }
}
