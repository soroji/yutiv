<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveOfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return LiveContext::tenant() !== null;
    }

    public function rules(): array
    {
        return ['batch' => ['nullable', 'integer', 'min:1'], 'actor_id' => ['nullable', 'integer', 'min:1'], 'location' => ['nullable', 'string', 'max:100'], 'search' => ['nullable', 'string', 'max:200'], 'edit' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'], 'part' => ['nullable', 'integer', 'min:1'],
            'basis' => ['nullable', Rule::in(['created_at', 'paid_at'])], 'period' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'product_id' => ['nullable', 'integer', 'min:1'], 'broadcast_id' => ['nullable', 'integer', 'min:1'], 'channel_id' => ['nullable', 'integer', 'min:1'], 'showhost_id' => ['nullable', 'integer', 'min:1'], 'option_id' => ['nullable', 'integer', 'min:1'],
            'receipt' => ['nullable', 'uuid'], 'unit' => ['nullable', 'uuid'], 'width' => ['nullable', 'integer', 'min:30', 'max:100'], 'height' => ['nullable', 'integer', 'min:20', 'max:100']];
    }
}
