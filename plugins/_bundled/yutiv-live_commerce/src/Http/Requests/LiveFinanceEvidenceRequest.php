<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveFinanceEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        LiveContext::authorize($this->isMethod('GET') ? 'finance.read' : ($this->input('source_kind') === 'transfer' ? 'finance.transfer' : 'finance.expense'));

        return true;
    }

    public function rules(): array
    {
        return $this->isMethod('GET') ? [] : ['source_kind' => ['required', Rule::in(['expense', 'transfer'])], 'source_id' => ['required', 'integer', 'min:1'], 'operation_key' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9-]+$/'], 'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120']];
    }
}
