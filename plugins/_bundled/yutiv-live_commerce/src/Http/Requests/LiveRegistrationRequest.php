<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveRegistrationRequest extends FormRequest
{
    public function messages(): array
    {
        return ['terms.accepted' => __('yutiv-live_commerce::live.terms')];
    }

    public function authorize(): bool
    {
        return LiveContext::tenant() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => TeeWideUser::normalizeEmail($this->input('email'))]);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:191', Rule::unique(TeeWideUser::class)->where('tenant_id', LiveContext::requireTenant()->id)],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'], 'terms' => ['accepted']];
    }
}
