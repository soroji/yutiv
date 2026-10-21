<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;

class LiveIncentiveService extends LiveWorkflow
{
    /** 이벤트 도착 순서 대신 주문 스냅샷과 현재 상태로 누적 목표를 재계산합니다. */
    public function sync(int $tenant, int $lineId): array
    {
        return $this->records->transaction(function () use ($tenant, $lineId) {
            LiveTenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $line = $this->row(E::OrderLine, $tenant, $lineId, true);
            $order = $this->row(E::Order, $tenant, $line->order_id, true);
            $eligible = $order->payment_state === 'paid' && ! $line->disputed;
            $confirmed = $eligible && $order->delivery_state === 'delivered' && $order->delivered_at
                && ($order->confirmed_at || $order->delivered_at->copy()->addDays($line->snapshot['return_days']) <= now());
            $results = [];
            $originals = $line->snapshot['incentives'];
            $correction = $this->records->query(E::Event, $tenant)->where('order_id', $order->id)->where('type', 'attribution_correction')->where('data->line_id', $lineId)->orderByDesc('id')->first();
            if ($correction) {
                $originals = $correction->data['after']['incentives'];
            }
            $known = $this->records->query(E::Incentive, $tenant)->where('order_line_id', $lineId)->distinct()->pluck('beneficiary');
            foreach ($known as $beneficiary) {
                $originals[$beneficiary] ??= 0;
            }
            foreach ($originals as $beneficiary => $original) {
                $removed = min($line->quantity, $line->cancelled + $line->returned);
                $target = $eligible ? $original - intdiv($original * $removed, $line->quantity) : 0;
                $this->target($tenant, $lineId, $beneficiary, 'expected', $confirmed ? 0 : $target);
                $this->target($tenant, $lineId, $beneficiary, 'confirmed', $confirmed ? $target : 0);
                $paid = (int) $this->records->query(E::Incentive, $tenant)->where('order_line_id', $lineId)->where('beneficiary', $beneficiary)->where('kind', 'payment')->sum('amount');
                $paid -= (int) $this->records->query(E::Incentive, $tenant)->where('order_line_id', $lineId)->where('beneficiary', $beneficiary)->where('kind', 'recovery')->sum('amount');
                $this->target($tenant, $lineId, $beneficiary, 'adjustment', min(0, ($confirmed ? $target : 0) - $paid));
                $results[$beneficiary] = ['expected' => $confirmed ? 0 : $target, 'confirmed' => $confirmed ? $target : 0, 'paid' => $paid, 'adjustment' => min(0, ($confirmed ? $target : 0) - $paid)];
            }

            return $results;
        });
    }

    public function payment(int $tenant, int $actor, int $lineId, string $beneficiary, int $amount, string $key): array
    {
        return $this->once($tenant, 'incentive-payment:'.$key, compact('lineId', 'beneficiary', 'amount'), function () use ($tenant, $actor, $lineId, $beneficiary, $amount, $key) {
            $totals = $this->sync($tenant, $lineId);
            $total = $totals[$beneficiary] ?? abort(404);
            abort_if(array_sum(array_column($totals, 'adjustment')) < 0, 409, __('yutiv-live_commerce::live.errors.e_fd5271ebbe'));
            abort_unless($amount > 0 && $amount <= $total['confirmed'] - $total['paid'] && $total['adjustment'] === 0, 409, __('yutiv-live_commerce::live.errors.e_72a3a66f26'));
            // 담당자의 승인 및 지급 기록만 저장합니다. 은행 송금 API를 호출하지 않습니다.
            $this->records->create(E::Incentive, $tenant, ['order_line_id' => $lineId, 'beneficiary' => $beneficiary, 'kind' => 'payment',
                'amount' => $amount, 'operation_key' => 'payment:'.$key, 'actor_id' => $actor]);
            $this->audit($tenant, $actor, 'incentive.payment_recorded', ['line_id' => $lineId, 'beneficiary' => $beneficiary, 'amount' => $amount, 'bank_transfer' => 'not_executed']);

            return ['recorded' => $amount, 'bank_transfer' => false];
        });
    }

    private function target(int $tenant, int $line, string $beneficiary, string $kind, int $target): void
    {
        $query = $this->records->query(E::Incentive, $tenant)->where('order_line_id', $line)->where('beneficiary', $beneficiary)->where('kind', $kind);
        $current = (int) (clone $query)->sum('amount');
        if ($target === $current) {
            return;
        }
        app(LiveFinancePeriodService::class)->assertOpen($tenant);
        $last = (clone $query)->max('id') ?? 0;
        $this->records->create(E::Incentive, $tenant, ['order_line_id' => $line, 'beneficiary' => $beneficiary, 'kind' => $kind,
            'amount' => $target - $current, 'operation_key' => 'sync:'.$line.':'.$beneficiary.':'.$kind.':'.$last.':'.$target]);
    }
}
