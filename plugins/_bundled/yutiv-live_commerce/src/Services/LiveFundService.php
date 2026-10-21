<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\FundEntryKind;
use Plugins\Yutiv\LiveCommerce\Enums\LiveCurrency;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Enums\TransferStatus;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Support\LiveMoney;

class LiveFundService extends LiveWorkflow
{
    public function balance(int $tenant, int $fund): int
    {
        return (int) $this->records->query(E::FundEntry, $tenant)->where('fund_id', $fund)->sum('amount_minor');
    }

    /** 자금 계정과 업체 잠금 안에서 잔액과 원본 중복을 검사한다. */
    public function entry(int $tenant, int $actor, int $fundId, string $date, string $kind, int $minor, ?int $krw, string $source, string $memo, ?int $order = null, ?int $bank = null, ?int $incentive = null): void
    {
        $this->records->transaction(function () use ($tenant, $actor, $fundId, $date, $kind, $minor, $krw, $source, $memo, $order, $bank, $incentive) {
            LiveTenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            app(LiveFinancePeriodService::class)->assertOpen($tenant, $date);
            $fund = $this->row(E::Fund, $tenant, $fundId, true);
            abort_unless($fund->active, 409, __('yutiv-live_commerce::live.finance_inactive'));
            abort_unless($fund->currency !== 'KRW' || $minor % 100 === 0, 422, __('yutiv-live_commerce::live.money_invalid'));
            if ($minor < 0) {
                $entries = $this->records->query(E::FundEntry, $tenant)->where('fund_id', $fundId);
                $before = (int) (clone $entries)->where('occurred_on', '<', $date)->sum('amount_minor');
                $atDate = $before + (int) (clone $entries)->where('occurred_on', $date)->sum('amount_minor');
                $daily = (clone $entries)->where('occurred_on', '>=', $date)->select('occurred_on')->selectRaw('SUM(SUM(amount_minor)) OVER (ORDER BY occurred_on ROWS UNBOUNDED PRECEDING) + ? balance', [$before])->groupBy('occurred_on');
                $minimum = $entries->getModel()->getConnection()->query()->fromSub($daily->toBase(), 'daily_balances')->min('balance');
                abort_unless(min($atDate, $minimum === null ? $atDate : (int) $minimum) + $minor >= 0, 409, __('yutiv-live_commerce::live.finance_funds'));
            }
            $snapshot = null;
            $identity = null;
            if ($bank) {
                $account = $this->row(E::Bank, $tenant, $bank);
                $snapshot = ['bank' => $account->bank, 'holder' => $account->holder, 'number' => $account->account_number, 'account_id' => $account->id];
                $identity = hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), (string) config('app.key'));
            }
            $this->records->create(E::FundEntry, $tenant, ['fund_id' => $fundId, 'occurred_on' => $date, 'kind' => FundEntryKind::from($kind)->value, 'amount_minor' => $minor, 'amount_krw' => $krw, 'source_key' => $source, 'incentive_id' => $incentive, 'order_id' => $order, 'bank_account_id' => $bank, 'bank_snapshot' => $snapshot, 'bank_identity_key' => $identity, 'actor_id' => $actor, 'memo' => $memo]);
        });
    }

    public function fund(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-fund:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            if (! empty($d['id'])) {
                $fund = $this->row(E::Fund, $tenant, $d['id'], true);
                abort_unless($fund->currency === $d['currency'], 409);
                $fund->update(['name' => $d['name'], 'active' => $d['active']]);
            } else {
                $fund = $this->records->create(E::Fund, $tenant, ['name' => $d['name'], 'currency' => $d['currency'], 'active' => true, 'actor_id' => $actor]);
            }$this->audit($tenant, $actor, 'finance.fund', ['fund_id' => $fund->id]);

            return ['fund_id' => $fund->id];
        });
    }

    public function adjust(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-fund-adjust:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $fund = $this->row(E::Fund, $tenant, $d['fund_id']);
            $money = LiveMoney::convert($d['amount_original'], LiveCurrency::from($fund->currency), $d['exchange_rate']);
            abort_unless($money['amount_minor'] > 0, 422);
            $sign = $d['direction'] === 'in' ? 1 : -1;
            $kind = $d['kind'];
            if ($kind === 'opening') {
                abort_if($this->records->query(E::FundEntry, $tenant)->where('fund_id', $fund->id)->where('kind', 'opening')->exists(), 409, __('yutiv-live_commerce::live.finance_opening'));
            }
            $this->entry($tenant, $actor, $fund->id, $d['occurred_on'], $kind, $sign * $money['amount_minor'], $sign * $money['amount_krw'], $kind.':'.$d['operation_key'], $d['reason']);
            $this->audit($tenant, $actor, 'finance.fund_adjustment', ['fund_id' => $fund->id, 'reason' => $d['reason']]);

            return ['recorded' => true];
        });
    }

    public function transfer(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-transfer:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            app(LiveFinancePeriodService::class)->assertOpen($tenant, $d['requested_on']);
            $from = $this->row(E::Fund, $tenant, $d['from_fund_id']);
            $to = $this->row(E::Fund, $tenant, $d['to_fund_id']);
            abort_unless($from->id !== $to->id && $from->active && $to->active, 409);
            abort_unless(LiveMoney::minor($d['amount_out']) > 0 && LiveMoney::minor($d['amount_in']) > 0, 422);
            $receiverFee = LiveMoney::minor($d['fee_original'] ?? '0') > 0 && (int) ($d['fee_fund_id'] ?? 0) === $to->id;
            if ($from->currency === $to->currency) {
                $net = LiveMoney::minor($d['amount_out']) - ($receiverFee ? LiveMoney::minor($d['fee_original']) : 0);
                abort_unless($net === LiveMoney::minor($d['amount_in']) && ($from->currency === 'CNY' || LiveMoney::minor($d['exchange_rate'], 6) === 1000000), 422);
            }
            if (LiveMoney::minor($d['fee_original'] ?? '0') > 0) {
                abort_unless(! empty($d['fee_fund_id']), 422, __('yutiv-live_commerce::live.finance_fee_fund_required'));
                $fee = $this->row(E::Fund, $tenant, $d['fee_fund_id']);
                abort_unless($fee->active, 409, __('yutiv-live_commerce::live.finance_inactive'));
                abort_unless($fee->currency === $d['fee_currency'], 422);
                $feeKrw = LiveMoney::convert($d['fee_original'], LiveCurrency::from($d['fee_currency']), $d['fee_currency'] === 'KRW' ? '1' : $d['exchange_rate'])['amount_krw'];
            } else {
                $feeKrw = 0;
            }
            $record = $this->records->create(E::MoneyTransfer, $tenant, array_intersect_key($d, array_flip(['from_fund_id', 'to_fund_id', 'amount_out', 'amount_in', 'exchange_rate', 'fee_original', 'fee_currency', 'fee_fund_id', 'requested_on', 'reference', 'memo'])) + ['fee_krw' => $feeKrw, 'status' => TransferStatus::Requested->value, 'actor_id' => $actor]);
            $this->audit($tenant, $actor, 'finance.transfer_requested', ['transfer_id' => $record->id]);

            return ['transfer_id' => $record->id];
        });
    }

    public function transferStep(int $tenant, int $actor, int $id, string $step, array $d): array
    {
        return $this->once($tenant, 'finance-transfer-'.$step.':'.$d['operation_key'], ['id' => $id] + $d, function () use ($tenant, $actor, $id, $step, $d) {
            $record = $this->row(E::MoneyTransfer, $tenant, $id, true);
            $from = $this->row(E::Fund, $tenant, $record->from_fund_id);
            $to = $this->row(E::Fund, $tenant, $record->to_fund_id);
            $date = $d['occurred_on'];
            app(LiveFinancePeriodService::class)->assertOpen($tenant, $date);
            if ($step === 'withdraw') {
                abort_unless($record->status === TransferStatus::Requested->value && $date >= $record->requested_on, 409);
                $money = LiveMoney::convert((string) $record->amount_out, LiveCurrency::from($from->currency), $from->currency === 'KRW' ? '1' : (string) $record->exchange_rate);
                $this->entry($tenant, $actor, $from->id, $date, 'transfer_out', -$money['amount_minor'], -$money['amount_krw'], 'transfer-out:'.$id, '수동 출금 확인 #'.$id);
                if (LiveMoney::minor((string) $record->fee_original) > 0 && (int) $record->fee_fund_id !== $to->id) {
                    $this->entry($tenant, $actor, $record->fee_fund_id, $date, 'fee', -LiveMoney::minor((string) $record->fee_original), -$record->fee_krw, 'transfer-fee:'.$id, '환전 수수료 #'.$id);
                }$record->update(['status' => TransferStatus::Withdrawn->value, 'withdrawn_on' => $date]);
            } elseif ($step === 'receive') {
                abort_unless($record->status === TransferStatus::Withdrawn->value && $date >= $record->withdrawn_on, 409);
                $actual = $d['amount_original'] ?? (string) $record->amount_in;
                $rate = $d['exchange_rate'] ?? (string) $record->exchange_rate;
                if ($from->currency === $to->currency) {
                    abort_unless(LiveMoney::minor($actual) === LiveMoney::minor((string) $record->amount_in) && LiveMoney::minor($rate, 6) === LiveMoney::minor((string) $record->exchange_rate, 6), 422);
                }
                $money = LiveMoney::convert($actual, LiveCurrency::from($to->currency), $to->currency === 'KRW' ? '1' : $rate);
                $feePending = LiveMoney::minor((string) $record->fee_original) > 0 && (int) $record->fee_fund_id === $to->id && ! $this->records->query(E::FundEntry, $tenant)->where('source_key', 'transfer-fee:'.$id)->exists();
                $feeMoney = $feePending ? LiveMoney::convert((string) $record->fee_original, LiveCurrency::from($to->currency), $to->currency === 'KRW' ? '1' : $rate) : ['amount_minor' => 0, 'amount_krw' => 0];
                // User confirms net receipt. Journal the gross and the separately deducted receiver fee atomically.
                $this->entry($tenant, $actor, $to->id, $date, 'transfer_in', $money['amount_minor'] + $feeMoney['amount_minor'], $money['amount_krw'] + $feeMoney['amount_krw'], 'transfer-in:'.$id, '수동 순수령 확인 · 수령계정 수수료 별도 #'.$id);
                if ($feePending) {
                    $this->entry($tenant, $actor, $to->id, $date, 'fee', -$feeMoney['amount_minor'], -$feeMoney['amount_krw'], 'transfer-fee:'.$id, '수령계정 차감 수수료 #'.$id);
                    $record->fee_krw = $feeMoney['amount_krw'];
                }
                $record->update(['status' => TransferStatus::Received->value, 'received_on' => $date, 'received_original' => $money['amount_original'], 'received_rate' => $rate]);
            } elseif ($step === 'cancel') {
                abort_unless(in_array($record->status, [TransferStatus::Requested->value, TransferStatus::Withdrawn->value], true), 409);
                $record->update(['status' => $record->status === TransferStatus::Withdrawn->value ? TransferStatus::CancelPending->value : TransferStatus::Cancelled->value, 'cancelled_on' => $date, 'memo' => trim(($record->memo ?? '')."\n취소: ".$d['reason'])]);
            } elseif ($step === 'cancel-confirm') {
                abort_unless($record->status === TransferStatus::CancelPending->value && $date >= $record->withdrawn_on && isset($d['amount_original']) && LiveMoney::minor($d['amount_original']) === LiveMoney::minor((string) $record->amount_out), 409);
                $money = LiveMoney::convert((string) $record->amount_out, LiveCurrency::from($from->currency), $from->currency === 'KRW' ? '1' : (string) $record->exchange_rate);
                $this->entry($tenant, $actor, $from->id, $date, 'transfer_return', $money['amount_minor'], $money['amount_krw'], 'transfer-return:'.$id, '취소 원금 반환 실제 확인: '.$d['reason']);
                $record->update(['status' => TransferStatus::Cancelled->value, 'cancelled_on' => $date]);
            } else {
                abort(404);
            }$this->audit($tenant, $actor, 'finance.transfer_'.$step, ['transfer_id' => $id, 'reason' => $d['reason'] ?? '', 'occurred_on' => $date]);

            return ['transfer_id' => $id, 'status' => $record->status];
        });
    }
}
