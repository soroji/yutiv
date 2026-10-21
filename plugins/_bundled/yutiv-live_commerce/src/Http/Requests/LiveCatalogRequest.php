<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Enums\BroadcastState;
use Plugins\Yutiv\LiveCommerce\Rules\ApprovedExternalUrl;
use Plugins\Yutiv\LiveCommerce\Services\IncentiveCalculator;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\LiveForms;

class LiveCatalogRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->route('entity') !== 'broadcasts' || ! $this->boolean('broadcast_workspace')) {
            return;
        }
        $data = [];
        if (! $this->boolean('separate_sales')) {
            $data['sale_starts_at'] = $this->input('starts_at');
            $data['sale_ends_at'] = $this->input('ends_at');
        }
        if (! $this->boolean('channel_payment')) {
            $data['channel_percent'] = '0';
        }
        if (! $this->input('host_shares')) {
            $data['host_percent'] = '0';
        }
        if (is_array($this->input('host_shares')) && count($this->input('host_shares')) === 1) {
            $data['host_shares'] = array_fill_keys(array_keys($this->input('host_shares')), '100');
        }
        $this->merge($data);
    }

    public function authorize(): bool
    {
        LiveContext::authorize(LiveForms::ability($this->route('entity')));

        return true;
    }

    public function rules(): array
    {
        $rules = [];
        foreach (LiveForms::fields($this->route('entity')) as $name => $field) {
            $rules[$name] = [$field['required'] ? 'required' : 'nullable'];
            $rules[$name][] = match ($field['type']) {
                'number', 'select' => 'integer', 'checkbox' => 'boolean', 'datetime-local' => 'date', 'hosts' => 'array', default => 'string'
            };
            if (in_array($field['type'], ['number', 'select'], true)) {
                $rules[$name][] = 'min:0';
                $rules[$name][] = 'max:1000000000';
            }
            if ($field['type'] === 'choice') {
                $rules[$name][] = Rule::in(array_keys($field['choices']));
            }
            if (str_ends_with($name, '_percent')) {
                $rules[$name][] = 'regex:/^(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/';
            }
            if ($field['type'] === 'url') {
                $rules[$name][] = new ApprovedExternalUrl(['youtube.com', 'www.youtube.com', 'youtu.be']);
            }
            if (in_array($field['type'], ['text', 'textarea', 'url'], true)) {
                $rules[$name][] = 'max:'.($field['type'] === 'textarea' ? 10000 : ($field['type'] === 'url' ? 500 : 200));
            }
        }
        if ($this->route('entity') === 'products') {
            $rules['code'][] = Rule::unique('live_products', 'code')->where('tenant_id', LiveContext::requireTenant()->id)->ignore((int) $this->route('record'));
        }
        if ($this->route('entity') === 'broadcasts') {
            $rules['state'] = ['required', Rule::enum(BroadcastState::class)];
            $rules['ends_at'][] = 'after:starts_at';
            $rules['sale_ends_at'][] = 'after:sale_starts_at';
            $rules['host_shares.*'] = ['required', 'string', 'regex:/^(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/'];
            $rules['host_shares'][] = 'max:100';
        }

        if (in_array($this->route('entity'), ['broadcasts', 'broadcast-items'], true)) {
            $rules['broadcast_workspace'] = ['nullable', 'boolean'];
            $rules['list'] = ['nullable', 'string', 'max:2000'];
            $rules['detail_query'] = ['nullable', 'string', 'max:2000'];
        }
        if ($this->route('entity') === 'broadcasts') {
            $rules['channel_payment'] = ['nullable', 'boolean'];
            $rules['separate_sales'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty() || $this->route('entity') !== 'broadcasts') {
                return;
            }
            $shares = $this->input('host_shares') ?? [];
            foreach (array_keys($shares) as $id) {
                if (! ctype_digit((string) $id) || (string) (int) $id !== (string) $id || (int) $id < 1) {
                    $validator->errors()->add('host_shares', __('yutiv-live_commerce::live.broadcast_policy'));
                }
            }
            $rates = array_map(fn ($v) => IncentiveCalculator::rate($v), $shares);
            $host = IncentiveCalculator::rate($this->input('host_percent', '0'));
            $channel = IncentiveCalculator::rate($this->input('channel_percent', '0'));
            if ($channel + $host > 10000 || ($host > 0 && array_sum($rates) !== 10000) || ($this->boolean('broadcast_workspace') && count($rates) > 1 && array_sum($rates) !== 10000)) {
                $validator->errors()->add('host_shares', __('yutiv-live_commerce::live.broadcast_policy'));
            }
        }];
    }
}
