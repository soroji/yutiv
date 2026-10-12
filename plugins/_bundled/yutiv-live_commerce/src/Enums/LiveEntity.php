<?php

namespace Plugins\Yutiv\LiveCommerce\Enums;

enum LiveEntity: string
{
    case Domain = 'live_domains';
    case Staff = 'live_staff';
    case Address = 'live_addresses';
    case Reset = 'live_password_resets';
    case Bank = 'live_bank_accounts';
    case Product = 'live_products';
    case Option = 'live_options';
    case Channel = 'live_channels';
    case Showhost = 'live_showhosts';
    case Broadcast = 'live_broadcasts';
    case BroadcastItem = 'live_broadcast_items';
    case SaleLink = 'live_sale_links';
    case CartLine = 'live_cart_lines';
    case Order = 'live_orders';
    case OrderLine = 'live_order_lines';
    case Unit = 'live_stock_units';
    case Movement = 'live_stock_movements';
    case Event = 'live_order_events';
    case Incentive = 'live_incentive_ledger';
    case Audit = 'live_audit';
    case Operation = 'live_operations';
}
