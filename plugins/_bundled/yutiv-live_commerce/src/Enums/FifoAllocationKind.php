<?php
namespace Plugins\Yutiv\LiveCommerce\Enums;
enum FifoAllocationKind:string {
 case Sale='sale';
 case Loss='loss';
 case Return='return';
 case Restore='restore';
 case LegacyReturn='legacy_return';
}
