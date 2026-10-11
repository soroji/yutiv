<?php

namespace Modules\Yutiv\PersonalStore\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Yutiv\PersonalStore\Models\Store;
use Modules\Yutiv\PersonalStore\Repositories\StoreRepository;
use Modules\Yutiv\PersonalStore\Services\RewardCalculator;
use Modules\Yutiv\PersonalStore\Tests\ModuleTestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RewardContractTest extends ModuleTestCase
{
    public function test_approved_2000_point_example(): void
    {
        $result = (new RewardCalculator)->allocate(10000, 2000, [['store_id' => 1, 'pass_bps' => 5000], ['store_id' => 2, 'pass_bps' => 2000], ['store_id' => 3, 'pass_bps' => 5000], ['store_id' => 4, 'pass_bps' => 0]]);
        $this->assertSame([1000, 800, 100, 100], array_column($result['allocations'], 'amount'));
        $this->assertSame(2000, array_sum(array_column($result['allocations'], 'amount')));
    }

    public function test_deep_chain_and_boundaries_preserve_pool_without_float(): void
    {
        $calculator = new RewardCalculator;
        foreach ([1, 4, 1000] as $depth) {
            foreach ([0, 1, 10001, PHP_INT_MAX] as $base) {
                foreach ([0, 1, 5000, 10000] as $rate) {
                    $path = [];
                    for ($i = 1; $i <= $depth; $i++) {
                        $path[] = ['store_id' => $i, 'pass_bps' => $i % 2 ? 10000 : 9999];
                    }
                    $result = $calculator->allocate($base, $rate, $path);
                    $this->assertSame($result['pool'], array_sum(array_column($result['allocations'], 'amount')));
                    $this->assertGreaterThanOrEqual(0, min(array_column($result['allocations'], 'amount')));
                }
            }
        }
    }

    public function test_cumulative_recovery_finishes_at_exact_original_amount(): void
    {
        $calculator = new RewardCalculator;
        $allocation = [['store_id' => 1, 'amount' => 7], ['store_id' => 2, 'amount' => 11]];
        $last = [1 => 0, 2 => 0];
        foreach ([1, 2, 3, 10] as $base) {
            $target = $calculator->recoveryTargets($allocation, $base, 10);
            foreach ($target as $id => $amount) {
                $this->assertGreaterThanOrEqual($last[$id], $amount);
            }
            $last = $target;
        }
        $this->assertSame([1 => 7, 2 => 11], $last);
    }

    public function test_operator_application_does_not_grant_admin_and_repeated_apply_is_rejected(): void
    {
        $user = User::factory()->create();
        $repo = app(StoreRepository::class);
        $repo->manage($user->id, 'apply', ['slug' => 'isolated-store']);
        $this->assertFalse($user->fresh()->isAdmin());
        $this->assertSame('pending', Store::where('user_id', $user->id)->first()->status->value);
        $this->expectException(HttpException::class);
        $repo->manage($user->id, 'apply', ['slug' => 'another-store']);
    }

    public function test_owner_cannot_approve_or_manage_other_lineage(): void
    {
        $user = User::factory()->create();
        $this->expectException(HttpException::class);
        app(StoreRepository::class)->manage($user->id, 'approve', ['application_id' => 1]);
    }

    public function test_snapshot_outbox_and_ledger_are_atomic_idempotent_and_reverse_order_safe(): void
    {
        $user = User::factory()->create();
        $repo = app(StoreRepository::class);
        $repo->manage($user->id, 'apply', ['slug' => 'atomic-store']);
        $store = Store::where('user_id', $user->id)->first();
        $order = OrderFactory::new()->create();
        DB::transaction(fn () => $repo->snapshot(['line_uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'store_id' => $store->id, 'created_at' => now(), 'payload' => ['quantity' => 10, 'reward' => ['allocations' => [['store_id' => $store->id, 'amount' => 2000]]]]]));
        $snapshotId = DB::table('personal_store_line_snapshots')->value('id');
        $repo->outbox('cancel-first', 'state', ['snapshot_id' => $snapshotId, 'confirmed_quantity' => 10, 'cancelled_confirmed_quantity' => 5, 'review_required' => false]);
        $repo->outbox('confirm-late', 'state', ['snapshot_id' => $snapshotId, 'confirmed_quantity' => 10, 'cancelled_confirmed_quantity' => 0, 'review_required' => false]);
        foreach (DB::table('personal_store_outbox')->orderBy('id')->pluck('id') as $id) {
            $repo->processOutbox($id);
            $this->assertFalse($repo->processOutbox($id));
        }
        $this->assertSame(1000, (int) DB::table('personal_store_wallets')->where('store_id', $store->id)->value('available'));
        $this->assertSame(1000, (int) DB::table('personal_store_ledger')->sum('available_delta'));
    }
}
