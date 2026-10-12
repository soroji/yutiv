<?php

namespace Plugins\Yutiv\LiveCommerce\Enums;

enum BroadcastState: string
{
    case Draft = 'draft';
    case Selling = 'selling';
    case Closed = 'closed';
}
