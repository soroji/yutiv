<?php

namespace Modules\Yutiv\PersonalStore\Enums;

enum RewardLedgerKind: string
{
    case Earn = 'earn';
    case Recover = 'recover';
    case Shortfall = 'shortfall';
    case Offset = 'offset';
}
