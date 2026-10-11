<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\TempOrderFactory;
use Modules\Sirsoft\Ecommerce\DTO\AppliedShippingPolicy;
use Modules\Sirsoft\Ecommerce\DTO\ItemCalculation;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;

class RepeatedLineProcessingTest extends OrderProcessingServiceTest
{
    public function test_duplicate_option_shipping_mapping_and_transaction_hook_rollback(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $option = ProductOption::factory()->create(['product_id' => $product->id]);
        $temp = TempOrderFactory::new()->forUser($user)->withItems([['cart_id' => 1, 'product_id' => $product->id, 'product_option_id' => $option->id, 'quantity' => 2]])->withCalculationResult(['summary' => ['final_amount' => 110000], 'items' => [], 'promotions' => [], 'validation_errors' => []])->create();
        $items = [];
        foreach ([3000, 7000] as $shipping) {
            $items[] = new ItemCalculation(productId: $product->id, productOptionId: $option->id, quantity: 2, unitPrice: 25000, subtotal: 50000, finalAmount: 50000, lineUuid: (string) Str::uuid(), attributionKey: (string) Str::uuid(), additionalOptionsSnapshot: [['input' => 'synthetic-'.$shipping]], appliedShippingPolicy: new AppliedShippingPolicy(policyId: $shipping === 3000 ? 5 : 6, policyName: '고정 배송', chargePolicy: 'paid', shippingAmount: $shipping, totalShippingAmount: $shipping, policySnapshot: ['id' => $shipping === 3000 ? 5 : 6]));
        }
        $this->mockCalculationService($this->makeCalculationResult(110000, ['subtotal' => 100000, 'total_shipping' => 10000, 'items' => $items]));
        $fail = true;
        HookManager::addAction('sirsoft-ecommerce.order.created_in_transaction', function ($order, $tempOrder, $result, $created) use (&$fail) {
            $this->assertGreaterThan(0, DB::transactionLevel());
            $this->assertCount(2, $created);
            if ($fail) {
                throw new \RuntimeException('Injected hook failure.');
            }
        });
        $create = fn () => $this->service->createFromTempOrder($temp, ['name' => 'Fixture', 'phone' => '010-0000-0000', 'email' => 'fixture@example.invalid'], ['recipient_name' => 'Fixture', 'recipient_phone' => '010-0000-0000', 'zipcode' => '00000', 'address' => 'Fixture', 'address_detail' => 'Fixture'], 'card', 110000);
        $count = DB::table('ecommerce_orders')->count();
        try {
            $create();
            $this->fail('Synchronous failure must roll back the order.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected hook failure.', $e->getMessage());
            $this->assertSame($count, DB::table('ecommerce_orders')->count());
            $this->assertSame(0, DB::table('ecommerce_order_options')->count());
            $this->assertSame(0, DB::table('ecommerce_order_shippings')->count());
        }
        $fail = false;
        $order = $create();
        $this->assertCount(2, $order->options);
        $this->assertSame(array_column($items, 'lineUuid'), $order->options->pluck('line_uuid')->all());
        $rows = $order->shippings()->orderBy('order_option_id')->get();
        $this->assertSame($order->options->pluck('id')->all(), $rows->pluck('order_option_id')->all());
        $this->assertSame([3000, 7000], $rows->pluck('base_shipping_amount')->map(fn ($amount) => (int) $amount)->all());
        $order->shippings()->delete();
        $reversedResult = $this->makeCalculationResult(110000, ['items' => array_reverse($items)]);
        (new \ReflectionMethod($this->service, 'createOrderShippings'))->invoke($this->service, $order, $temp, $reversedResult, $order->currency_snapshot, $order->options->all());
        $rows = $order->shippings()->orderBy('order_option_id')->get();
        $this->assertSame([3000, 7000], $rows->pluck('base_shipping_amount')->map(fn ($amount) => (int) $amount)->all());
        $this->assertSame([5, 6], $rows->pluck('shipping_policy_id')->all());
        $this->assertSame(array_column($items, 'attributionKey'), $order->options->pluck('attribution_key')->all());
    }
}
