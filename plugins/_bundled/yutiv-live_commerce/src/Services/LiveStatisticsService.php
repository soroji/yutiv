<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Carbon\CarbonImmutable;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;

class LiveStatisticsService extends LiveWorkflow
{
    public function summary(int $tenant, string $from, string $to, string $basis = 'created_at', string $period = 'day', array $filters = []): array
    {
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $timezone = LiveTenant::findOrFail($tenant)->timezone;
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $timezone)->endOfDay()->utc();
        $basis = $basis === 'paid_at' ? 'paid_at' : 'created_at';
        $buckets = [];
        $orders = $this->records->query(E::Order, $tenant)->whereBetween($basis, [$start, $end])->orderBy('id');
        $orders->chunkById(100, function ($orders) use ($tenant, $timezone, $period, $basis, $filters, &$buckets) {
            foreach ($orders as $order) {
                $date = CarbonImmutable::parse($order->$basis, 'UTC')->setTimezone($timezone);
                $key = match ($period) {
                    'week' => $date->startOfWeek(1)->format('Y-m-d'), 'month' => $date->format('Y-m'), default => $date->format('Y-m-d')
                };
                $bucket = $buckets[$key] ?? ['ordered' => 0, 'paid' => 0, 'cancelled' => 0, 'returned' => 0, 'refund_recorded' => 0, 'net_sales' => 0, 'quantity' => 0, 'shipped' => 0, 'direct_sales' => 0];
                $lines = $this->records->query(E::OrderLine, $tenant)->where('order_id', $order->id);
                if (! empty($filters['broadcast_id'])) {
                    $lines->where('snapshot->broadcast_id', (int) $filters['broadcast_id']);
                }
                if (! empty($filters['product_id'])) {
                    $lines->whereIn('option_id', $this->records->query(E::Option, $tenant)->where('product_id', (int) $filters['product_id'])->select('id'));
                }
                if (! empty($filters['option_id'])) {
                    $lines->where('option_id', (int) $filters['option_id']);
                }
                foreach ($lines->get() as $line) {
                    $correction = $this->records->query(E::Event, $tenant)->where('order_id', $order->id)->where('type', 'attribution_correction')->where('data->line_id', $line->id)->orderByDesc('id')->first();
                    $attribution = $correction?->data['after'] ?? $line->snapshot;
                    if (! empty($filters['channel_id']) && (int) $attribution['channel_id'] !== (int) $filters['channel_id']) {
                        continue;
                    }
                    if (! empty($filters['showhost_id']) && (int) ($attribution['showhost_id'] ?? 0) !== (int) $filters['showhost_id'] && empty($attribution['host_shares'][$filters['showhost_id']])) {
                        continue;
                    }
                    $gross = $line->quantity * $line->unit_price;
                    $cancel = $line->cancelled * $line->unit_price;
                    $return = $line->returned * $line->unit_price;
                    $bucket['ordered'] += $gross;
                    $bucket['cancelled'] += $cancel;
                    $bucket['returned'] += $return;
                    $bucket['quantity'] += $line->quantity - $line->cancelled - $line->returned;
                    $bucket['shipped'] += $line->shipped;
                    if ($order->payment_state === 'paid') {
                        $bucket['paid'] += $gross;
                        $bucket['net_sales'] += $gross - $cancel - $return;
                        if (! $line->snapshot['sale_link_id']) {
                            $bucket['direct_sales'] += $gross - $cancel - $return;
                        }
                    }
                }
                // 환불은 금융 지급 기록입니다. 취소/반품과 다시 합산해 순매출을 이중 차감하지 않습니다.
                if ($filters === []) {
                    $bucket['refund_recorded'] += (int) $this->records->query(E::Event, $tenant)->where('order_id', $order->id)->where('type', 'refund')->sum('amount');
                }
                $buckets[$key] = $bucket;
            }
        });
        ksort($buckets);
        $stock = $this->records->query(E::Unit, $tenant)->select('state')->selectRaw('COUNT(*) AS quantity')->groupBy('state')->get()->pluck('quantity', 'state')->all();
        $incentives = $this->records->query(E::Incentive, $tenant)->select('kind')->selectRaw('SUM(amount) AS amount')->groupBy('kind')->get()->pluck('amount', 'kind')->all();

        return ['basis' => $basis === 'paid_at' ? '입금일' : '주문일', 'timezone' => $timezone, 'week_starts' => '월요일', 'periods' => $buckets, 'stock' => $stock, 'incentives' => $incentives];
    }
}
