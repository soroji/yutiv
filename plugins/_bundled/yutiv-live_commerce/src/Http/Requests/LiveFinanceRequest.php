<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveFinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        LiveContext::authorize($this->route('section') === 'sales' ? 'statistics' : 'finance.read');
        if (in_array($this->route('section') ?? 'overview', ['overview', 'assets', 'reports'], true) || $this->route('uuid')) {
            LiveContext::authorize('finance.cost');
        }

        return true;
    }

    public function rules(): array
    {
        return ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])], 'period' => ['nullable', Rule::in(['day', 'week', 'month', 'custom'])], 'search' => ['nullable', 'string', 'max:200'], 'fund_id'=>['nullable','integer','min:1'],'product_id' => ['nullable', 'integer', 'min:1'], 'order_id' => ['nullable', 'integer', 'min:1'], 'location' => ['nullable', 'string', 'max:100'], 'aging_bucket' => ['nullable', Rule::in(['normal', 'warning', 'risk'])], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', Rule::in([20, 50, 100])], 'part' => ['nullable', 'integer', 'min:1'], 'orientation' => ['nullable', Rule::in(['portrait', 'landscape'])]];
    }

    public function after(): array
    {
        return [function ($v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }if ($this->filled('from') && $this->filled('to') && CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) > 365) {
                $v->errors()->add('to', __('yutiv-live_commerce::live.ledger_limit'));
            }
        }];
    }
}
