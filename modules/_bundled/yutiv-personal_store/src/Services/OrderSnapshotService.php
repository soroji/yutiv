<?php

namespace Modules\Yutiv\PersonalStore\Services;

use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Yutiv\PersonalStore\Exceptions\StorePolicyException;
use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;

class OrderSnapshotService
{
    public function __construct(private StoreRepositoryInterface $stores, private RewardCalculator $calculator) {}

    public function create(Order $order, OrderCalculationResult $result, array $options): void
    {
        $revision = $this->stores->lockRevision();
        $optionsByLine = [];
        foreach ($options as $option) {
            $optionsByLine[$option->line_uuid] = $option;
        }
        foreach ($result->items as $index => $item) {
            if ($item->attributionKey === null) {
                continue;
            }
            $storeId = $this->stores->attribution($item->attributionKey);
            if ($storeId === null || ! $this->stores->allowed($storeId, $item->productId)) {
                throw new StorePolicyException(__('yutiv-personal_store::messages.unavailable'));
            }
            // 환율로 리워드를 만들지 않습니다. 계산 저장 단위와 주문 통화가 모두 KRW여야 합니다.
            if ($order->currency !== 'KRW' || ($order->currency_snapshot['base_currency'] ?? null) !== 'KRW') {
                continue;
            }
            $path = $this->stores->path($storeId);
            $option = $optionsByLine[$item->lineUuid] ?? throw new \LogicException(__('yutiv-personal_store::messages.invalid_path'));
            $base = max(0, $item->subtotal - $item->getTotalDiscount() - (int) ($item->metadata['grade_discount_amount'] ?? 0));
            $rate = $this->stores->productRate($item->productId);
            $this->stores->snapshot([
                'line_uuid' => $option->line_uuid,
                'order_id' => $order->id,
                'store_id' => $storeId,
                'product_id' => $item->productId,
                'eligible_base' => $base,
                'quantity' => $item->quantity,
                'created_at' => now(),
                'payload' => [
                    'line_uuid' => $option->line_uuid,
                    'order_option_id_at_creation' => $option->id,
                    'product_id' => $item->productId,
                    'product_option_id' => $item->productOptionId,
                    'product_name' => $item->productName,
                    'quantity' => $item->quantity,
                    'currency' => 'KRW',
                    'unit_price' => $item->unitPrice,
                    'additional_options_total' => $item->additionalOptionsTotal,
                    'subtotal' => $item->subtotal,
                    'discounts' => ['product_coupon' => $item->productCouponDiscountAmount, 'order_coupon' => $item->orderCouponDiscountShare, 'code' => $item->codeDiscountAmount, 'grade' => (int) ($item->metadata['grade_discount_amount'] ?? 0)],
                    'points_used_share' => $item->pointsUsedShare,
                    'eligible_base' => $base,
                    'rate_bps' => $rate,
                    'path' => $path,
                    'revision' => $revision,
                    'reward' => $this->calculator->allocate($base, $rate, $path),
                ],
            ]);
        }
    }
}
