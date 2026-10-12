<?php

namespace Plugins\Yutiv\LiveCommerce\Enums;

enum StockState: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Shipped = 'shipped';
    case Returned = 'returned';
    case As = 'as';
    case Defective = 'defective';
    case Lost = 'lost';
    case Disposed = 'disposed';

    public function adjustments(): array
    {
        return match ($this) {
            self::Available => [self::Available, self::As, self::Defective, self::Lost, self::Disposed],
            self::Returned => [self::Available, self::As, self::Defective, self::Disposed],
            self::As => [self::Available, self::Defective, self::Disposed],
            self::Lost => [self::Available, self::Disposed],
            self::Defective => [self::As, self::Disposed],
            default => [],
        };
    }
}
