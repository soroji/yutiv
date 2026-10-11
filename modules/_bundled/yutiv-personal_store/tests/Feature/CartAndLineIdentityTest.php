<?php

namespace Modules\Yutiv\PersonalStore\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Services\CartService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Services\TempOrderService;
use Modules\Yutiv\PersonalStore\Models\Store;
use Modules\Yutiv\PersonalStore\Repositories\StoreRepository;
use Modules\Yutiv\PersonalStore\Services\AttributionProof;
use Modules\Yutiv\PersonalStore\Tests\ModuleTestCase;

class CartAndLineIdentityTest extends ModuleTestCase
{
    public function test_cart_origin_survives_visits_and_login_merge_without_cross_store_merge(): void
    {
        Queue::fake();
        $product = ProductFactory::new()->create(['display_status' => 'visible', 'sales_status' => 'on_sale']);
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $repo = app(StoreRepository::class);
        $keys = [];
        foreach (['cart-a', 'cart-b'] as $slug) {
            $owner = User::factory()->create();
            $repo->manage($owner->id, 'apply', ['slug' => $slug]);
            $store = Store::where('user_id', $owner->id)->firstOrFail();
            $store->update(['status' => 'active']);
            $repo->manage($owner->id, 'products', ['product_id' => $product->id, 'excluded' => false]);
            $keys[] = $repo->publicOrigin($slug);
        }
        $cart = app(CartService::class);
        $guestKey = (string) Str::uuid();
        foreach ($keys as $reference) {
            request()->headers->set('X-Yutiv-Store-Origin', app(AttributionProof::class)->issue($reference, time() + 60, 'sale'));
            $cart->bulkAddToCart(['product_id' => $product->id, 'items' => [['product_option_id' => $option->id, 'quantity' => 1]], 'cart_key' => $guestKey]);
        }
        $this->assertSame($keys, Cart::where('cart_key', $guestKey)->orderBy('id')->pluck('attribution_key')->all());
        $buyer = User::factory()->create();
        $cart->mergeGuestCartToUser($guestKey, $buyer->id);
        $this->assertSame($keys, Cart::where('user_id', $buyer->id)->orderBy('id')->pluck('attribution_key')->all());
        $this->assertSame(2, Cart::where('user_id', $buyer->id)->count());
        $this->assertSame(2, Cart::where('user_id', $buyer->id)->distinct()->count('line_uuid'));
    }

    public function test_partial_confirmation_split_and_merge_preserve_original_uuid(): void
    {
        Queue::fake();
        $product = ProductFactory::new()->create();
        $productOption = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::DELIVERED]);
        $uuid = (string) Str::uuid();
        $option = OrderOptionFactory::new()->create(['order_id' => $order->id, 'product_id' => $product->id, 'product_option_id' => $productOption->id, 'quantity' => 10, 'line_uuid' => $uuid, 'attribution_key' => 'original-reference', 'option_status' => OrderStatusEnum::DELIVERED]);
        $service = app(OrderOptionService::class);
        $service->changeStatusWithQuantity($option, OrderStatusEnum::CONFIRMED, 4);
        $this->assertSame(2, $order->options()->count());
        $this->assertSame([$uuid], $order->options()->distinct()->pluck('line_uuid')->all());
        $service->changeStatusWithQuantity($option->fresh(), OrderStatusEnum::CONFIRMED, 6);
        $this->assertSame(10, (int) $order->options()->sum('quantity'));
        $this->assertSame([$uuid], $order->options()->distinct()->pluck('line_uuid')->all());
        $this->assertSame(['original-reference'], $order->options()->distinct()->pluck('attribution_key')->all());
        $this->assertSame(0, $order->options()->whereNull('confirmed_at')->count());
    }

    public function test_direct_purchase_freezes_origin_and_new_uuid_without_creating_cart_rows(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $repo = app(StoreRepository::class);
        $repo->manage($owner->id, 'apply', ['slug' => 'direct-origin']);
        $store = Store::where('user_id', $owner->id)->firstOrFail();
        $store->update(['status' => 'active']);
        $product = ProductFactory::new()->create(['display_status' => 'visible', 'sales_status' => 'on_sale']);
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $repo->manage($owner->id, 'products', ['product_id' => $product->id, 'excluded' => false]);
        $reference = $repo->publicOrigin($store->slug);
        request()->headers->set('X-Yutiv-Store-Origin', app(AttributionProof::class)->issue($reference, time() + 60, 'sale'));
        $this->mock(OrderCalculationService::class)->shouldReceive('calculate')->andReturn(new OrderCalculationResult);
        $service = app(TempOrderService::class);
        $items = [['product_id' => $product->id, 'product_option_id' => $option->id, 'quantity' => 1]];
        $first = $service->createTempOrderFromDirectItems($items, null, 'direct-guest');
        $uuid = $first->items[0]['line_uuid'];
        $this->assertSame($reference, $first->items[0]['attribution_key']);
        $second = $service->createTempOrderFromDirectItems($items, null, 'direct-guest');
        $this->assertNotSame($uuid, $second->items[0]['line_uuid']);
        $this->assertSame($reference, $second->items[0]['attribution_key']);
        $this->assertSame(0, Cart::count());
    }
}
