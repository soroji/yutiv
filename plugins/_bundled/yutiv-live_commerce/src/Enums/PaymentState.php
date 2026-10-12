<?php

namespace Plugins\Yutiv\LiveCommerce\Enums;

enum PaymentState: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Review = 'review';
    case Expired = 'expired';
}
