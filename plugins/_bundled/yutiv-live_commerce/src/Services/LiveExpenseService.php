<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Carbon\CarbonImmutable as Date;
use Plugins\Yutiv\LiveCommerce\Enums\ExpenseStatus;
use Plugins\Yutiv\LiveCommerce\Enums\LiveCurrency;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Support\LiveMoney;

class LiveExpenseService extends LiveWorkflow
{
    public const DEFAULTS = [['rent', '임대료', 'fixed', 'operating'], ['salary', '인건비', 'fixed', 'operating'], ['hosting', '솔루션·호스팅비', 'fixed', 'operating'], ['utilities', '통신·공과금', 'fixed', 'operating'], ['purchase', '사입·제조비', 'variable', 'inventory'], ['shipping', '물류·배송비', 'variable', 'operating'], ['marketing', '마케팅·광고비', 'variable', 'operating'], ['fees', '플랫폼·결제 수수료', 'variable', 'fee'], ['other', '기타 운영비', 'variable', 'operating']];

    public function defaults(int $tenant): void
    {
        foreach (self::DEFAULTS as [$code,$name,$group,$treatment]) {
            if (! $this->records->query(E::ExpenseCategory, $tenant)->where('code', $code)->exists()) {
                $this->records->create(E::ExpenseCategory, $tenant, ['code' => $code, 'name' => $name, 'cost_group' => $group, 'treatment' => $treatment, 'active' => true]);
            }
        }
    }

    public function category(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-category:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $values = array_intersect_key($d, array_flip(['name', 'cost_group', 'treatment', 'active', 'parent_id']));
            if (! empty($d['parent_id'])) {
                $parent = $this->row(E::ExpenseCategory, $tenant, $d['parent_id']);
                abort_unless(! $parent->parent_id && $parent->cost_group === $d['cost_group'], 422);
                if (! empty($d['id'])) {
                    abort_unless($parent->id !== (int) $d['id'], 422);
                }
            }
            if (! empty($d['id'])) {
                $row = $this->row(E::ExpenseCategory, $tenant, $d['id'], true);
                abort_if($this->records->query(E::ExpenseCategory, $tenant)->where('parent_id', $row->id)->exists() && ! empty($values['parent_id']), 422);
                $row->update($values);
            } else {
                $row = $this->records->create(E::ExpenseCategory, $tenant, $values + ['code' => 'custom-'.bin2hex(random_bytes(8))]);
            }$this->audit($tenant, $actor, 'finance.category', ['category_id' => $row->id]);

            return ['category_id' => $row->id];
        });
    }

    private function createExpense(int $tenant, int $actor, array $d, string $sourceKind, string $sourceKey, bool $pending = false): LiveRecord
    {
        if ($sourceKind !== 'receipt') {
            app(LiveFinancePeriodService::class)->assertOpen($tenant, $d['occurred_on']);
        }$category = $this->row(E::ExpenseCategory, $tenant, $d['category_id']);
        abort_unless($category->active, 409, __('yutiv-live_commerce::live.finance_inactive'));
        $currency = LiveCurrency::from($d['currency']);
        $minor = LiveMoney::minor($d['amount_original']);
        abort_unless($minor > 0, 422);
        $money = $pending ? ['currency' => $currency->value, 'amount_original' => LiveMoney::decimal($minor), 'exchange_rate' => null, 'amount_krw' => null] : array_diff_key(LiveMoney::convert($d['amount_original'], $currency, $currency === LiveCurrency::KRW ? '1' : $d['exchange_rate']), ['amount_minor' => true]);

        return $this->records->create(E::Expense, $tenant, $money + ['category_id' => $category->id, 'category_code' => $category->parent_id ? $this->row(E::ExpenseCategory, $tenant, $category->parent_id)->code : $category->code, 'category_name' => $category->name, 'cost_group' => $category->cost_group, 'treatment' => $category->treatment, 'occurred_on' => $d['occurred_on'], 'rate_date' => $pending ? null : ($d['rate_date'] ?? $d['occurred_on']), 'rate_source' => $pending ? ExpenseStatus::Pending->value : ($d['rate_source'] ?? 'manual'), 'source_kind' => \Plugins\Yutiv\LiveCommerce\Enums\ExpenseSourceKind::from($sourceKind)->value, 'source_key' => $sourceKey, 'actor_id' => $actor, 'memo' => $d['memo'] ?? null, 'counterparty' => $d['counterparty'] ?? null, 'status' => $pending ? ExpenseStatus::Pending->value : ExpenseStatus::Posted->value]);
    }

    public function expense(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-expense:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $expense = $this->createExpense($tenant, $actor, $d, 'manual', $d['operation_key']);
            if (($d['payment_status'] ?? 'unpaid') === 'paid') {
                $this->payWork($tenant, $actor, $expense->id, ['fund_id' => $d['fund_id'], 'paid_on' => $d['paid_on'], 'amount_original' => $d['amount_original'], 'operation_key' => 'expense-'.$d['operation_key'], 'memo' => $d['memo'] ?? '']);
            }$this->audit($tenant, $actor, 'finance.expense_created', ['expense_id' => $expense->id]);

            return ['expense_id' => $expense->id];
        });
    }

    /** 같은 입고를 한번만 매입 채무로 연결한다. 입고 매입은 운영 비용이 아니다. */
    private function receiptLiability(int $tenant, int $actor, int $receiptId): LiveRecord
    {
        $existing = $this->records->query(E::Expense, $tenant)->where('receipt_id', $receiptId)->first();
        if ($existing) {
            return $existing;
        }
        $receipt = $this->row(E::Receipt, $tenant, $receiptId);
        $items = $this->records->query(E::ReceiptItem, $tenant)->where('receipt_id', $receiptId)->get();
        abort_unless($items->isNotEmpty() && ! $items->contains(fn ($i) => $i->purchase_amount === null), 409, __('yutiv-live_commerce::live.finance_unknown_cost'));
        $currencies = $items->pluck('currency')->unique();
        abort_unless($currencies->count() === 1, 409);
        $currency = $currencies->first();
        $minor = 0;
        foreach ($items as $item) {
            $minor += LiveMoney::minor((string) ($item->unit_original ?? $item->unit_cost)) * $item->quantity;
        }
        $date = $receipt->received_at->setTimezone(LiveTenant::findOrFail($tenant)->timezone)->format('Y-m-d');
        $category = $this->records->query(E::ExpenseCategory, $tenant)->where('code', 'purchase')->firstOrFail();
        $expense = $this->createExpense($tenant, $actor, ['category_id' => $category->id, 'currency' => $currency, 'amount_original' => LiveMoney::decimal($minor), 'exchange_rate' => (string) ($items->first()->exchange_rate ?? '1'), 'occurred_on' => $date, 'rate_date' => $items->first()->rate_date ?? $date, 'rate_source' => $items->first()->rate_source ?? 'receipt'], 'receipt', (string) $receiptId);
        $expense->update(['receipt_id' => $receiptId, 'amount_krw' => (int) $items->sum('purchase_amount')]);

        return $expense;
    }

    public function linkReceipt(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-expense-link:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $expense = $this->row(E::Expense, $tenant, $d['expense_id'], true);
            $receipt = $this->row(E::Receipt, $tenant, $d['receipt_id']);
            app(LiveFinancePeriodService::class)->assertOpen($tenant, $expense->occurred_on);
            abort_unless($expense->status === ExpenseStatus::Posted->value && $expense->treatment === 'inventory' && $expense->receipt_id === null && ! $this->records->query(E::Expense, $tenant)->where('receipt_id', $receipt->id)->exists(), 409);
            $items = $this->records->query(E::ReceiptItem, $tenant)->where('receipt_id', $receipt->id)->get();
            abort_unless($items->isNotEmpty() && ! $items->contains(fn ($i) => $i->purchase_amount === null) && $items->pluck('currency')->unique()->all() === [$expense->currency] && (int) $items->sum('purchase_amount') === $expense->amount_krw, 409, __('yutiv-live_commerce::live.finance_link_amount'));
            $minor = 0;
            foreach ($items as $item) {
                $minor += LiveMoney::minor((string) ($item->unit_original ?? $item->unit_cost)) * $item->quantity;
            }abort_unless($minor === LiveMoney::minor((string) $expense->amount_original), 409);
            $expense->update(['receipt_id' => $receipt->id]);
            $this->audit($tenant, $actor, 'finance.expense_linked', ['expense_id' => $expense->id, 'receipt_id' => $receipt->id, 'reason' => $d['reason']]);

            return ['expense_id' => $expense->id];
        });
    }

    public function pay(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-payment:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $expense = ! empty($d['receipt_id']) ? $this->receiptLiability($tenant, $actor, $d['receipt_id']) : $this->row(E::Expense, $tenant, $d['expense_id']);

            return $this->payWork($tenant, $actor, $expense->id, $d);
        });
    }

    private function payWork(int $tenant, int $actor, int $expenseId, array $d): array
    {
        $expense = $this->row(E::Expense, $tenant, $expenseId, true);
        abort_unless($expense->status === ExpenseStatus::Posted->value && $expense->amount_krw !== null && $d['paid_on'] >= $expense->occurred_on, 409, __('yutiv-live_commerce::live.finance_pending'));
        $fund = $this->row(E::Fund, $tenant, $d['fund_id']);
        abort_unless($fund->currency === $expense->currency, 422, __('yutiv-live_commerce::live.finance_currency'));
        $original = LiveMoney::minor((string) $expense->amount_original);
        $paid = LiveMoney::minor((string) $this->records->query(E::ExpensePayment, $tenant)->where('expense_id', $expenseId)->sum('amount_original')) - LiveMoney::minor((string) $this->records->query(E::ExpenseRecovery, $tenant)->where('expense_id', $expenseId)->sum('amount_original'));
        $minor = LiveMoney::minor($d['amount_original']);
        abort_unless($minor > 0 && $paid + $minor <= $original, 409, __('yutiv-live_commerce::live.finance_payment_limit'));
        $krw = LiveMoney::allocation($expense->amount_krw, $original, $paid, $minor);
        $rounding = $krw - LiveMoney::convert($d['amount_original'], LiveCurrency::from($expense->currency), (string) $expense->exchange_rate)['amount_krw'];
        $payment = $this->records->create(E::ExpensePayment, $tenant, ['expense_id' => $expenseId, 'fund_id' => $fund->id, 'paid_on' => $d['paid_on'], 'amount_original' => LiveMoney::decimal($minor), 'amount_krw' => $krw, 'rounding_delta' => $rounding, 'operation_key' => $d['operation_key'], 'actor_id' => $actor, 'memo' => $d['memo'] ?? null]);
        app(LiveFundService::class)->entry($tenant, $actor, $fund->id, $d['paid_on'], 'payment', -$minor, -$krw, 'expense-payment:'.$payment->id, '지출 #'.$expenseId.' 지급');
        $this->audit($tenant, $actor, 'finance.payment', ['expense_id' => $expenseId, 'payment_id' => $payment->id]);

        return ['expense_id' => $expenseId, 'payment_id' => $payment->id, 'remaining_original' => LiveMoney::decimal($original - $paid - $minor)];
    }

    public function confirmRate(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-rate-confirm:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $old = $this->row(E::Expense, $tenant, $d['expense_id'], true);
            abort_unless($old->status === ExpenseStatus::Pending->value && $old->amount_krw === null, 409);
            app(LiveFinancePeriodService::class)->assertOpen($tenant, $old->occurred_on);
            $money = LiveMoney::convert((string) $old->amount_original, LiveCurrency::from($old->currency), $d['exchange_rate']);
            $old->update(['exchange_rate' => $money['exchange_rate'], 'amount_krw' => $money['amount_krw'], 'rate_date' => $d['rate_date'], 'rate_source' => $d['rate_source'], 'status' => ExpenseStatus::Posted->value]);
            $this->audit($tenant, $actor, 'finance.pending_rate_confirmed', ['expense_id' => $old->id, 'rate_source' => $d['rate_source']]);

            return ['expense_id' => $old->id];
        });
    }

    public function recover(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-recovery:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $payment = $this->row(E::ExpensePayment, $tenant, $d['payment_id'], true);
            $expense = $this->row(E::Expense, $tenant, $payment->expense_id);
            $fund = $this->row(E::Fund, $tenant, $d['fund_id']);
            abort_unless($fund->currency === $expense->currency && $d['recovered_on'] >= $payment->paid_on, 422);
            $total = LiveMoney::minor((string) $payment->amount_original);
            $recovered = LiveMoney::minor((string) $this->records->query(E::ExpenseRecovery, $tenant)->where('payment_id', $payment->id)->sum('amount_original'));
            $part = LiveMoney::minor($d['amount_original']);
            abort_unless($part > 0 && $part + $recovered <= $total, 409, __('yutiv-live_commerce::live.finance_payment_limit'));
            $krw = LiveMoney::allocation($payment->amount_krw, $total, $recovered, $part);
            $row = $this->records->create(E::ExpenseRecovery, $tenant, ['payment_id' => $payment->id, 'expense_id' => $expense->id, 'fund_id' => $fund->id, 'recovered_on' => $d['recovered_on'], 'amount_original' => LiveMoney::decimal($part), 'amount_krw' => $krw, 'rounding_delta' => $krw - LiveMoney::convert($d['amount_original'], LiveCurrency::from($expense->currency), (string) $expense->exchange_rate)['amount_krw'], 'operation_key' => $d['operation_key'], 'reason' => $d['reason'], 'actor_id' => $actor]);
            app(LiveFundService::class)->entry($tenant, $actor, $fund->id, $d['recovered_on'], 'recovery', $part, $krw, 'payment-recovery:'.$row->id, $d['reason']);
            $this->audit($tenant, $actor, 'finance.payment_recovery', ['payment_id' => $payment->id, 'recovery_id' => $row->id, 'reason' => $d['reason']]);

            return ['recovery_id' => $row->id];
        });
    }

    public function void(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-expense-void:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $expense = $this->row(E::Expense, $tenant, $d['expense_id'], true);
            app(LiveFinancePeriodService::class)->assertOpen($tenant, $d['occurred_on']);
            $paid = LiveMoney::minor((string) $this->records->query(E::ExpensePayment, $tenant)->where('expense_id', $expense->id)->sum('amount_original')) - LiveMoney::minor((string) $this->records->query(E::ExpenseRecovery, $tenant)->where('expense_id', $expense->id)->sum('amount_original'));
            abort_unless($expense->status !== ExpenseStatus::Void->value && $paid === 0 && $expense->receipt_id === null && $expense->source_kind !== 'receipt' && $expense->source_kind !== 'reversal' && $d['occurred_on'] >= $expense->occurred_on, 409, __('yutiv-live_commerce::live.finance_paid_immutable'));
            $this->records->create(E::Expense, $tenant, ['category_id' => $expense->category_id, 'category_code' => $expense->category_code, 'category_name' => $expense->category_name, 'cost_group' => $expense->cost_group, 'treatment' => $expense->treatment, 'occurred_on' => $d['occurred_on'], 'currency' => $expense->currency, 'amount_original' => $expense->amount_original, 'exchange_rate' => $expense->exchange_rate, 'amount_krw' => $expense->amount_krw, 'rate_date' => $expense->rate_date, 'rate_source' => $expense->rate_source, 'source_kind' => 'reversal', 'source_key' => (string) $expense->id, 'reversal_of' => $expense->id, 'status' => ExpenseStatus::Posted->value, 'memo' => $d['reason'], 'actor_id' => $actor]);
            $expense->update(['status' => ExpenseStatus::Void->value]);
            $this->audit($tenant, $actor, 'finance.expense_void', ['expense_id' => $expense->id, 'reason' => $d['reason'], 'reversal_on' => $d['occurred_on']]);

            return ['expense_id' => $expense->id];
        });
    }

    public function recurring(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-recurring:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $category = $this->row(E::ExpenseCategory, $tenant, $d['category_id']);
            abort_unless($category->active, 409);
            $values = array_intersect_key($d, array_flip(['category_id', 'currency', 'amount_original', 'day', 'starts_on', 'ends_on', 'active', 'memo']));
            $values += ['rate_policy' => $d['currency'] === 'KRW' ? 'one' : 'manual_pending', 'actor_id' => $actor];
            if (! empty($d['id'])) {
                $rule = $this->row(E::RecurringExpense, $tenant, $d['id'], true);
                $rule->update($values);
            } else {
                $start = Date::parse($d['starts_on']);
                $first = $start->startOfMonth()->day(min((int) $d['day'], $start->daysInMonth));
                if ($first->lt($start)) {
                    $next = $start->startOfMonth()->addMonth();
                    $first = $next->day(min((int) $d['day'], $next->daysInMonth));
                }$values['next_on'] = $first->format('Y-m-d');
                $rule = $this->records->create(E::RecurringExpense, $tenant, $values);
            }

return ['rule_id' => $rule->id];
        });
    }

    public function generateRecurring(int $tenant, int $ruleId, string $through): array
    {
        return $this->records->transaction(function () use ($tenant, $ruleId, $through) {
            LiveTenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $rule = $this->row(E::RecurringExpense, $tenant, $ruleId, true);
            if (! $rule->active) {
                return ['generated' => false];
            }$month = Date::parse($rule->next_on)->startOfMonth();
            $date = $month->day(min($rule->day, $month->daysInMonth))->format('Y-m-d');
            if ($rule->ends_on && $date > $rule->ends_on) {
                $rule->update(['active' => false]);

                return ['generated' => false];
            }if ($date > $through) {
                return ['generated' => false];
            }$source = $rule->id.':'.$month->format('Y-m');
            $created = false;
            if ($date >= $rule->starts_on && ! $this->records->query(E::Expense,$tenant)->where('source_kind','recurring')->where('source_key',$source)->exists()) {
                $category = $this->row(E::ExpenseCategory,$tenant,$rule->category_id);
                if (! $category->active) {
                    return ['generated' => false];
                }$this->createExpense($tenant,$rule->actor_id,['category_id' => $rule->category_id, 'currency' => $rule->currency, 'amount_original' => (string) $rule->amount_original, 'exchange_rate' => '1', 'occurred_on' => $date, 'memo' => $rule->memo],'recurring',$source,$rule->currency === 'CNY');
                $created = true;
            }
            $next = $month->addMonth();
            $rule->update(['next_on' => $next->day(min($rule->day,$next->daysInMonth))->format('Y-m-d')]);

            return ['generated' => $created, 'period' => $source];
        });
    }
}
