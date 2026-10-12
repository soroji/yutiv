<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;

class LiveAccountService extends LiveWorkflow
{
    public function issue(int $tenant, int $actor, array $data, ?int $id = null): array
    {
        return $this->records->transaction(function () use ($tenant, $actor, $data, $id) {
            LiveTenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $user = $id ? TeeWideUser::query()->where('tenant_id', $tenant)->lockForUpdate()->findOrFail($id) : null;
            $temporary = Str::password(20);
            $values = ['tenant_id' => $tenant, 'name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'] ?? $data['username'].'@accounts.invalid',
                'password' => Hash::make($temporary), 'status' => 'active', 'must_change_password' => true, 'remember_token' => null,
                'session_version' => ($user?->session_version ?? 0) + 1];
            if ($user) {
                $user->fill($values)->save();
            } else {
                $user = TeeWideUser::query()->create($values);
            }
            $staff = $this->records->query(E::Staff, $tenant)->where('user_id', $user->id)->first();
            if ($staff) {
                $staff->fill(['role' => $data['role'], 'active' => true])->save();
            } else {
                $this->records->create(E::Staff, $tenant, ['user_id' => $user->id, 'role' => $data['role'], 'active' => true]);
            }
            if ($data['role'] === 'owner') {
                LiveTenant::whereKey($tenant)->update(['owner_user_id' => $user->id]);
            }
            $this->audit($tenant, $actor, 'account.issued', ['user_id' => $user->id, 'role' => $data['role']]);

            // 평문은 이 최초 응답에서만 반환하며 DB/감사/멱등 결과에 넣지 않습니다.
            return ['user_id' => $user->id, 'username' => $user->username, 'temporary_password' => $temporary];
        });
    }

    public function permission(int $tenant, int $actor, int $id, string $role, bool $active): void
    {
        $this->records->transaction(function () use ($tenant, $actor, $id, $role, $active) {
            $tenantModel = LiveTenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $user = TeeWideUser::where('tenant_id', $tenant)->lockForUpdate()->findOrFail($id);
            $staff = $this->records->query(E::Staff, $tenant)->where('user_id', $id)->lockForUpdate()->firstOrFail();
            $staff->fill(['role' => $role, 'active' => $active])->save();
            $user->fill(['session_version' => $user->session_version + 1, 'remember_token' => null])->save();
            if ($role === 'owner' && $active) {
                $tenantModel->fill(['owner_user_id' => $id])->save();
            } elseif ($tenantModel->owner_user_id === $id) {
                $tenantModel->fill(['owner_user_id' => null])->save();
            }
            $this->audit($tenant, $actor, 'account.permission', ['user_id' => $id, 'role' => $role, 'active' => $active]);
        });
    }

    public function lock(int $tenant, int $actor, int $id, bool $locked): void
    {
        $this->records->transaction(function () use ($tenant, $actor, $id, $locked) {
            LiveTenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $user = TeeWideUser::where('tenant_id', $tenant)->lockForUpdate()->findOrFail($id);
            $user->fill(['status' => $locked ? 'suspended' : 'active', 'session_version' => $user->session_version + 1, 'remember_token' => null])->save();
            $this->audit($tenant, $actor, 'account.lock', ['user_id' => $id, 'locked' => $locked]);
        });
    }

    public function changePassword(TeeWideUser $user, string $password): void
    {
        $this->records->transaction(function () use ($user, $password) {
            LiveTenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $locked = TeeWideUser::where('tenant_id', $user->tenant_id)->lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->status === 'active', 403);
            $locked->fill(['password' => Hash::make($password), 'must_change_password' => false, 'session_version' => $locked->session_version + 1, 'remember_token' => null])->save();
            $this->audit($locked->tenant_id, $locked->id, 'account.password_changed');
            $user->refresh();
        });
    }

    public function customs(TeeWideUser $user, string $code): void
    {
        $user->customs_code = $code;
        $user->save();
        $this->audit($user->tenant_id, $user->id, 'customs.updated', ['user_id' => $user->id]);
    }

    public function revealCustoms(int $tenant, int $actor, int $userId): string
    {
        $user = TeeWideUser::where('tenant_id', $tenant)->findOrFail($userId);
        $this->audit($tenant, $actor, 'customs.viewed', ['user_id' => $userId]);

        return $user->customs_code ?? '';
    }

    public function transferLegacy(int $tenant, int $actor, int $userId, string $reason): void
    {
        $this->records->transaction(function () use ($tenant, $actor, $userId, $reason) {
            LiveTenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $user = TeeWideUser::whereNull('tenant_id')->lockForUpdate()->findOrFail($userId);
            $user->fill(['tenant_id' => $tenant, 'session_version' => $user->session_version + 1, 'remember_token' => null])->save();
            $this->audit($tenant, $actor, 'account.legacy_assigned', ['user_id' => $userId, 'reason' => $reason]);
        });
    }
}
