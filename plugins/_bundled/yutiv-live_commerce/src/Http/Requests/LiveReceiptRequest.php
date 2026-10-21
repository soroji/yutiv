<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveReceiptRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('items'))) {
            $this->merge(['items' => array_map(function ($item) {
                if (is_array($item) && is_string($item['name'] ?? null)) {
                    $item['name'] = trim($item['name']);
                }

                return $item;
            }, $this->input('items'))]);
        }
    }

    public function authorize(): bool
    {
        LiveContext::authorize('inventory');
        if ($this->input('mode') !== 'existing') {
            LiveContext::authorize('catalog');
        }
        foreach ((array) $this->input('items', []) as $item) {
            if (is_array($item) && empty($item['option_id'])) {
                LiveContext::authorize('catalog');
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9-]+$/'],
            'mode' => ['required', Rule::in(['new', 'copy', 'existing'])],
            'product_id' => ['required_unless:mode,new', 'nullable', 'integer', 'min:1'],
            'name' => ['exclude_if:mode,existing', 'required', 'string', 'max:200'],
            'code' => ['exclude_if:mode,existing', 'required', 'string', 'max:100'],
            'description' => ['exclude_if:mode,existing', 'nullable', 'string', 'max:10000'],
            'published' => ['exclude_if:mode,existing', 'required', 'boolean'],
            'sellable' => ['exclude_if:mode,existing', 'required', 'boolean'],
            'location' => ['required', 'string', 'max:100'], 'memo' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', 'min:1'], 'list' => ['nullable', 'string', 'max:4000'],
            'axes' => ['nullable', 'array', 'max:4'], 'axes.*.name' => ['required', 'string', 'max:30', 'distinct:ignore_case'],
            'axes.*.values' => ['required', 'array', 'min:1', 'max:20'], 'axes.*.values.*' => ['required', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.option_id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'items.*.name' => ['required', 'string', 'max:200', 'distinct:ignore_case'],
            'items.*.price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'items.*.attributes' => ['nullable', 'array', 'max:4'], 'items.*.attributes.*' => ['required', 'string', 'max:40'],
            'items.*.unit_cost' => $this->input('currency','KRW')==='CNY'?['required','string','regex:/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,2})?$/D']:['required','integer','min:0','max:1000000000'],
            'currency'=>['nullable',Rule::in(['KRW','CNY'])],'exchange_rate'=>['required_if:currency,CNY','nullable','string','regex:/^(0|[1-9][0-9]{0,4})(\.[0-9]{1,6})?$/D'],'rate_date'=>['required_if:currency,CNY','nullable','date_format:Y-m-d'],'rate_source'=>['required_if:currency,CNY','nullable','string','max:100'],
            'items.*.discount' => ['required', 'integer', 'min:0', 'lte:items.*.price'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:200'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {foreach($this->input('items',[]) as $item){if(($item['quantity']??0)>0){$minor=\Plugins\Yutiv\LiveCommerce\Support\LiveMoney::minor((string)$item['unit_cost'])*(int)$item['quantity'];\Plugins\Yutiv\LiveCommerce\Support\LiveMoney::convert(\Plugins\Yutiv\LiveCommerce\Support\LiveMoney::decimal($minor),\Plugins\Yutiv\LiveCommerce\Enums\LiveCurrency::from($this->input('currency')??'KRW'),$this->input('currency','KRW')==='CNY'?$this->input('exchange_rate'):'1');}}} catch(\DomainException $error){$validator->errors()->add('items',$error->getMessage());return;}
            $combinations = 1;
            $axes = [];
            foreach ((array) $this->input('axes', []) as $axis) {
                $axes[$axis['name']] = $axis['values'];
                $values = is_array($axis['values'] ?? null) ? $axis['values'] : [];
                if (count(array_unique(array_map(fn ($v) => is_string($v) ? mb_strtolower(trim($v)) : '', $values))) !== count($values)) {
                    $validator->errors()->add('axes', __('yutiv-live_commerce::live.option_axes'));
                }
                $combinations *= count($values);
            }
            if ($combinations > 50) {
                $validator->errors()->add('axes', __('yutiv-live_commerce::live.option_axes'));
            }
            if ($axes) {
                $seen = [];
                foreach ($this->input('items', []) as $index => $item) {
                    $attributes = $item['attributes'] ?? [];
                    if (count($attributes) !== count($axes) || array_diff(array_keys($attributes), array_keys($axes))) {
                        $validator->errors()->add('items.'.$index.'.attributes', __('yutiv-live_commerce::live.option_axes'));

                        continue;
                    }
                    foreach ($attributes as $name => $value) {
                        if (! in_array($value, $axes[$name], true)) {
                            $validator->errors()->add('items.'.$index.'.attributes', __('yutiv-live_commerce::live.option_axes'));
                        }
                    }
                    ksort($attributes);
                    $identity = json_encode($attributes, JSON_THROW_ON_ERROR);
                    if (isset($seen[$identity])) {
                        $validator->errors()->add('items.'.$index.'.attributes', __('yutiv-live_commerce::live.option_axes'));
                    }
                    $seen[$identity] = true;
                }
            }
            $total = array_sum(array_map(fn ($item) => is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : 0, (array) $this->input('items', [])));
            if ($total < 1 || $total > 200) {
                $validator->errors()->add('items', __('yutiv-live_commerce::live.receipt_quantity'));
            }
        }];
    }
}
