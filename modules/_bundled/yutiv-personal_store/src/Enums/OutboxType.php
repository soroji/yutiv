<?php

namespace Modules\Yutiv\PersonalStore\Enums;

enum OutboxType: string
{
    case Created = 'created';
    case State = 'state';
}
