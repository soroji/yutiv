<?php

namespace Plugins\Yutiv\LiveCommerce\Contracts;

interface SellerBrandRepositoryInterface
{
    public function updateOwnedName(int $ownerId, string $slug, string $name): void;
}
