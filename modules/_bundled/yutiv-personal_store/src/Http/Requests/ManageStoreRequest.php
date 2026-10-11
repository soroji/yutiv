<?php

namespace Modules\Yutiv\PersonalStore\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Yutiv\PersonalStore\Enums\ManagementAction;
use Modules\Yutiv\PersonalStore\Enums\StoreStatus;
use Modules\Yutiv\PersonalStore\Models\Store;

class ManageStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(ManagementAction::class)],
            'slug' => ['required_if:action,apply,profile', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'introduction' => ['nullable', 'string', 'max:2000'],
            'logo_url' => ['nullable', 'url:http,https', 'max:2048'],
            'banner_url' => ['nullable', 'url:http,https', 'max:2048'],
            'product_id' => ['required_if:action,products,product_rate', 'integer', Rule::exists(Product::class, 'id')],
            'excluded' => ['required_if:action,products', 'boolean'],
            'category_ids' => ['present_if:action,categories', 'array'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists(Category::class, 'id')],
            'child_id' => ['required_if:action,allocation', 'integer', Rule::exists(Store::class, 'id')],
            'rate_bps' => ['required_if:action,allocation,product_rate', 'integer', 'between:0,10000'],
            'parent_id' => ['nullable', 'integer', Rule::exists(Store::class, 'id')],
            'application_id' => ['required_if:action,approve', 'integer', 'min:1'],
            'store_id' => ['required_if:action,status', 'integer', Rule::exists(Store::class, 'id')],
            'status' => ['required_if:action,status', Rule::enum(StoreStatus::class)],
        ];
    }
}
