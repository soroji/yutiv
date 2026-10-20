<?php

namespace Plugins\Yutiv\LiveCommerce\Enums;

enum ReportRowKind: string
{
    case Option = 'option';
    case Order = 'order';
    case Event = 'event';
    case Movement = 'movement';
}
