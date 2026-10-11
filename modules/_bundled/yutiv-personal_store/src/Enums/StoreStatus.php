<?php

namespace Modules\Yutiv\PersonalStore\Enums;

enum StoreStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
}
