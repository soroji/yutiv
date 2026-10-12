<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;

class LiveHeadquartersService extends LiveWorkflow
{
    public function overview(?int $tenant = null): array
    {
        $tenants = LiveTenant::query()->orderBy('name')->get(['id', 'uuid', 'slug', 'name', 'status', 'timezone', 'reservation_minutes', 'return_days', 'owner_user_id']);
        $selected = $tenant ? LiveTenant::findOrFail($tenant) : null;
        $data = ['tenants' => $tenants, 'selected' => $selected, 'legacy_users' => TeeWideUser::whereNull('tenant_id')->orderBy('id')->limit(100)->get(['id', 'name', 'email'])];
        if ($selected) {
            $data['domains'] = $this->records->query(E::Domain, $tenant)->orderBy('host')->get();
            $data['accounts'] = TeeWideUser::where('tenant_id', $tenant)->orderBy('id')->get(['id', 'name', 'username', 'email', 'status', 'must_change_password']);
            $data['staff'] = $this->records->query(E::Staff, $tenant)->orderBy('id')->get();
            $data['summary'] = app(LiveStatisticsService::class)->summary($tenant, now()->subMonth()->format('Y-m-d'), now()->format('Y-m-d'));
            $data['audit'] = $this->records->query(E::Audit, $tenant)->orderByDesc('id')->limit(100)->get();
        }

        return $data;
    }

    public function tenant(int $actor, array $data, ?int $id): LiveTenant
    {
        return $this->records->transaction(function () use ($actor, $data, $id) {
            $tenant = $id ? LiveTenant::query()->lockForUpdate()->findOrFail($id) : LiveTenant::create($data);
            if ($id) {
                $tenant->fill($data)->save();
            }
            $this->records->create(E::Audit, $tenant->id, ['actor_id' => $actor, 'actor_type' => 'headquarters', 'action' => 'tenant.saved', 'data' => ['name' => $tenant->name, 'status' => $tenant->status]]);

            return $tenant;
        });
    }
}
