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
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.option_id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'items.*.name' => ['required', 'string', 'max:200', 'distinct:ignore_case'],
            'items.*.price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'items.*.discount' => ['required', 'integer', 'min:0', 'lte:items.*.price'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:200'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $total = array_sum(array_map(fn ($item) => is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : 0, (array) $this->input('items', [])));
            if ($total < 1 || $total > 200) {
                $validator->errors()->add('items', __('yutiv-live_commerce::live.receipt_quantity'));
            }
        }];
    }
}
