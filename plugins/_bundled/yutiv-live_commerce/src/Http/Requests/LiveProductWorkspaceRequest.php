<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Enums\StockState;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveProductWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return LiveContext::tenant() !== null;
    }

    public function rules(): array
    {
        return ['legacy_audit_id' => ['nullable', 'integer', 'min:1'], 'edit' => ['nullable', 'integer', 'min:1'], 'copy' => ['nullable', 'boolean'], 'unit' => ['nullable', 'uuid'], 'search' => ['nullable', 'string', 'max:200'], 'category_id' => ['nullable', 'integer', 'min:0'], 'date_basis' => ['nullable', Rule::in(['created', 'received'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])], 'stock' => ['nullable', Rule::in(['yes', 'no'])],
            'unit_state' => ['nullable', Rule::enum(StockState::class)], 'sort' => ['nullable', Rule::in(['newest', 'oldest', 'name'])], 'per_page' => ['nullable', Rule::in([20, 50, 100])], 'page' => ['nullable', 'integer', 'min:1'],
            'list' => ['nullable', 'string', 'max:4000'], 'completed' => ['nullable', 'integer', 'min:1'], 'products' => ['nullable', 'array', 'max:100'], 'products.*' => ['integer', 'min:1', 'distinct'],
            'product_id' => ['nullable', 'integer', 'min:1'], 'batch' => ['nullable', 'integer', 'min:1'], 'original_id' => ['nullable', 'integer', 'min:1'], 'width' => ['nullable', 'integer', 'between:30,100'], 'height' => ['nullable', 'integer', 'between:20,100'], 'copies' => ['nullable', 'integer', 'between:1,10']];
    }
}
