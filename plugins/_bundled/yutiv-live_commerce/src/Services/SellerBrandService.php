<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Contracts\SellerBrandRepositoryInterface;

class SellerBrandService
{
    public function __construct(private readonly SellerBrandRepositoryInterface $brands) {}

    public function updateOwnedName(int $ownerId, string $slug, string $name): void
    {
        $this->brands->updateOwnedName($ownerId, $slug, $name);
    }
}
