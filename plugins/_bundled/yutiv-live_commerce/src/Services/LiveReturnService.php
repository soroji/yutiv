<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;

class LiveReturnService extends LiveWorkflow
{
    public function receive(int $tenant, int $actor, int $lineId, array $qrs, string $reason, string $key): array
    {
        sort($qrs);

        return $this->once($tenant, 'return:'.$key, compact('lineId', 'qrs', 'reason'), function () use ($tenant, $actor, $lineId, $qrs, $reason, $key) {
            $line = $this->row(E::OrderLine, $tenant, $lineId, true);
            abort_unless(count($qrs) === count(array_unique($qrs)) && count($qrs) <= $line->shipped - $line->returned, 409);
            foreach ($qrs as $qr) {
                $unit = $this->records->query(E::Unit, $tenant)->where('uuid', $qr)->lockForUpdate()->firstOrFail();
                abort_unless($unit->state === 'shipped' && $unit->order_line_id === $lineId, 409, __('yutiv-live_commerce::live.errors.e_b1118269b3'));
                $unit->state = 'returned';
                $unit->save();
                app(LiveInventoryService::class)->movement($unit, $actor, $key, 'return', ['state' => 'shipped', 'location' => $unit->location], $reason);
            }
            $line->increment('returned', count($qrs));
            // 일부 물품 입고만으로 고객이 요청한 분쟁 전체를 해결했다고 간주하지 않습니다.
            $this->records->create(E::Event, $tenant, ['order_id' => $line->order_id, 'operation_key' => $key, 'type' => 'return', 'amount' => count($qrs) * $line->unit_price,
                'data' => ['line_id' => $lineId, 'quantity' => count($qrs), 'reason' => $reason], 'actor_id' => $actor]);
            app(LiveIncentiveService::class)->sync($tenant, $lineId);
            $this->audit($tenant, $actor, 'return.received', ['line_id' => $lineId, 'quantity' => count($qrs)]);

            return ['returned' => $line->returned];
        });
    }

    public function refundRecord(int $tenant, int $actor, int $orderId, int $amount, string $reason, string $key): array
    {
        return $this->once($tenant, 'refund:'.$key, compact('orderId', 'amount', 'reason'), function () use ($tenant, $actor, $orderId, $amount, $reason, $key) {
            $order = $this->row(E::Order, $tenant, $orderId, true);
            $eligible = (int) $this->records->query(E::Event, $tenant)->where('order_id', $orderId)->whereIn('type', ['cancel', 'return'])->sum('amount');
            $recorded = (int) $this->records->query(E::Event, $tenant)->where('order_id', $orderId)->where('type', 'refund')->sum('amount');
            abort_unless($amount > 0 && $amount <= min($order->received, $eligible) - $recorded, 409, __('yutiv-live_commerce::live.errors.e_c092285f48'));
            $this->records->create(E::Event, $tenant, ['order_id' => $orderId, 'operation_key' => $key, 'type' => 'refund', 'amount' => $amount,
                'data' => ['reason' => $reason, 'bank_transfer' => 'not_executed'], 'actor_id' => $actor]);
            $this->audit($tenant, $actor, 'refund.recorded', ['order_id' => $orderId, 'amount' => $amount, 'bank_transfer' => 'not_executed']);

            return ['recorded' => $amount, 'bank_transfer' => false];
        });
    }

    public function resolve(int $tenant, int $actor, int $lineId, string $reason, string $key): array
    {
        return $this->once($tenant, 'return-review:'.$key, compact('lineId', 'reason'), function () use ($tenant, $actor, $lineId, $reason, $key) {
            $line = $this->row(E::OrderLine, $tenant, $lineId, true);
            abort_unless($line->disputed, 409, __('yutiv-live_commerce::live.errors.e_84a75fe8ed'));
            $line->disputed = false;
            $line->save();
            $this->records->create(E::Event, $tenant, ['order_id' => $line->order_id, 'operation_key' => $key, 'type' => 'return_review', 'amount' => 0, 'data' => ['line_id' => $lineId, 'reason' => $reason], 'actor_id' => $actor]);
            app(LiveIncentiveService::class)->sync($tenant, $lineId);
            $this->audit($tenant, $actor, 'return.review_resolved', ['line_id' => $lineId, 'reason' => $reason]);

            return ['saved' => true];
        });
    }
}
