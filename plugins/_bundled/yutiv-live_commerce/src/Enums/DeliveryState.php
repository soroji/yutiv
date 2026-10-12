<?php

namespace Plugins\Yutiv\LiveCommerce\Enums;

enum DeliveryState: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
}
