<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;

abstract class LiveWorkflow
{
    public function __construct(protected readonly LiveRepositoryInterface $records) {}

    protected function audit(int $tenant, int $actor, string $action, array $data = []): void
    {
        $type = 'system';
        if ($actor) {
            $request = app()->bound('request') ? request() : null;
            $type = $request?->is('api/plugins/yutiv-live_commerce/admin/*') ? 'headquarters'
                : ($this->records->query(E::Staff, $tenant)->where('user_id', $actor)->exists() ? 'staff' : 'customer');
        }
        $this->records->create(E::Audit, $tenant, ['actor_id' => $actor ?: null, 'actor_type' => $type, 'action' => $action, 'data' => $data]);
    }

    /** 업체 행을 멱등 작업의 짧은 직렬화 경계로 사용합니다. 외부 통신은 금지합니다. */
    protected function once(int $tenant, string $key, array $input, callable $work): array
    {
        return $this->records->transaction(function () use ($tenant, $key, $input, $work): array {
            LiveTenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $existing = $this->records->query(E::Operation, $tenant)->where('operation_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, __('yutiv-live_commerce::live.errors.e_d3154a2b33'));

                return $existing->result;
            }
            $result = $work();
            $this->records->create(E::Operation, $tenant, ['operation_key' => $key, 'request_hash' => $hash, 'result' => $result]);

            return $result;
        });
    }

    protected function row(E $entity, int $tenant, int $id, bool $lock = false): LiveRecord
    {
        $query = $this->records->query($entity, $tenant)->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }
}
