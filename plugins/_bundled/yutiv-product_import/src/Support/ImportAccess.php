<?php

namespace Plugins\Yutiv\ProductImport\Support;

use App\Enums\PermissionType;
use App\Models\User;

final class ImportAccess
{
    public static function allowed(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Explicit headquarters grant AND existing unscoped product creation.
        return $user->hasPermission('yutiv-product_import.headquarters.create', PermissionType::Admin)
            && $user->hasPermission('sirsoft-ecommerce.products.create', PermissionType::Admin)
            && $user->getEffectiveScopeForPermission('sirsoft-ecommerce.products.create') === null
            && $user->getEffectiveScopeForPermission('yutiv-product_import.headquarters.create') === null;
    }
}
