<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LiveCashService extends LiveWorkflow
{
    private function account(int $tenant, array $d): array
    {
        $fund = $this->row(E::Fund, $tenant, $d['fund_id']);
        abort_unless($fund->currency === 'KRW', 422, __('yutiv-live_commerce::live.finance_currency'));
        $bank = ! empty($d['bank_account_id']) ? $this->row(E::Bank, $tenant, $d['bank_account_id']) : null;
        if ($bank) {
            abort_unless($bank->fund_id === $fund->id, 422);
        }

return [$fund, $bank];
    }

    public function receipt(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'cash-receipt:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $order = $this->row(E::Order, $tenant, $d['order_id'], true);
            [$fund,$bank] = $this->account($tenant, $d);
            $previous = (int) $order->received;
            $amount = (int) $d['amount'];
            $received = $previous + $amount;
            abort_unless($amount > 0 && $received <= 1000000000000, 422);
            app(LiveFundService::class)->entry($tenant, $actor, $fund->id, $d['occurred_on'], 'deposit', $amount * 100, $amount, 'cash-receipt:'.$d['operation_key'], $d['reason'], $order->id, $bank?->id);
            $order->update(['received' => $received, 'payment_state' => 'review']);
            $event = $this->records->create(E::Event, $tenant, ['order_id' => $order->id, 'operation_key' => 'cash:'.$d['operation_key'], 'type' => 'deposit', 'amount' => $received, 'actor_id' => $actor, 'data' => ['previous_confirmation' => $previous, 'increment' => $amount, 'occurred_on' => $d['occurred_on'], 'actual_bank_account_id' => $bank?->id, 'fund_id' => $fund->id, 'reason' => $d['reason'], 'result' => 'review']]);
            try {
                app(LiveOrderService::class)->resolveDeposit($tenant, $actor, $order->id, $received, $d['reason'], 'cash-review-'.$d['operation_key'], true);
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 409) {
                    throw $e;
                }
            }
            $order->refresh();
            $data = $event->data;
            $data['result'] = $order->payment_state;
            $event->update(['data' => $data]);
            $this->audit($tenant, $actor, 'finance.cash_receipt', ['order_id' => $order->id, 'amount' => $amount, 'bank_account_id' => $bank?->id]);

            return ['state' => $order->payment_state, 'received' => $received, 'order_id' => $order->id];
        });
    }

    public function recheck(int $tenant, int $actor, array $d): array
    {
        $order = $this->row(E::Order, $tenant, $d['order_id']);

        return app(LiveOrderService::class)->resolveDeposit($tenant, $actor, $order->id, $order->received, $d['reason'], 'cash-review-'.$d['operation_key'], true);
    }

    public function refund(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'cash-refund:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $order = $this->row(E::Order, $tenant, $d['order_id'], true);
            [$fund,$bank] = $this->account($tenant, $d);
            $due = (int) $this->records->query(E::OrderLine, $tenant)->where('order_id', $order->id)->selectRaw('COALESCE(SUM((quantity-cancelled-returned)*unit_price),0) due')->value('due') + $order->shipping;
            $refunded = (int) $this->records->query(E::Event, $tenant)->where('order_id', $order->id)->where('type', 'refund')->sum('amount');
            $limit = max(0, $order->received - $due - $refunded);
            $amount = (int) $d['amount'];
            abort_unless($amount > 0 && $amount <= $limit, 409, __('yutiv-live_commerce::live.finance_refund_limit'));
            app(LiveFundService::class)->entry($tenant, $actor, $fund->id, $d['occurred_on'], 'refund', -$amount * 100, -$amount, 'cash-refund:'.$d['operation_key'], $d['reason'], $order->id, $bank?->id);
            $this->records->create(E::Event, $tenant, ['order_id' => $order->id, 'operation_key' => 'cash-refund:'.$d['operation_key'], 'type' => 'refund', 'amount' => $amount, 'actor_id' => $actor, 'data' => ['occurred_on' => $d['occurred_on'], 'fund_id' => $fund->id, 'actual_bank_account_id' => $bank?->id, 'reason' => $d['reason'], 'manual_transfer_confirmed' => true, 'bank_transfer' => 'not_executed']]);
            $this->audit($tenant, $actor, 'finance.cash_refund', ['order_id' => $order->id, 'amount' => $amount]);

            return ['recorded' => $amount, 'bank_transfer' => false];
        });
    }

    public function incentive(int $tenant, int $actor, array $d, bool $recovery = false): array
    {
        return $this->once($tenant, 'finance-incentive:'.$d['operation_key'], $d + ['recovery' => $recovery], function () use ($tenant, $actor, $d, $recovery) {
            $fund = $this->row(E::Fund, $tenant, $d['fund_id']);
            abort_unless($fund->currency === 'KRW', 422);
            if ($recovery) {
                $result = app(LiveOrderAdjustmentService::class)->recovery($tenant, $actor, $d['line_id'], $d['beneficiary'], $d['amount'], $d['reason'], 'cash-'.$d['operation_key']);
            } else {
                $result = app(LiveIncentiveService::class)->payment($tenant, $actor, $d['line_id'], $d['beneficiary'], $d['amount'], 'cash-'.$d['operation_key']);
            }$ledger = $this->records->query(E::Incentive, $tenant)->where('operation_key',($recovery ? 'recovery:cash-' : 'payment:cash-').$d['operation_key'])->firstOrFail();
            $sign = $recovery ? 1 : -1;
            app(LiveFundService::class)->entry($tenant,$actor,$fund->id,$d['occurred_on'],$recovery ? 'incentive_recovery' : 'incentive_payment',$sign * $d['amount'] * 100,$sign * $d['amount'],'incentive:'.$d['operation_key'],$d['reason'],null,null,$ledger->id);

            return $result;
        });
    }
}
