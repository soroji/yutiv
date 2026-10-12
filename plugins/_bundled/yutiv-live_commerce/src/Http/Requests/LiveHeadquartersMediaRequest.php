<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LiveHeadquartersMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000']];
    }
}
