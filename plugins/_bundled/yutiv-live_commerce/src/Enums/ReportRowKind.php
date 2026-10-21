<?php

namespace Plugins\Yutiv\LiveCommerce\Enums;

enum ReportRowKind: string
{
    case Option = 'option';
    case Order = 'order';
    case Event = 'event';
    case Movement = 'movement';
    case Purchase = 'purchase';
    case FinanceAsset = 'finance_asset';
    case FinanceCash = 'finance_cash';
    case FinanceExpense = 'finance_expense';
    case FinanceFifo = 'finance_fifo';
    case FinanceIncentive = 'finance_incentive';
    case FinanceOrder = 'finance_order';
    case FinanceOrderEvent = 'finance_order_event';
    case FinanceOrderLine = 'finance_order_line';
    case FinancePayment = 'finance_payment';
    case FinancePurchase = 'finance_purchase';
    case FinanceRecovery = 'finance_recovery';
    case FinanceStockMovement = 'finance_stock_movement';
    case FinanceTransfer = 'finance_transfer';
}
