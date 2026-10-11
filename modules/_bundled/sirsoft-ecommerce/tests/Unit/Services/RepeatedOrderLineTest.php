<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use PHPUnit\Framework\TestCase;

/** DB와 애플리케이션 부팅 없이 실제 계산 메서드의 라인 충돌을 검증합니다. */
class RepeatedOrderLineTest extends TestCase
{
    public function test_repeated_option_preserves_each_line_subtotal(): void
    {
        $service = (new \ReflectionClass(OrderCalculationService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'calculateItemSubtotals');
        $items = [
            ['line_uuid' => 'first', 'product_option_id' => 77, 'unit_price' => 1000, 'additional_options_total' => 100, 'quantity' => 2],
            ['line_uuid' => 'second', 'product_option_id' => 77, 'unit_price' => 1000, 'additional_options_total' => 300, 'quantity' => 1],
        ];
        $result = $method->invoke($service, $items);
        $this->assertCount(2, $result);
        $this->assertSame(3500, array_sum(array_column($result, 'subtotal')));
    }
}
