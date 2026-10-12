<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        LiveContext::authorize($this->route('kind') === 'logo' ? 'brand' : 'catalog');

        return true;
    }

    public function rules(): array
    {
        return ['image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'], 'product_id' => [$this->route('kind') === 'product' ? 'required' : 'nullable', 'integer', 'min:1']];
    }
}
