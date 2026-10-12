<?php

namespace Plugins\Yutiv\LiveCommerce\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;

class LiveRepository implements LiveRepositoryInterface
{
    public function query(LiveEntity $entity, int $tenantId): Builder
    {
        return LiveRecord::entity($entity)->newQuery()->where('tenant_id', $tenantId);
    }

    public function create(LiveEntity $entity, int $tenantId, array $data): LiveRecord
    {
        return $this->query($entity, $tenantId)->create(array_merge($data, ['tenant_id' => $tenantId]));
    }

    public function transaction(callable $callback): mixed
    {
        return LiveRecord::entity(LiveEntity::Order)->getConnection()->transaction($callback, 3);
    }
}
