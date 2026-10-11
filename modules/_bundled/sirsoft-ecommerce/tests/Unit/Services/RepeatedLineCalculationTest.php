<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;

class RepeatedLineCalculationTest extends OrderCalculationServiceTest
{
    public function test_same_option_with_distinct_original_lines_survives_full_pipeline(): void
    {
        [$product, $option] = $this->createProductWithOption(price: 10000);
        $result = $this->service->calculate(new CalculationInput(items: [
            new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2, lineUuid: 'first', attributionKey: 'store-a'),
            new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1, lineUuid: 'second', attributionKey: 'store-b'),
        ]));
        $this->assertCount(2, $result->items);
        $this->assertSame(['first', 'second'], array_column($result->items, 'lineUuid'));
        $this->assertSame(['store-a', 'store-b'], array_column($result->items, 'attributionKey'));
        $this->assertSame([20000, 10000], array_column($result->items, 'subtotal'));
        $this->assertSame([$option->id, $option->id], array_column($result->items, 'productOptionId'));
    }
}
