<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use App\Support\Query\PaginationLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        LiveContext::authorize('broadcast');

        return true;
    }

    public function rules(): array
    {
        $rules = ['edit' => ['nullable', 'integer', 'min:1'], 'broadcast_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:200'], 'channel_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'state' => ['nullable', Rule::in(['draft', 'scheduled', 'selling', 'closed', 'expired'])],
            'page' => ['nullable', 'integer', 'min:1'], 'items_page' => ['nullable', 'integer', 'min:1'], 'candidates_page' => ['nullable', 'integer', 'min:1'],
            'list' => ['nullable', 'string', 'max:2000'], 'product_search' => ['nullable', 'string', 'max:200'], 'category_id' => ['nullable', 'integer', 'min:1'], 'stock' => ['nullable', Rule::in(['yes'])]];
        $max = PaginationLimits::maxPage('live.broadcasts');
        if ($max !== null) {
            foreach (['page', 'items_page', 'candidates_page'] as $key) {
                $rules[$key][] = 'max:'.$max;
            }
        }

        return $rules;
    }
}
