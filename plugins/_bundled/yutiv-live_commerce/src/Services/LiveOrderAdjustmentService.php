<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;

class LiveOrderAdjustmentService extends LiveWorkflow
{
    public function attribution(int $tenant, int $actor, int $lineId, int $channelId, ?int $hostId, string $reason, string $key): array
    {
        return $this->once($tenant, 'attribution:'.$key, compact('lineId', 'channelId', 'hostId', 'reason'), function () use ($tenant, $actor, $lineId, $channelId, $hostId, $reason, $key) {
            $line = $this->row(E::OrderLine, $tenant, $lineId, true);
            $this->row(E::Channel, $tenant, $channelId, true);
            if ($hostId) {
                $this->row(E::Showhost, $tenant, $hostId, true);
            }
            $latest = $this->records->query(E::Event, $tenant)->where('order_id', $line->order_id)->where('type', 'attribution_correction')->where('data->line_id', $lineId)->orderByDesc('id')->first();
            $before = $latest?->data['after'] ?? $line->snapshot;
            $after = $before;
            $after['channel_id'] = $channelId;
            $after['showhost_id'] = $hostId;
            $after['host_shares'] = $hostId ? [$hostId => 10000] : $line->snapshot['host_shares'];
            $after['incentives'] = IncentiveCalculator::amounts($line->snapshot['basis'], $line->snapshot['channel_rate'], $line->snapshot['host_rate'], $channelId, $after['host_shares']);
            $this->records->create(E::Event, $tenant, ['order_id' => $line->order_id, 'operation_key' => $key, 'type' => 'attribution_correction', 'amount' => 0,
                'data' => ['line_id' => $lineId, 'before' => $before, 'after' => $after, 'reason' => $reason], 'actor_id' => $actor]);
            app(LiveIncentiveService::class)->sync($tenant, $lineId);
            $this->audit($tenant, $actor, 'order.attribution_corrected', ['line_id' => $lineId, 'channel_id' => $channelId, 'showhost_id' => $hostId, 'reason' => $reason]);

            return ['saved' => true, 'original_snapshot_unchanged' => true];
        });
    }

    public function bank(int $tenant, int $actor, int $orderId, int $bankId, string $reason, string $key): array
    {
        return $this->once($tenant, 'bank-notice:'.$key, compact('orderId', 'bankId', 'reason'), function () use ($tenant, $actor, $orderId, $bankId, $reason, $key) {
            $order = $this->row(E::Order, $tenant, $orderId, true);
            abort_unless(in_array($order->payment_state, ['pending', 'review', 'expired'], true), 409, __('yutiv-live_commerce::live.errors.e_74fa717509'));
            $bank = $this->row(E::Bank, $tenant, $bankId, true);
            abort_unless($bank->active && $bank->customer_visible, 409, __('yutiv-live_commerce::live.errors.e_34e5a80449'));
            $notice = ['bank' => $bank->bank, 'number' => $bank->account_number, 'holder' => $bank->holder, 'account_id' => $bank->id];
            $this->records->create(E::Event, $tenant, ['order_id' => $orderId, 'operation_key' => $key, 'type' => 'bank_notice', 'amount' => 0,
                'data' => ['bank_id' => $bankId, 'encrypted_notice' => encrypt($notice), 'reason' => $reason, 'notification_state' => 'customer_portal_available', 'external_message_sent' => false], 'actor_id' => $actor]);
            $this->audit($tenant, $actor, 'order.bank_change_notified', ['order_id' => $orderId, 'bank_id' => $bankId, 'notification_state' => 'customer_portal_available']);

            return ['saved' => true, 'external_message_sent' => false];
        });
    }

    public function recovery(int $tenant, int $actor, int $lineId, string $beneficiary, int $amount, string $reason, string $key): array
    {
        return $this->once($tenant, 'recovery:'.$key, compact('lineId', 'beneficiary', 'amount', 'reason'), function () use ($tenant, $actor, $lineId, $beneficiary, $amount, $reason, $key) {
            $totals = app(LiveIncentiveService::class)->sync($tenant, $lineId);
            $total = $totals[$beneficiary] ?? abort(404);
            abort_unless($amount > 0 && $amount <= -$total['adjustment'], 409, __('yutiv-live_commerce::live.errors.e_3a4829969e'));
            $this->records->create(E::Incentive, $tenant, ['order_line_id' => $lineId, 'beneficiary' => $beneficiary, 'kind' => 'recovery', 'amount' => $amount, 'operation_key' => 'recovery:'.$key, 'actor_id' => $actor]);
            $this->audit($tenant, $actor, 'incentive.recovery_recorded', ['line_id' => $lineId, 'beneficiary' => $beneficiary, 'amount' => $amount, 'reason' => $reason, 'bank_transfer' => false]);
            app(LiveIncentiveService::class)->sync($tenant, $lineId);

            return ['recorded' => $amount, 'bank_transfer' => false];
        });
    }
}
