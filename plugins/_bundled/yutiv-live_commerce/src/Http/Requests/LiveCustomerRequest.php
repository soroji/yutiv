<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (in_array($this->route('action'), ['forgot', 'reset', 'cart'], true)) {
            return LiveContext::tenant() !== null;
        }
        LiveContext::user();

        return true;
    }

    public function rules(): array
    {
        return match ($this->route('action')) {
            'forgot' => ['email' => ['required', 'email', 'max:191']],
            'reset' => ['token' => ['required', 'regex:/^[a-f0-9]{64}$/'], 'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed']],
            'password' => ['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed']],
            'profile' => ['name' => ['required', 'string', 'max:100'], 'customs_code' => ['nullable', 'regex:/^P[0-9]{12}$/']],
            'address' => ['name' => ['required', 'string', 'max:100'], 'phone' => ['required', 'string', 'max:30'], 'address' => ['required', 'string', 'max:500'], 'postal_code' => ['required', 'string', 'max:20']],
            'cart' => ['broadcast_id' => ['required', 'integer', 'min:1'], 'option_id' => ['required', 'integer', 'min:1'], 'quantity' => ['required', 'integer', 'min:1', 'max:200'],
                'code' => ['nullable', 'regex:/^[a-f0-9]{48}$/'], 'operation_key' => ['required', 'string', 'max:100']],
            'order' => ['lines' => ['required', 'array', 'min:1', 'max:50'], 'lines.*' => ['required', 'uuid', 'distinct'], 'address_id' => ['required', 'integer', 'min:1'], 'operation_key' => ['required', 'string', 'max:100']],
            'cancel' => ['line_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1', 'max:200'], 'reason' => ['required', 'string', 'max:1000', 'not_regex:/P[0-9]{12}/'], 'operation_key' => ['required', 'string', 'max:100']],
            'return' => ['operation_key' => ['required', 'string', 'max:100'], 'line_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:1000', 'not_regex:/P[0-9]{12}/']],
            'confirm' => ['operation_key' => ['required', 'string', 'max:100']],
            default => [],
        };
    }
}
