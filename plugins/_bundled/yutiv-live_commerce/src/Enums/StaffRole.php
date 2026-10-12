<?php

namespace Plugins\Yutiv\LiveCommerce\Enums;

enum StaffRole: string
{
    case Owner = 'owner';
    case Sales = 'sales';
    case Warehouse = 'warehouse';
    case Finance = 'finance';
    case Customs = 'customs';

    public function allows(string $ability): bool
    {
        return $this === self::Owner || in_array($ability, match ($this) {
            self::Sales => ['catalog', 'broadcast', 'orders', 'contacts', 'statistics'],
            self::Warehouse => ['inventory', 'shipping', 'labels', 'orders'],
            self::Finance => ['banks', 'deposit', 'refund', 'settlement', 'orders', 'statistics'],
            self::Customs => ['customs', 'shipping', 'orders'],
            default => [],
        }, true);
    }
}
