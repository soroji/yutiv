<?php

namespace Plugins\Yutiv\LiveCommerce\Enums;

enum ExpenseSourceKind: string
{
    case Manual = 'manual';
    case Receipt = 'receipt';
    case Recurring = 'recurring';
    case Reversal = 'reversal';
}
