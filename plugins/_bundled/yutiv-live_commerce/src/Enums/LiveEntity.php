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
    case Receipt = 'live_receipts';
    case Category = 'live_categories';
    case FinanceAttachment = 'live_finance_attachments';
    case FinanceState = 'live_finance_states';
    case ExpenseCategory = 'live_expense_categories';
    case Expense = 'live_expenses';
    case ExpenseRecovery = 'live_expense_recoveries';
    case ExpensePayment = 'live_expense_payments';
    case Fund = 'live_funds';
    case FundEntry = 'live_fund_entries';
    case MoneyTransfer = 'live_money_transfers';
    case RecurringExpense = 'live_recurring_expenses';
    case FinanceClosing = 'live_finance_closings';
    case FifoOption = 'live_fifo_options';
    case FifoLayer = 'live_fifo_layers';
    case FifoAllocation = 'live_fifo_allocations';
    case StockCount = 'live_stock_counts';
    case InventoryRevision = 'live_inventory_revisions';
    case ReceiptItem = 'live_receipt_items';
    case LabelRequest = 'live_label_requests';
    case LabelRequestUnit = 'live_label_request_units';
    case Report = 'live_reports';
    case ReportRow = 'live_report_rows';
}
