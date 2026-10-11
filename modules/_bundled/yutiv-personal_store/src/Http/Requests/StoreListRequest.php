<?php

namespace Modules\Yutiv\PersonalStore\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['stores_page' => ['nullable', 'integer', 'min:1'], 'history_page' => ['nullable', 'integer', 'min:1'], 'rates_page' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1'], 'children_page' => ['nullable', 'integer', 'min:1'], 'statistics_page' => ['nullable', 'integer', 'min:1'], 'ledger_page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']];
    }
}
