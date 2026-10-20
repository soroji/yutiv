<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Plugins\Yutiv\LiveCommerce\Enums\StaffRole;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;

class LiveHeadquartersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('host')) {
            $this->merge(['host' => strtolower(trim((string) $this->input('host')))]);
        }
    }

    public function rules(): array
    {
        return match ($this->route('action')) {
            'reset-password' => ['user_id' => ['required', 'integer', 'min:1']],
            'tenant' => ['name' => ['required', 'string', 'max:100', 'regex:/^[^<>\x00-\x1F\x7F]+$/u'], 'slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{0,62}$/', Rule::unique(LiveTenant::class, 'slug')->ignore((int) $this->route('tenant'))], 'status' => ['required', Rule::in(['draft', 'active', 'suspended'])],
                'timezone' => ['required', Rule::in(timezone_identifiers_list())], 'reservation_minutes' => ['required', 'integer', 'min:1', 'max:10080'], 'return_days' => ['required', 'integer', 'min:0', 'max:365']],
            'domain' => ['host' => ['required', 'string', 'max:191', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'], 'active' => ['required', 'boolean'], 'primary' => ['required', 'boolean']],
            'account' => ['user_id' => ['nullable', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:100'], 'username' => ['required', 'regex:/^[a-z0-9][a-z0-9_.-]{2,99}$/', $this->uniqueAccount('username')], 'email' => ['nullable', 'email', 'max:191', $this->uniqueAccount('email')], 'role' => ['required', Rule::enum(StaffRole::class)]],
            'permission' => ['user_id' => ['required', 'integer'], 'role' => ['required', Rule::enum(StaffRole::class)], 'active' => ['required', 'boolean']],
            'lock' => ['user_id' => ['required', 'integer'], 'locked' => ['required', 'boolean']],
            'legacy' => ['user_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:3', 'max:1000', 'not_regex:/P[0-9]{12}/']],
            default => [],
        };
    }

    private function uniqueAccount(string $field): Unique
    {
        return Rule::unique(TeeWideUser::class, $field)
            ->where('tenant_id', (int) $this->route('tenant'))->ignore((int) $this->input('user_id', 0));
    }
}
