<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveProductActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        LiveContext::authorize(in_array($this->route('action'), ['print', 'preview'], true) ? 'labels' : 'catalog');

        return true;
    }

    public function rules(): array
    {
        return match ($this->route('action')) {
            'category' => ['id' => ['nullable', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:100', Rule::unique('live_categories', 'name')->where('tenant_id', LiveContext::requireTenant()->id)->ignore($this->integer('id'))], 'active' => ['required', 'boolean']],
            'product' => ['id' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:200'], 'code' => ['required', 'string', 'max:100', Rule::unique('live_products', 'code')->where('tenant_id', LiveContext::requireTenant()->id)->ignore($this->integer('id'))], 'description' => ['nullable', 'string', 'max:10000'], 'category_id' => ['nullable', 'integer', 'min:1'], 'published' => ['required', 'boolean'], 'sellable' => ['required', 'boolean']],
            'print','preview' => ['operation_key' => [$this->route('action') === 'print' ? 'required' : 'nullable', 'string', 'max:64', 'regex:/^[a-zA-Z0-9-]+$/'], 'qrs' => ['required', 'array', 'min:1', 'max:200'], 'qrs.*' => ['required', 'uuid', 'distinct'], 'width' => ['required', 'integer', 'between:30,100'], 'height' => ['required', 'integer', 'between:20,100'], 'copies' => ['required', 'integer', 'between:1,10'], 'original_id' => ['nullable', 'integer', 'min:1'], 'legacy_audit_id' => ['nullable', 'integer', 'min:1']],
            default => []
        };
    }

    public function after(): array
    {
        return [function ($v) {
            if (in_array($this->route('action'), ['print', 'preview'], true) && count((array) $this->input('qrs', [])) * (int) $this->input('copies', 1) > 200) {
                $v->errors()->add('copies', __('yutiv-live_commerce::live.print_limit'));
            }
        }];
    }
}
