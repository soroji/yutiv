<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Helpers\PermissionHelper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\CatalogTranslationKind;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;

class CatalogTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! in_array($this->input('kind'), ['product', 'category'], true)) {
            return false;
        }
        $resource = $this->input('kind') === 'product' ? 'products' : 'categories';

        return PermissionHelper::check('sirsoft-ecommerce.'.$resource.'.'.($this->input('entity_id') ? 'update' : 'create'), $this->user());
    }

    public function rules(): array
    {
        $fields = ['name', 'description', 'meta_title', 'meta_description'];
        if ($this->input('kind') === 'product') {
            $fields = [...$fields, 'meta_keywords', 'option_group_name', 'option_value', 'option_name', 'additional_option_name', 'additional_option_value'];
        }

        return [
            'request_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::enum(CatalogTranslationKind::class)],
            'entity_id' => ['nullable', 'integer', Rule::exists($this->input('kind') === 'category' ? Category::class : Product::class, 'id')],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['array:id,field,source,html,locale,current,overwrite'],
            'items.*.id' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'items.*.field' => ['required', Rule::in($fields)],
            'items.*.source' => ['present', 'nullable', 'string', 'max:65535'],
            'items.*.html' => ['required', 'boolean'],
            'items.*.locale' => ['required', Rule::in(['en', 'ja', 'zh-CN'])],
            'items.*.current' => ['present', 'nullable', 'string', 'max:65535'],
            'items.*.overwrite' => ['required', 'boolean'],
            'terms' => ['present', 'array', 'max:100'],
            'terms.*' => ['string', 'min:1', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (strlen($this->getContent()) > 1048576 || strlen(json_encode($this->input('items', []), JSON_UNESCAPED_UNICODE)) > 524288) {
                $validator->errors()->add('items', __('sirsoft-ecommerce::translation.too_large'));
            }
            foreach ($this->input('items', []) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (($item['html'] ?? false) && ($item['field'] ?? '') !== 'description') {
                    $validator->errors()->add('items.'.$index.'.html', __('sirsoft-ecommerce::translation.invalid_response'));
                }
            }
        });
    }
}
