<?php

namespace Modules\Yutiv\PersonalStore\Tests\Feature;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Tests\Unit\Services\OrderCancellationServiceTest;
use Modules\Yutiv\PersonalStore\Listeners\StoreOrderListener;
use Modules\Yutiv\PersonalStore\Models\Store;
use Modules\Yutiv\PersonalStore\Providers\PersonalStoreServiceProvider;
use Modules\Yutiv\PersonalStore\Repositories\StoreRepository;

class CancellationEntryPointTest extends OrderCancellationServiceTest
{
    protected array $requiredExtensions = ['modules/sirsoft-ecommerce', 'modules/yutiv-personal_store'];

    public function test_actual_cancel_api_and_refund_save_recover_the_same_original_only_once(): void
    {
        $this->app->register(PersonalStoreServiceProvider::class);
        $listener = app(StoreOrderListener::class);
        foreach (StoreOrderListener::getSubscribedHooks() as $name => $config) {
            if (($config['type'] ?? null) !== 'filter') {
                HookManager::addAction($name, [$listener, $config['method']]);
            }
        }
        $this->createShippingPolicy();
        [$product, $productOption] = $this->createProductWithOption(price: 20000);
        $order = $this->createOrderFromCalculation(new CalculationInput(items: [new CalculationItem(productId: $product->id, productOptionId: $productOption->id, quantity: 2)]));
        $uuid = (string) Str::uuid();
        $order->options->first()->update(['line_uuid' => $uuid, 'confirmed_at' => now()]);
        $repo = app(StoreRepository::class);
        $owner = User::factory()->create();
        $repo->manage($owner->id, 'apply', ['slug' => 'cancel-api']);
        $store = Store::where('user_id', $owner->id)->firstOrFail();
        DB::transaction(fn () => $repo->snapshot(['line_uuid' => $uuid, 'order_id' => $order->id, 'store_id' => $store->id, 'created_at' => now(), 'payload' => ['quantity' => 2, 'reward' => ['allocations' => [['store_id' => $store->id, 'amount' => 1000]]]]]));
        $repo->captureOrderState($order->id);
        foreach (DB::table('personal_store_outbox')->pluck('id') as $id) {
            $repo->processOutbox($id);
        }
        $this->assertSame(1000, (int) DB::table('personal_store_wallets')->where('store_id', $store->id)->value('available'));
        $reason = ClaimReason::where('type', 'refund')->where('is_active', true)->firstOrFail();
        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.orders.update']), 'sanctum');
        $this->postJson('/api/modules/sirsoft-ecommerce/admin/orders/'.$order->order_number.'/cancel', ['type' => 'full', 'reason' => $reason->code, 'cancel_pg' => false])->assertOk();
        $refund = $order->refunds()->firstOrFail();
        $refund->touch();
        $repo->captureOrderState($order->id);
        foreach (DB::table('personal_store_outbox')->orderBy('id')->pluck('id') as $id) {
            $repo->processOutbox($id);
        }
        $this->assertSame(1000, (int) DB::table('personal_store_ledger')->where('kind', 'recover')->sum('amount'));
        $this->assertSame(1, DB::table('personal_store_ledger')->where('kind', 'recover')->count());
        $this->assertSame(0, (int) DB::table('personal_store_wallets')->where('store_id', $store->id)->value('available'));
    }
}
