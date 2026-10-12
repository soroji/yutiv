<?php

namespace Plugins\Yutiv\LiveCommerce\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;

interface LiveRepositoryInterface
{
    public function query(LiveEntity $entity, int $tenantId): Builder;

    public function create(LiveEntity $entity, int $tenantId, array $data): LiveRecord;

    public function transaction(callable $callback): mixed;
}
