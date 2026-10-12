<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideAuth;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        LiveContext::authorize('brand');
        if (LiveContext::requireTenant()->slug !== $this->route('tenant')) {
            return false;
        }

        return TeeWideAuth::user()?->liveTenants()->where('slug', $this->route('tenant'))->exists() ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100', 'regex:/^[^<>\x00-\x1F\x7F]+$/u']];
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->session()->flash('brand_error', $validator->errors()->first('name'));
        throw new HttpResponseException(
            redirect()->route('teewide.account')
        );
    }
}
