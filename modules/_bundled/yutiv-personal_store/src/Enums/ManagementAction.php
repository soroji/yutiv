<?php

namespace Modules\Yutiv\PersonalStore\Enums;

enum ManagementAction: string
{
    case Apply = 'apply';
    case Profile = 'profile';
    case Products = 'products';
    case Categories = 'categories';
    case Allocation = 'allocation';
    case RequestMove = 'request_move';
    case Approve = 'approve';
    case ProductRate = 'product_rate';
    case Status = 'status';
}
