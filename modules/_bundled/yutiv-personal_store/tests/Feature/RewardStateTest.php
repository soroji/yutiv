<?php

namespace Modules\Yutiv\PersonalStore\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Yutiv\PersonalStore\Models\Store;
use Modules\Yutiv\PersonalStore\Repositories\StoreRepository;
use Modules\Yutiv\PersonalStore\Tests\ModuleTestCase;

class RewardStateTest extends ModuleTestCase
{
    public function test_actual_admin_deposit_entry_captures_payment_and_replays_without_duplicate_earn(): void
    {
        [$repo, $store, $order, $uuid] = $this->fixture();
        $order->update(['currency' => 'KRW', 'currency_snapshot' => ['base_currency' => 'KRW', 'order_currency' => 'KRW', 'exchange_rates' => ['KRW' => ['rate' => 1, 'decimal_places' => 0, 'rounding_unit' => '1']]], 'order_status' => 'pending_payment', 'total_due_amount' => 10000, 'total_amount' => 10000, 'subtotal_amount' => 10000, 'total_paid_amount' => 0]);
        $order->update(['total_discount_amount' => 0, 'total_product_coupon_discount_amount' => 0, 'total_order_coupon_discount_amount' => 0, 'total_code_discount_amount' => 0, 'base_shipping_amount' => 0, 'extra_shipping_amount' => 0, 'shipping_discount_amount' => 0, 'total_shipping_amount' => 0, 'total_points_used_amount' => 0]);
        $order->update(['mc_total_due_amount' => ['KRW' => 10000]]);
        OrderOptionFactory::new()->create(['order_id' => $order->id, 'line_uuid' => $uuid, 'quantity' => 10, 'option_status' => 'confirmed', 'confirmed_at' => now()]);
        OrderPaymentFactory::new()->create(['order_id' => $order->id, 'payment_method' => 'dbank', 'payment_status' => 'ready', 'paid_at' => null]);
        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.orders.update']), 'sanctum');
        $url = '/api/modules/sirsoft-ecommerce/admin/orders/'.$order->order_number.'/confirm-deposit';
        $this->patchJson($url, ['amount' => 10000, 'mark_order_complete' => false])->assertOk();
        $this->drain($repo);
        $this->assertSame(1000, (int) DB::table('personal_store_wallets')->where('store_id', $store->id)->value('available'));
        $this->patchJson($url, ['amount' => 10000, 'mark_order_complete' => false])->assertUnprocessable();
        $this->drain($repo);
        $this->assertSame(1, DB::table('personal_store_ledger')->where('kind', 'earn')->count());
    }

    public function test_actual_http_confirmation_creates_state_outbox_and_pays_once(): void
    {
        [$repo, $store, $order, $uuid] = $this->fixture();
        $buyer = $this->createUser();
        $order->update(['user_id' => $buyer->id, 'order_status' => 'delivered']);
        $option = OrderOptionFactory::new()->create(['order_id' => $order->id, 'line_uuid' => $uuid, 'quantity' => 10, 'option_status' => 'delivered', 'confirmed_at' => null]);
        OrderPaymentFactory::new()->create(['order_id' => $order->id, 'paid_at' => now()]);
        $this->actingAs($buyer, 'sanctum');
        $url = '/api/modules/sirsoft-ecommerce/user/orders/'.$order->id.'/options/'.$option->id.'/confirm';
        $this->postJson($url)->assertSuccessful();
        $this->drain($repo);
        $this->assertSame(1000, (int) DB::table('personal_store_wallets')->where('store_id', $store->id)->value('available'));
        $this->postJson($url)->assertUnprocessable();
        $this->drain($repo);
        $this->assertSame(1, DB::table('personal_store_ledger')->where('kind', 'earn')->count());
    }

    public function test_refund_saved_blocks_old_confirmation_before_review_outbox_is_processed(): void
    {
        [$repo, $store, $order, $uuid, $id] = $this->fixture();
        OrderOptionFactory::new()->create(['order_id' => $order->id, 'line_uuid' => $uuid, 'quantity' => 10, 'confirmed_at' => now()]);
        OrderPaymentFactory::new()->create(['order_id' => $order->id, 'paid_at' => now()]);
        $repo->captureOrderState($order->id);
        $old = DB::table('personal_store_outbox')->max('id');
        DB::transaction(fn () => OrderRefund::create(['order_id' => $order->id, 'refund_number' => 'review-fixture', 'refund_status' => 'completed', 'refund_method' => 'bank', 'refund_amount' => 1]));
        $this->assertTrue((bool) DB::table('personal_store_line_states')->where('snapshot_id', $id)->value('review_required'));
        $repo->processOutbox($old);
        $this->drain($repo);
        $this->assertSame(0, DB::table('personal_store_ledger')->count());
        $this->assertSame(0, (int) DB::table('personal_store_wallets')->where('store_id', $store->id)->value('available'));
        $this->assertSame(1, DB::table('personal_store_line_states')->where('review_required', true)->count());
    }

    private function fixture(): array
    {
        $repo = app(StoreRepository::class);
        $owner = User::factory()->create();
        $repo->manage($owner->id, 'apply', ['slug' => 'state-'.Str::lower(Str::random(12))]);
        $store = Store::where('user_id', $owner->id)->firstOrFail();
        $order = OrderFactory::new()->create();
        $uuid = (string) Str::uuid();
        DB::transaction(fn () => $repo->snapshot(['line_uuid' => $uuid, 'order_id' => $order->id, 'store_id' => $store->id, 'created_at' => now(), 'payload' => ['quantity' => 10, 'reward' => ['allocations' => [['store_id' => $store->id, 'amount' => 1000]]]]]));

        return [$repo, $store, $order, $uuid, DB::table('personal_store_line_snapshots')->max('id')];
    }

    private function drain(StoreRepository $repo): void
    {
        foreach (DB::table('personal_store_outbox')->orderBy('id')->pluck('id') as $id) {
            $repo->processOutbox($id);
        }
    }

    public function test_confirm_before_payment_does_not_pay_and_reconciliation_recovers_lost_event(): void
    {
        [$repo, $store, $order, $uuid] = $this->fixture();
        OrderOptionFactory::new()->create(['order_id' => $order->id, 'line_uuid' => $uuid, 'quantity' => 10, 'option_status' => 'confirmed', 'confirmed_at' => now()]);
        $payment = OrderPaymentFactory::new()->create(['order_id' => $order->id, 'paid_at' => null]);
        $repo->captureOrderState($order->id);
        $this->drain($repo);
        $this->assertSame(0, (int) DB::table('personal_store_wallets')->where('store_id', $store->id)->value('available'));
        $payment->update(['paid_at' => now()]);
        // 후처리가 유실된 것처럼 훅은 호출하지 않고 현재 경제 상태로 outbox를 복구합니다.
        $repo->captureOrderState($order->id);
        $repo->captureOrderState($order->id);
        $this->drain($repo);
        $this->assertSame(1000, (int) DB::table('personal_store_wallets')->where('store_id', $store->id)->value('available'));
        $this->assertSame(1, DB::table('personal_store_ledger')->where('kind', 'earn')->count());
    }

    public function test_newer_review_state_blocks_older_confirmation_delivery(): void
    {
        [$repo, $store, $order, $uuid, $id] = $this->fixture();
        $repo->outbox('old-confirm', 'state', ['snapshot_id' => $id, 'confirmed_quantity' => 10, 'cancelled_confirmed_quantity' => 0, 'review_required' => false]);
        $old = DB::table('personal_store_outbox')->max('id');
        $repo->outbox('new-review', 'state', ['snapshot_id' => $id, 'confirmed_quantity' => 10, 'cancelled_confirmed_quantity' => 0, 'review_required' => true]);
        $repo->processOutbox(DB::table('personal_store_outbox')->max('id'));
        $repo->processOutbox($old);
        $this->assertSame(0, DB::table('personal_store_ledger')->count());
        $this->assertTrue((bool) DB::table('personal_store_line_states')->where('snapshot_id', $id)->value('review_required'));
    }

    public function test_review_clear_is_a_new_transition_even_when_payload_matches_an_older_state(): void
    {
        [$repo, $store, $order, $uuid, $id] = $this->fixture();
        $option = OrderOptionFactory::new()->create(['order_id' => $order->id, 'line_uuid' => $uuid, 'quantity' => 10, 'confirmed_at' => now()]);
        OrderPaymentFactory::new()->create(['order_id' => $order->id, 'paid_at' => now()]);
        $repo->captureOrderState($order->id);
        $this->drain($repo);
        $option->update(['quantity' => 11]);
        $repo->captureOrderState($order->id);
        $this->drain($repo);
        $this->assertTrue((bool) DB::table('personal_store_line_states')->where('snapshot_id', $id)->value('review_required'));
        $option->update(['quantity' => 10]);
        $repo->captureOrderState($order->id);
        $repo->captureOrderState($order->id);
        $this->drain($repo);
        $state = DB::table('personal_store_line_states')->where('snapshot_id', $id)->first();
        $this->assertFalse((bool) $state->review_required);
        $this->assertSame(3, (int) $state->capture_version);
        $this->assertSame(1, DB::table('personal_store_ledger')->where('kind', 'earn')->count());
    }

    public function test_shortfall_offsets_future_reward_and_full_recovery_is_exact(): void
    {
        [$repo, $store, $order, $uuid, $id] = $this->fixture();
        // 이전 회수 부족 사실과 그 원장 집계를 함께 시드합니다. 서비스 밖 잔액 변경 API는 없습니다.
        DB::table('personal_store_ledger')->insert(['idempotency_key' => 'prior-shortfall', 'store_id' => $store->id, 'snapshot_id' => $id, 'kind' => 'shortfall', 'amount' => 1000, 'available_delta' => 0, 'debt_delta' => 1000, 'created_at' => now()]);
        DB::table('personal_store_wallets')->where('store_id', $store->id)->update(['debt' => 1000]);
        $repo->outbox('earn-offset', 'state', ['snapshot_id' => $id, 'confirmed_quantity' => 10, 'cancelled_confirmed_quantity' => 0, 'review_required' => false]);
        $this->drain($repo);
        $wallet = DB::table('personal_store_wallets')->where('store_id', $store->id)->first();
        $this->assertSame([0, 0], [(int) $wallet->available, (int) $wallet->debt]);
        foreach ([1, 3, 10] as $cancelled) {
            $repo->outbox('cancel-'.$cancelled, 'state', ['snapshot_id' => $id, 'confirmed_quantity' => 10, 'cancelled_confirmed_quantity' => $cancelled, 'review_required' => false]);
            $this->drain($repo);
        }
        $this->assertSame(1000, (int) DB::table('personal_store_ledger')->where('kind', 'recover')->sum('amount'));
        $this->assertSame(1000, (int) DB::table('personal_store_wallets')->where('store_id', $store->id)->value('debt'));
        $this->assertSame(1000, (int) DB::table('personal_store_ledger')->sum('debt_delta'));
    }
}
