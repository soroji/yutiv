<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\ExpenseGroup;
use Plugins\Yutiv\LiveCommerce\Enums\ExpenseTreatment;
use Plugins\Yutiv\LiveCommerce\Enums\LiveCurrency;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity;
use Plugins\Yutiv\LiveCommerce\Enums\LogisticsStage;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\LiveMoney;

class LiveFinanceActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = match ($this->route('action')) {
            'activate','settings' => 'finance.configure','close' => 'finance.close','reopen' => 'finance.reopen','category','expense','recurring','rate-confirm','expense-void','expense-link-receipt' => 'finance.expense','payment','payment-recovery','incentive-payment','incentive-recovery' => 'finance.payment','fund','fund-adjust','bank' => 'finance.accounts','transfer','withdraw','receive','cancel','cancel-confirm' => 'finance.transfer','cash-receipt','cash-recheck' => 'deposit','cash-refund' => 'refund','fifo-prepare' => 'finance.cost','logistics' => 'inventory','report' => 'finance.read',default => abort(404)
        };
        LiveContext::authorize($ability);
        if ($this->route('action') === 'report') {
            LiveContext::authorize('finance.cost');
        }

        return true;
    }

    private function amount(): array
    {
        return ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/D'];
    }

    private function rate(): array
    {
        return ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,4})(\.[0-9]{1,6})?$/D'];
    }

    public function rules(): array
    {
        $key = ['operation_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/']];
        $id = ['required', 'integer', 'min:1'];
        $date = ['required', 'date_format:Y-m-d'];
        $reason = ['required', 'string', 'max:2000'];
        $money = ['currency' => ['required', Rule::enum(LiveCurrency::class)], 'amount_original' => $this->amount(), 'exchange_rate' => $this->rate(), 'rate_date' => $date, 'rate_source' => ['required', 'string', 'max:100']];

        return $key + match ($this->route('action')) {
            'activate' => ['reason' => $reason],
            'settings' => ['aging_warning_days' => ['required', 'integer', 'between:1,3650'], 'aging_risk_days' => ['required', 'integer', 'gt:aging_warning_days', 'max:7300'], 'labor_ratio_bps' => ['required', 'integer', 'between:0,10000'], 'reason' => $reason],
            'category' => ['id' => ['nullable', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:100'], 'cost_group' => ['required', Rule::enum(ExpenseGroup::class)], 'treatment' => ['required', Rule::enum(ExpenseTreatment::class)], 'parent_id' => ['nullable', 'integer', 'min:1'], 'active' => ['required', 'boolean']],
            'expense' => $money + ['category_id' => $id, 'occurred_on' => $date, 'payment_status' => ['required', Rule::in(['paid', 'unpaid'])], 'fund_id' => ['required_if:payment_status,paid', 'nullable', 'integer', 'min:1'], 'paid_on' => ['required_if:payment_status,paid', 'nullable', 'date_format:Y-m-d'], 'memo' => ['nullable', 'string', 'max:2000'], 'counterparty' => ['nullable', 'string', 'max:200']],
            'expense-link-receipt' => ['expense_id' => $id, 'receipt_id' => $id, 'reason' => $reason],
            'payment' => ['expense_id' => ['required_without:receipt_id', 'nullable', 'integer', 'min:1'], 'receipt_id' => ['required_without:expense_id', 'nullable', 'integer', 'min:1'], 'amount_original' => $this->amount(), 'fund_id' => $id, 'paid_on' => $date, 'memo' => ['nullable', 'string', 'max:2000']],
            'payment-recovery' => ['payment_id' => $id, 'fund_id' => $id, 'amount_original' => $this->amount(), 'recovered_on' => $date, 'reason' => $reason],
            'rate-confirm' => ['expense_id' => $id, 'exchange_rate' => $this->rate(), 'rate_date' => $date, 'rate_source' => ['required', 'string', 'max:100']],
            'expense-void' => ['expense_id' => $id, 'occurred_on' => $date, 'reason' => $reason],
            'fund' => ['id' => ['nullable', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:100'], 'currency' => ['required', Rule::enum(LiveCurrency::class)], 'active' => ['required', 'boolean']],
            'fund-adjust' => ['fund_id' => $id, 'amount_original' => $this->amount(), 'exchange_rate' => $this->rate(), 'direction' => ['required', Rule::in(['in', 'out'])], 'kind' => ['required', Rule::in(['opening', 'adjustment'])], 'occurred_on' => $date, 'reason' => $reason],
            'bank' => ['id' => ['nullable', 'integer', 'min:1'], 'bank' => ['required', 'string', 'max:100'], 'account_number' => ['required_without:id', 'nullable', 'string', 'max:100'], 'holder' => ['required', 'string', 'max:100'], 'active' => ['required', 'boolean'], 'default' => ['required', 'boolean'], 'customer_visible' => ['required', 'boolean'], 'fund_id' => ['nullable', 'integer', 'min:1']],
            'transfer' => ['from_fund_id' => $id, 'to_fund_id' => ['required', 'integer', 'min:1', 'different:from_fund_id'], 'amount_out' => $this->amount(), 'amount_in' => $this->amount(), 'exchange_rate' => $this->rate(), 'fee_original' => $this->amount(), 'fee_currency' => ['required', Rule::enum(LiveCurrency::class)], 'fee_fund_id' => ['nullable', 'integer', 'min:1'], 'requested_on' => $date, 'reference' => ['nullable', 'string', 'max:200'], 'memo' => ['nullable', 'string', 'max:2000']],
            'withdraw','receive','cancel','cancel-confirm' => ['id' => $id, 'occurred_on' => $date, 'amount_original' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/D'], 'exchange_rate' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,4})(\.[0-9]{1,6})?$/D'], 'reason' => $reason],
            'cash-receipt','cash-refund' => ['order_id' => $id, 'fund_id' => $id, 'bank_account_id' => ['nullable', 'integer', 'min:1'], 'amount' => ['required', 'integer', 'between:1,1000000000000'], 'occurred_on' => $date, 'reason' => $reason],
            'cash-recheck' => ['order_id' => $id, 'reason' => $reason],
            'incentive-payment','incentive-recovery' => ['line_id' => $id, 'beneficiary' => ['required', 'regex:/^(channel|host):[0-9]+$/'], 'amount' => ['required', 'integer', 'between:1,1000000000000'], 'fund_id' => $id, 'occurred_on' => $date, 'reason' => $reason],
            'recurring' => ['id' => ['nullable', 'integer', 'min:1'], 'category_id' => $id, 'currency' => ['required', Rule::enum(LiveCurrency::class)], 'amount_original' => $this->amount(), 'day' => ['required', 'integer', 'between:1,31'], 'starts_on' => $date, 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'active' => ['required', 'boolean'], 'memo' => ['nullable', 'string', 'max:2000']],
            'fifo-prepare' => ['option_id' => $id],
            'logistics' => ['unit' => ['required', 'uuid'], 'logistics_stage' => ['required', Rule::enum(LogisticsStage::class)], 'reason' => $reason],
            'report' => ['from' => $date, 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'period' => ['required', Rule::in(['day', 'week', 'month', 'custom'])]],
            'close' => ['report_uuid' => ['required', 'uuid'], 'reason' => $reason, 'acknowledge_incomplete' => ['required', 'boolean']],
            'reopen' => ['id' => $id, 'reason' => $reason],
            default => []
        };
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }$action = $this->route('action');
            $actionRules = $this->rules();
            try {
                if ($action === 'expense') {
                    LiveMoney::convert($this->input('amount_original'), LiveCurrency::from($this->input('currency')), $this->input('exchange_rate'));
                }
                $this->validateNativeAmounts($action);
                foreach (['amount_original', 'amount_out', 'amount_in', 'fee_original'] as $field) {
                    if (isset($actionRules[$field]) && $this->filled($field)) {
                        LiveMoney::minor($this->input($field));
                    }
                }if (isset($actionRules['exchange_rate']) && $this->filled('exchange_rate') && LiveMoney::minor($this->input('exchange_rate'), 6) <= 0) {
                    $validator->errors()->add('exchange_rate', __('yutiv-live_commerce::live.money_invalid'));
                }
            } catch (\DomainException $error) {
                $validator->errors()->add('amount_original', $error->getMessage());
            }
            if ($action === 'transfer' && $validator->errors()->isEmpty() && LiveMoney::minor($this->input('fee_original')) > 0 && ! $this->filled('fee_fund_id')) {
                $validator->errors()->add('fee_fund_id', __('yutiv-live_commerce::live.finance_fee_fund_required'));
            }
            if (in_array($action, ['payment', 'payment-recovery', 'fund-adjust', 'withdraw', 'receive', 'cancel-confirm', 'cash-receipt', 'cash-refund', 'incentive-payment', 'incentive-recovery'], true)) {
                foreach (['paid_on', 'recovered_on', 'occurred_on'] as $field) {
                    if (isset($actionRules[$field]) && $this->filled($field) && $this->input($field) > now(LiveContext::requireTenant()->timezone)->format('Y-m-d')) {
                        $validator->errors()->add($field, __('yutiv-live_commerce::live.finance_future_date'));
                    }
                }
            }
            if (in_array($action, ['report'], true) && CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) > 365) {
                $validator->errors()->add('to', __('yutiv-live_commerce::live.ledger_limit'));
            }
        }];
    }

    private function validateNativeAmounts(string $action): void
    {
        $records = app(LiveRepositoryInterface::class);
        $tenant = LiveContext::requireTenant()->id;
        $entity = LiveEntity::class;
        $fund = function (int $id) use ($records, $tenant, $entity) {
            return $records->query($entity::Fund, $tenant)->find($id);
        };
        $convert = function (string $amount, $account, string $rate) {
            if ($account) {
                LiveMoney::convert($amount, LiveCurrency::from($account->currency), $account->currency === 'KRW' ? '1' : $rate);
            }
        };
        if ($action === 'recurring') {
            LiveMoney::convert($this->input('amount_original'), LiveCurrency::from($this->input('currency')), '1');
            if (LiveMoney::minor($this->input('amount_original')) === 0) {
                throw new \DomainException(__('yutiv-live_commerce::live.money_invalid'));
            }
        }
        if ($action === 'fund-adjust') {
            $account = $fund($this->integer('fund_id'));
            if ($account) {
                LiveMoney::convert($this->input('amount_original'), LiveCurrency::from($account->currency), $this->input('exchange_rate'));
            }
        }
        if (in_array($action, ['payment', 'payment-recovery'], true)) {
            $convert($this->input('amount_original'), $fund($this->integer('fund_id')), '1');
        }
        if ($action === 'transfer') {
            $convert($this->input('amount_out'), $fund($this->integer('from_fund_id')), $this->input('exchange_rate'));
            $convert($this->input('amount_in'), $fund($this->integer('to_fund_id')), $this->input('exchange_rate'));
            LiveMoney::convert($this->input('fee_original'), LiveCurrency::from($this->input('fee_currency')), $this->input('fee_currency') === 'KRW' ? '1' : $this->input('exchange_rate'));
        }
        if ($action === 'rate-confirm') {
            $expense = $records->query($entity::Expense, $tenant)->find($this->integer('expense_id'));
            if ($expense) {
                LiveMoney::convert((string) $expense->amount_original, LiveCurrency::from($expense->currency), $this->input('exchange_rate'));
            }
        }
        if (in_array($action, ['receive', 'cancel-confirm'], true) && $this->filled('amount_original')) {
            $transfer = $records->query($entity::MoneyTransfer, $tenant)->find($this->integer('id'));
            if ($transfer) {
                $convert($this->input('amount_original'), $fund($action === 'receive' ? $transfer->to_fund_id : $transfer->from_fund_id), $this->input('exchange_rate') ?? (string) $transfer->exchange_rate);
            }
        }
    }
}
