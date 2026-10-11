<?php

namespace Modules\Yutiv\PersonalStore\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\DTO\AppliedShippingPolicy;
use Modules\Sirsoft\Ecommerce\DTO\ItemCalculation;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Yutiv\PersonalStore\Models\LineSnapshot;
use Modules\Yutiv\PersonalStore\Models\Store;
use Modules\Yutiv\PersonalStore\Repositories\StoreRepository;
use Modules\Yutiv\PersonalStore\Services\OrderSnapshotService;
use Modules\Yutiv\PersonalStore\Tests\ModuleTestCase;

class OrderSnapshotTest extends ModuleTestCase
{
    public function test_shuffled_mixed_store_options_match_uuid_and_split_keeps_one_original_snapshot(): void
    {
        $repo = app(StoreRepository::class);
        $product = ProductFactory::new()->create(['display_status' => 'visible', 'sales_status' => 'on_sale']);
        $order = OrderFactory::new()->create(['currency' => 'KRW', 'currency_snapshot' => ['base_currency' => 'KRW']]);
        $options = $items = [];
        foreach ([1, 2] as $index) {
            $owner = User::factory()->create();
            $repo->manage($owner->id, 'apply', ['slug' => 'shuffled-'.$index]);
            $store = Store::where('user_id', $owner->id)->firstOrFail();
            $store->update(['status' => 'active']);
            $repo->manage($owner->id, 'products', ['product_id' => $product->id, 'excluded' => false]);
            $attributes = ['order_id' => $order->id, 'product_id' => $product->id, 'line_uuid' => (string) Str::uuid(), 'quantity' => 2, 'option_status' => 'delivered'];
            if ($options !== []) {
                $attributes['product_option_id'] = $options[0]->product_option_id;
            }
            $option = OrderOptionFactory::new()->create($attributes);
            $options[] = $option;
            $items[] = new ItemCalculation(productId: $product->id, productOptionId: $option->product_option_id, quantity: 2, unitPrice: $index * 100, subtotal: $index * 200, lineUuid: $option->line_uuid, attributionKey: $repo->publicOrigin($store->slug), additionalOptionsSnapshot: [['input' => 'synthetic-'.$index]], appliedShippingPolicy: new AppliedShippingPolicy(policyId: $index, policyName: 'Synthetic', chargePolicy: 'paid', shippingAmount: $index * 3000, totalShippingAmount: $index * 3000));
        }
        DB::transaction(fn () => app(OrderSnapshotService::class)->create($order, new OrderCalculationResult(items: array_reverse($items)), $options));
        foreach ($options as $option) {
            $this->assertSame($option->id, LineSnapshot::where('line_uuid', $option->line_uuid)->firstOrFail()->payload['order_option_id_at_creation']);
        }
        $result = app(OrderOptionService::class)->changeStatusWithQuantity($options[0], OrderStatusEnum::CONFIRMED, 1);
        $this->assertSame($options[0]->line_uuid, $result['split']->line_uuid);
        $this->assertSame(2, $order->options()->where('line_uuid', $options[0]->line_uuid)->count());
        $this->assertSame(1, LineSnapshot::where('line_uuid', $options[0]->line_uuid)->count());
        $this->assertSame(2, LineSnapshot::where('order_id', $order->id)->count());
    }

    public function test_snapshot_basis_excludes_all_discounts_but_not_buyer_mileage_and_non_krw_is_ineligible(): void
    {
        $repo = app(StoreRepository::class);
        $owner = User::factory()->create();
        $repo->manage($owner->id, 'apply', ['slug' => 'snapshot-source']);
        $store = Store::where('user_id', $owner->id)->firstOrFail();
        $store->update(['status' => 'active']);
        $product = ProductFactory::new()->create(['display_status' => 'visible', 'sales_status' => 'on_sale']);
        $repo->manage($owner->id, 'products', ['product_id' => $product->id, 'excluded' => false]);
        DB::table('personal_store_product_rates')->insert(['product_id' => $product->id, 'rate_bps' => 2000]);
        $reference = $repo->publicOrigin($store->slug);
        foreach ([['USD', 'KRW'], ['KRW', 'USD'], ['KRW', 'KRW']] as [$currency, $base]) {
            $order = OrderFactory::new()->create(['currency' => $currency, 'currency_snapshot' => ['base_currency' => $base]]);
            $option = OrderOptionFactory::new()->create(['order_id' => $order->id, 'product_id' => $product->id, 'line_uuid' => (string) Str::uuid()]);
            $item = new ItemCalculation(productId: $product->id, productOptionId: $option->product_option_id, quantity: 2, unitPrice: 4500, additionalOptionsTotal: 500, subtotal: 10000, productCouponDiscountAmount: 100, codeDiscountAmount: 200, orderCouponDiscountShare: 300, pointsUsedShare: 1000, metadata: ['grade_discount_amount' => 400], attributionKey: $reference, productName: 'Safe product', lineUuid: $option->line_uuid);
            DB::transaction(fn () => app(OrderSnapshotService::class)->create($order, new OrderCalculationResult(items: [$item]), [$option]));
            $this->assertSame($currency === 'KRW' && $base === 'KRW' ? 1 : 0, LineSnapshot::where('order_id', $order->id)->count());
        }
        $snapshot = LineSnapshot::firstOrFail();
        $this->assertSame(9000, $snapshot->payload['eligible_base']);
        $this->assertSame(1800, $snapshot->payload['reward']['pool']);
        $this->assertSame(1000, $snapshot->payload['points_used_share']);
        $this->assertSame(1, DB::table('personal_store_outbox')->count());
        $this->expectException(\LogicException::class);
        $snapshot->update(['eligible_base' => 1]);
    }
}
