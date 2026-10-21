<?php
namespace Plugins\Yutiv\LiveCommerce\Enums;
enum FundEntryKind:string {
 case Opening='opening';
 case Adjustment='adjustment';
 case Deposit='deposit';
 case Refund='refund';
 case Recovery='recovery';
 case Payment='payment';
 case TransferOut='transfer_out';
 case TransferIn='transfer_in';
 case TransferReturn='transfer_return';
 case Fee='fee';
 case IncentivePayment='incentive_payment';
 case IncentiveRecovery='incentive_recovery';
}
