<?php

namespace Modules\Yutiv\PersonalStore\DTO;

/** 운영자 주문 응답의 폐쇄된 개인정보 없는 필드 계약입니다. */
final readonly class OperatorOrder
{
    public function __construct(public string $reference, public string $orderedAt, public ?string $product, public int $quantity, public int $paymentAmount, public string $currency, public int $reward, public string $pointStatus, public string $orderStatus) {}

    public function toArray(): array
    {
        return ['reference' => $this->reference, 'ordered_at' => $this->orderedAt, 'product' => $this->product, 'quantity' => $this->quantity, 'payment_amount' => $this->paymentAmount, 'currency' => $this->currency, 'reward' => $this->reward, 'point_status' => $this->pointStatus, 'order_status' => $this->orderStatus];
    }
}
