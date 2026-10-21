<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller;

use App\Helpers\ResponseHelper;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Enums\ReportKind;
use Plugins\Yutiv\LiveCommerce\Enums\StaffRole;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveFinanceActionRequest;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveFinanceEvidenceRequest;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveFinanceRequest;
use Plugins\Yutiv\LiveCommerce\Services\LiveCashService;
use Plugins\Yutiv\LiveCommerce\Services\LiveExpenseService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFifoService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFinanceAssetService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFinanceDashboardService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFinanceEvidenceService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFinanceReportService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFinanceSetupService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFundService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOfficeService;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;

class LiveFinanceController
{
    public const SECTIONS = ['overview' => '회계 현황', 'sales' => '매출·입금·미수', 'expenses' => '매입·지출', 'accounts' => '계좌·자금 이동', 'assets' => '재고 자산·체류', 'reports' => '보고서·관리 마감'];

    public function __construct(private readonly LiveOfficeService $office) {}

    public function index(LiveFinanceRequest $request, string $section = 'overview')
    {
        abort_unless(isset(self::SECTIONS[$section]), 404);
        $tenant = LiveContext::requireTenant();
        $user = LiveContext::user();
        $t = $tenant->id;
        $staff = $this->office->query(E::Staff, $t)->where('user_id', $user->id)->firstOrFail();
        $role = StaffRole::from($staff->role);
        $from = $request->input('from', now($tenant->timezone)->startOfMonth()->format('Y-m-d'));
        $to = $request->input('to', now($tenant->timezone)->format('Y-m-d'));
        $period = $request->input('period', 'day');
        $state = $this->office->query(E::FinanceState, $t)->first();
        $report = null;
        $dashboard = null;
        $rows = null;
        // A sales-only role must never construct a cost-bearing report, even if the view hides fields.
        if ($state && $role->allows('finance.read') && in_array($section, ['overview', 'reports'], true)) {
            $reports = app(LiveFinanceReportService::class);
            [$report, $dashboard] = $reports->snapshot(function () use ($reports, $t, $user, $from, $to, $period) {
                $result = $reports->report($t, $user->id, $from, $to, $period);

                return [$result, app(LiveFinanceDashboardService::class)->forReport($t, $result)];
            });
        }
        $q = match ($section) {
            'expenses' => $this->office->query(E::Expense, $t)->whereBetween('occurred_on', [$from, $to]),'accounts' => $this->office->query(E::MoneyTransfer, $t),'reports' => $this->office->query(E::Report, $t)->where('report_kind', ReportKind::Finance->value),'sales' => $this->office->query(E::Order, $t)->when($request->integer('order_id'), fn ($q) => $q->whereKey($request->integer('order_id')))->when($request->filled('search'), fn ($q) => $q->where('uuid', 'like', '%'.$request->input('search').'%')),default => null
        };
        $expenseTotals = $section === 'expenses' ? (clone $q)->select('currency')->selectRaw("SUM(amount_original * CASE WHEN source_kind='reversal' THEN -1.0 ELSE 1.0 END) original_sum,SUM(amount_krw * CASE WHEN source_kind='reversal' THEN -1.0 ELSE 1.0 END) krw_sum,SUM(CASE WHEN amount_krw IS NULL THEN 1 ELSE 0 END) unknown_rows")->groupBy('currency')->get() : null;
        if ($q) {
            $rows = $q->orderByDesc('id')->paginate(20)->withQueryString();
        }
        $assets = $section === 'assets' ? app(LiveFinanceAssetService::class)->totals($t, $request->only(['product_id', 'search', 'location', 'aging_bucket']), now('UTC')->toDateTimeString()) : null;
        $assetRows = $section === 'assets' ? app(LiveFinanceAssetService::class)->grouped($t, $request->only(['product_id', 'search', 'location', 'aging_bucket']), now('UTC')->toDateTimeString())->orderBy(E::Unit->value.'.option_id')->orderBy(E::Unit->value.'.receipt_item_id')->paginate(20)->withQueryString() : null;
        // Funds and categories are operator configuration lists, independent of transaction volume.
        $funds = $role->allows('finance.read') ? $this->office->query(E::Fund, $t)->select('*')->addSelect(['balance_minor' => $this->office->query(E::FundEntry, $t)->whereColumn('fund_id', E::Fund->value.'.id')->selectRaw('COALESCE(SUM(amount_minor),0)')])->orderBy('id')->get() : collect();

        $categories = $role->allows('finance.read') ? $this->office->query(E::ExpenseCategory, $t)->orderBy('id')->get() : collect();
        $banks = $role->allows('banks') ? $this->office->query(E::Bank, $t)->orderBy('id')->get() : collect();
        $payments = $section === 'expenses' ? $this->office->query(E::ExpensePayment, $t)->whereBetween('paid_on', [$from, $to])->orderByDesc('id')->paginate(20, ['*'], 'payments_page')->withQueryString() : null;
        $recurring = $section === 'expenses' ? $this->office->query(E::RecurringExpense, $t)->orderByDesc('id')->paginate(20, ['*'], 'rules_page')->withQueryString() : null;
        $closings = $section === 'reports' ? $this->office->query(E::FinanceClosing, $t)->orderByDesc('id')->paginate(20, ['*'], 'closings_page')->withQueryString() : null;
        $evidence = in_array($section, ['expenses', 'accounts'], true) ? $this->office->query(E::FinanceAttachment, $t)->where('source_kind', $section === 'expenses' ? 'expense' : 'transfer')->orderByDesc('id')->paginate(10, ['id', 'name', 'source_kind', 'source_id'], 'evidence_page')->withQueryString() : null;
        $fundEntries = $section === 'accounts' ? $this->office->query(E::FundEntry, $t)->whereBetween('occurred_on', [$from, $to])->when($request->integer('fund_id'), fn ($q) => $q->where('fund_id', $request->integer('fund_id')))->orderByDesc('id')->paginate(20, ['*'], 'entries_page')->withQueryString() : null;
        $currentBalances = $section === 'sales' && $role->allows('finance.read') ? app(LiveFinanceReportService::class)->currentBalances($t) : null;

        return view('teewide::seller.finance', compact('tenant', 'role', 'state', 'section', 'from', 'to', 'period', 'report', 'dashboard', 'rows', 'assets', 'assetRows', 'funds', 'categories', 'banks', 'payments', 'recurring', 'closings', 'evidence', 'currentBalances', 'expenseTotals', 'fundEntries') + ['teeWideUser' => $user, 'sections' => self::SECTIONS, 'portalUrl' => TeeWidePresenter::portalUrl(), 'liveUrl' => TeeWidePresenter::liveUrl()]);
    }

    public function store(LiveFinanceActionRequest $request)
    {
        $t = LiveContext::requireTenant()->id;
        $a = LiveContext::user()->id;
        $d = $request->validated();
        $action = $request->route('action');
        $result = match ($action) {
            'activate','settings','bank','logistics' => app(LiveFinanceSetupService::class)->$action($t, $a, $d),
            'category','expense','recurring' => app(LiveExpenseService::class)->$action($t, $a, $d),
            'payment' => app(LiveExpenseService::class)->pay($t, $a, $d),
            'payment-recovery' => app(LiveExpenseService::class)->recover($t, $a, $d),
            'rate-confirm' => app(LiveExpenseService::class)->confirmRate($t, $a, $d),
            'expense-void' => app(LiveExpenseService::class)->void($t, $a, $d),
            'expense-link-receipt' => app(LiveExpenseService::class)->linkReceipt($t, $a, $d),
            'fund' => app(LiveFundService::class)->fund($t, $a, $d),
            'fund-adjust' => app(LiveFundService::class)->adjust($t, $a, $d),
            'transfer' => app(LiveFundService::class)->transfer($t, $a, $d),
            'withdraw','receive','cancel','cancel-confirm' => app(LiveFundService::class)->transferStep($t, $a, $d['id'], $action, $d),
            'cash-receipt' => app(LiveCashService::class)->receipt($t, $a, $d),
            'cash-recheck' => app(LiveCashService::class)->recheck($t, $a, $d),
            'cash-refund' => app(LiveCashService::class)->refund($t, $a, $d),
            'incentive-payment','incentive-recovery' => app(LiveCashService::class)->incentive($t, $a, $d, $action === 'incentive-recovery'),
            'fifo-prepare' => app(LiveFifoService::class)->prepare($t, $a, $d['option_id'], $d['operation_key']),
            'report' => ['report_uuid' => app(LiveFinanceReportService::class)->saveOnce($t, $a, $d)],
            'close','reopen' => app(LiveFinanceReportService::class)->$action($t, $a, $d),
            default => abort(404),
        };
        $result['return_url'] = isset($result['report_uuid']) ? '/seller/accounting/reports/'.$result['report_uuid'] : url()->previous();

        return ResponseHelper::success('common.success', $result);
    }

    public function saved(LiveFinanceRequest $request, string $uuid)
    {
        $tenant = LiveContext::requireTenant();
        $user = LiveContext::user();

        return view('teewide::seller.finance-report', app(LiveFinanceReportService::class)->saved($tenant->id, $user->id, $uuid, $request->integer('part', 1)) + ['role'=>StaffRole::from($this->office->query(E::Staff,$tenant->id)->where('user_id',$user->id)->firstOrFail()->role),'orientation' => $request->input('orientation', 'portrait'), 'tenant' => $tenant, 'teeWideUser' => $user, 'portalUrl' => TeeWidePresenter::portalUrl(), 'liveUrl' => TeeWidePresenter::liveUrl()]);
    }

    public function evidence(LiveFinanceEvidenceRequest $request)
    {
        return ResponseHelper::success('common.success', app(LiveFinanceEvidenceService::class)->upload(LiveContext::requireTenant()->id, LiveContext::user()->id, $request->validated(), $request->file('evidence')));
    }

    public function download(LiveFinanceEvidenceRequest $request, int $id)
    {
        return app(LiveFinanceEvidenceService::class)->download(LiveContext::requireTenant()->id, $id);
    }
}
