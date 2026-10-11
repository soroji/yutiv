<?php

namespace Modules\Yutiv\PersonalStore\Services;

use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;

class StoreManagementService
{
    public function __construct(private StoreRepositoryInterface $stores) {}

    public function manage(int $actorId, array $data): array
    {
        return $this->stores->manage($actorId, $data['action'], $data);
    }

    public function dashboard(int $actorId, array $filters): array
    {
        return $this->stores->dashboard($actorId, $filters);
    }

    public function headquarters(int $actorId, array $filters): array
    {
        return $this->stores->headquarters($actorId, $filters);
    }

    public function catalog(int $actorId, array $filters): array
    {
        return $this->stores->catalog($actorId, $filters);
    }
}
