<?php
namespace Plugins\Yutiv\LiveCommerce\Enums;
enum TransferStatus:string {
 case Requested='requested';
 case Withdrawn='withdrawn';
 case Received='received';
 case CancelPending='cancel_pending';
 case Cancelled='cancelled';
}
