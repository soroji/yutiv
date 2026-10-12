<?php

namespace Plugins\Yutiv\LiveCommerce\Repositories;

use Plugins\Yutiv\LiveCommerce\Contracts\SellerBrandRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;

class SellerBrandRepository implements SellerBrandRepositoryInterface
{
    public function updateOwnedName(int $ownerId, string $slug, string $name): void
    {
        $model = new LiveTenant;
        $model->getConnection()->transaction(function () use ($model, $ownerId, $slug, $name): void {
            // 저장 직전의 소유권을 같은 행 잠금 안에서 다시 확인합니다.
            $tenant = $model->newQuery()->where('slug', $slug)->where('owner_user_id', $ownerId)->lockForUpdate()->firstOrFail();
            $tenant->update(['name' => $name]);
        });
    }
}
