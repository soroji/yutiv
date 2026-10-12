<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveLoginRequest extends FormRequest
{
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
        return ['email' => ['required', 'string', 'max:191'], 'password' => ['required', 'string', 'max:128'], 'remember' => ['sometimes', 'boolean']];
    }
}
