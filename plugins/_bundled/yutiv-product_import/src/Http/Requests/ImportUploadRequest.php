<?php

namespace Plugins\Yutiv\ProductImport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Yutiv\ProductImport\Support\ImportAccess;

class ImportUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ImportAccess::allowed($this->user());
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:10240', 'extensions:xlsx']];
    }
}
