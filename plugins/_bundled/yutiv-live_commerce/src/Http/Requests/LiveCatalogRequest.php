<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Enums\BroadcastState;
use Plugins\Yutiv\LiveCommerce\Rules\ApprovedExternalUrl;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\LiveForms;

class LiveCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        LiveContext::authorize(LiveForms::ability($this->route('entity')));

        return true;
    }

    public function rules(): array
    {
        $rules = [];
        foreach (LiveForms::fields($this->route('entity')) as $name => $field) {
            $rules[$name] = [$field['required'] ? 'required' : 'nullable'];
            $rules[$name][] = match ($field['type']) {
                'number', 'select' => 'integer', 'checkbox' => 'boolean', 'datetime-local' => 'date', 'hosts' => 'array', default => 'string'
            };
            if (in_array($field['type'], ['number', 'select'], true)) {
                $rules[$name][] = 'min:0';
                $rules[$name][] = 'max:1000000000';
            }
            if ($field['type'] === 'choice') {
                $rules[$name][] = Rule::in(array_keys($field['choices']));
            }
            if (str_ends_with($name, '_percent')) {
                $rules[$name][] = 'regex:/^(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/';
            }
            if ($field['type'] === 'url') {
                $rules[$name][] = new ApprovedExternalUrl(['youtube.com', 'www.youtube.com', 'youtu.be']);
            }
            if (in_array($field['type'], ['text', 'textarea', 'url'], true)) {
                $rules[$name][] = 'max:'.($field['type'] === 'textarea' ? 10000 : ($field['type'] === 'url' ? 500 : 200));
            }
        }
        if ($this->route('entity') === 'products') {
            $rules['code'][] = Rule::unique('live_products', 'code')->where('tenant_id', LiveContext::requireTenant()->id)->ignore((int) $this->route('record'));
        }
        if ($this->route('entity') === 'broadcasts') {
            $rules['state'] = ['required', Rule::enum(BroadcastState::class)];
            $rules['ends_at'][] = 'after:starts_at';
            $rules['sale_ends_at'][] = 'after:sale_starts_at';
            $rules['host_shares.*'] = ['required', 'string', 'regex:/^(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/'];
        }

        return $rules;
    }
}
