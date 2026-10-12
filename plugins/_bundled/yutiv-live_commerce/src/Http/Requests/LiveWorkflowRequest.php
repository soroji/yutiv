<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Rules\ApprovedExternalUrl;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = match ($this->route('action')) {
            'scan' => $this->filled('order_id') ? 'shipping' : 'inventory', 'deposit', 'resolve-deposit' => 'deposit', 'refund' => 'refund', 'incentive', 'recovery', 'attribution' => 'settlement', 'bank-notice' => 'banks', 'ship', 'delivered' => 'shipping',
            'as-receive', 'as-dispatch', 'receive', 'transition', 'stock-correction', 'return', 'return-review' => 'inventory', 'label-print' => 'labels', 'brand' => 'brand', 'staff', 'staff-permission' => 'staff', 'customs' => 'customs', default => abort(404)
        };
        LiveContext::authorize($ability);

        return true;
    }

    public function rules(): array
    {
        $key = ['operation_key' => ['required', 'string', 'max:100']];
        $reason = ['reason' => ['required', 'string', 'max:1000', 'not_regex:/P[0-9]{12}/']];

        return match ($this->route('action')) {
            'receive' => $key + ['option_id' => ['required', 'integer', 'min:1'], 'quantity' => ['required', 'integer', 'min:1', 'max:200'], 'location' => ['required', 'string', 'max:100']],
            'label-print' => $key + ['qrs' => ['required', 'array', 'min:1', 'max:200'], 'qrs.*' => ['required', 'uuid', 'distinct'], 'width' => ['required', 'integer', 'min:30', 'max:100'], 'height' => ['required', 'integer', 'min:20', 'max:100']],
            'as-receive', 'as-dispatch' => $key + $reason + ['qr' => ['required', 'uuid'], 'location' => ['required', 'string', 'max:100']],
            'stock-correction' => $key + $reason + ['qr' => ['required', 'uuid'], 'movement_id' => ['required', 'integer', 'min:1']],
            'transition' => $key + $reason + ['qr' => ['required', 'uuid'], 'state' => ['required', Rule::in(['available', 'as', 'defective', 'lost', 'disposed'])], 'location' => ['required', 'string', 'max:100']],
            'ship' => $key + ['order_id' => ['required', 'integer', 'min:1'], 'qrs' => ['required', 'array', 'min:1', 'max:200'], 'qrs.*' => ['required', 'uuid', 'distinct']],
            'scan' => ['order_id' => ['nullable', 'integer', 'min:1'], 'qrs' => ['required', 'array', 'min:1', 'max:200'], 'qrs.*' => ['required', 'regex:/^(P:)?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', 'distinct']],
            'deposit', 'resolve-deposit' => $key + $reason + ['order_id' => ['required', 'integer', 'min:1'], 'amount' => ['required', 'integer', 'min:0', 'max:1000000000000']],
            'delivered' => $key + ['order_id' => ['required', 'integer', 'min:1']],
            'return' => $key + $reason + ['line_id' => ['required', 'integer', 'min:1'], 'qrs' => ['required', 'array', 'min:1', 'max:200'], 'qrs.*' => ['required', 'uuid', 'distinct']],
            'return-review' => $key + $reason + ['line_id' => ['required', 'integer', 'min:1'], 'resolved' => ['required', 'accepted']],
            'refund' => $key + $reason + ['order_id' => ['required', 'integer', 'min:1'], 'amount' => ['required', 'integer', 'min:1', 'max:1000000000000']],
            'incentive' => $key + ['line_id' => ['required', 'integer', 'min:1'], 'beneficiary' => ['required', 'regex:/^(channel|host):[0-9]+$/'], 'amount' => ['required', 'integer', 'min:1', 'max:1000000000000']],
            'recovery' => $key + $reason + ['line_id' => ['required', 'integer', 'min:1'], 'beneficiary' => ['required', 'regex:/^(channel|host):[0-9]+$/'], 'amount' => ['required', 'integer', 'min:1', 'max:1000000000000']],
            'attribution' => $key + $reason + ['line_id' => ['required', 'integer', 'min:1'], 'channel_id' => ['required', 'integer', 'min:1'], 'showhost_id' => ['nullable', 'integer', 'min:1']],
            'bank-notice' => $key + $reason + ['order_id' => ['required', 'integer', 'min:1'], 'bank_id' => ['required', 'integer', 'min:1']],
            'customs' => ['user_id' => ['required', 'integer', 'min:1']],
            'brand' => ['name' => ['required', 'string', 'max:100', 'not_regex:/[<>]/'], 'kakao_url' => ['nullable', new ApprovedExternalUrl(['open.kakao.com', 'pf.kakao.com'])], 'telegram_url' => ['nullable', new ApprovedExternalUrl(['t.me'])]],
            'staff' => ['name' => ['required', 'string', 'max:100'], 'username' => ['required', 'regex:/^[a-z0-9][a-z0-9_.-]{2,99}$/', Rule::unique(TeeWideUser::class, 'username')->where('tenant_id', LiveContext::requireTenant()->id)], 'role' => ['required', Rule::in(['sales', 'warehouse', 'finance', 'customs'])]],
            'staff-permission' => ['user_id' => ['required', 'integer', 'min:1'], 'role' => ['required', Rule::in(['sales', 'warehouse', 'finance', 'customs'])], 'active' => ['required', 'boolean']],
            default => [],
        };
    }

    protected function prepareForValidation(): void
    {
        $input = [];
        if ($this->has('qr')) {
            $input['qr'] = preg_replace('/^U:/', '', trim((string) $this->input('qr')));
        }
        if ($this->has('qrs') && is_array($this->input('qrs'))) {
            $input['qrs'] = array_map(fn ($q) => is_string($q) ? preg_replace('/^U:/', '', trim($q)) : $q, $this->input('qrs'));
        }
        $this->merge($input);
    }
}
