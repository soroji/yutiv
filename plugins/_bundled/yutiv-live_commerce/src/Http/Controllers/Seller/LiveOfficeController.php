<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller;

use App\Helpers\ResponseHelper;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity;
use Plugins\Yutiv\LiveCommerce\Enums\StaffRole;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveCatalogRequest;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveOfficeRequest;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveReceiptRequest;
use Plugins\Yutiv\LiveCommerce\Services\LiveCatalogService;
use Plugins\Yutiv\LiveCommerce\Services\LiveLedgerService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOfficeService;
use Plugins\Yutiv\LiveCommerce\Services\LiveReceiptService;
use Plugins\Yutiv\LiveCommerce\Services\LiveStatisticsService;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\LiveForms;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;

class LiveOfficeController
{
    public const MENU = ['dashboard' => ['운영 현황', 'orders'], 'broadcasts' => ['방송 관리', 'broadcast'], 'channels' => ['유튜브 채널', 'broadcast'], 'showhosts' => ['쇼호스트', 'broadcast'],
        'products' => ['상품 관리', 'catalog'], 'options' => ['상품 옵션', 'catalog'], 'broadcast-items' => ['방송 판매 상품', 'broadcast'], 'sale-links' => ['판매 링크', 'broadcast'],
        'accounting' => ['회계관리', 'statistics'], 'orders' => ['주문·입금 확인', 'orders'], 'banks' => ['입금계좌', 'banks'], 'receive' => ['입고', 'inventory'], 'ship' => ['출고', 'shipping'],
        'inventory' => ['재고·실사·조정', 'inventory'], 'returns' => ['반품·A/S', 'inventory'], 'statistics' => ['매출 통계', 'statistics'], 'incentives' => ['인센티브·정산', 'settlement'],
        'labels' => ['상품 관리 · QR 라벨', 'labels'], 'receipts' => ['입고 내역', 'inventory'], 'product-receive' => ['상품 등록·입고', 'inventory'], 'ledger' => ['매출정산', 'settlement'], 'brand' => ['브랜드·상담 링크', 'brand'], 'staff' => ['직원·권한', 'staff'], 'audit' => ['작업 이력', 'audit']];

    public function __construct(private readonly LiveOfficeService $office, private readonly LiveCatalogService $catalog, private readonly LiveStatisticsService $statistics) {}

    public function index(LiveOfficeRequest $request, string $section = 'dashboard'): View
    {
        $ability = self::MENU[$section][1] ?? abort(404);
        $user = LiveContext::authorize($ability);
        $tenant = LiveContext::requireTenant();
        $staff = $this->office->query(LiveEntity::Staff, $tenant->id)->where('user_id', $user->id)->firstOrFail();
        $role = StaffRole::from($staff->role);
        $menu = $this->menu($role);
        $data = $this->office->data($tenant->id, $section, $request->integer('edit') ?: null);
        if ($section === 'product-receive') {
            $data['products'] = $this->office->query(LiveEntity::Product, $tenant->id)->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%')->orWhere('code', 'like', '%'.$request->input('search').'%')))->orderBy('name')->limit(50)->get();
            $data['source'] = $request->integer('product_id') ? $this->office->query(LiveEntity::Product, $tenant->id)->findOrFail($request->integer('product_id')) : null;
            $data['options'] = $data['source'] ? $this->office->query(LiveEntity::Option, $tenant->id)->where('product_id', $data['source']->id)->get()->map(function ($option) use ($tenant) {
                $option->available = $this->office->query(LiveEntity::Unit, $tenant->id)->where('option_id', $option->id)->where('state', 'available')->count();
                $option->reserved = $this->office->query(LiveEntity::Unit, $tenant->id)->where('option_id', $option->id)->where('state', 'reserved')->count();

                return $option;
            }) : collect();
        }
        if ($section === 'receipts') {
            $data['rows'] = $this->office->query(LiveEntity::Receipt, $tenant->id)
                ->when($request->integer('batch'), fn ($q) => $q->whereKey($request->integer('batch')))
                ->when($request->filled('search'), fn ($q) => $q->whereIn('product_id', $this->office->query(LiveEntity::Product, $tenant->id)->where(fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%')->orWhere('code', 'like', '%'.$request->input('search').'%'))->select('id')))
                ->when($request->filled('location'), fn ($q) => $q->where('location', 'like', '%'.$request->input('location').'%'))
                ->when($request->integer('actor_id'), fn ($q) => $q->where('actor_id', $request->integer('actor_id')))
                ->when($request->integer('option_id'), fn ($q) => $q->whereIn('receipt_key', $this->office->query(LiveEntity::Unit, $tenant->id)->where('option_id', $request->integer('option_id'))->select('receipt_key')))
                ->when($request->filled('from'), fn ($q) => $q->where('received_at', '>=', CarbonImmutable::parse($request->input('from'), $tenant->timezone)->utc()))
                ->when($request->filled('to'), fn ($q) => $q->where('received_at', '<', CarbonImmutable::parse($request->input('to'), $tenant->timezone)->addDay()->utc()))
                ->orderByDesc('id')->paginate(20)->withQueryString();
        }
        $data['ledger'] = $section === 'ledger' ? app(LiveLedgerService::class)->report($tenant->id, $user->id, $request->input('from', now($tenant->timezone)->format('Y-m-d')), $request->input('to', now($tenant->timezone)->format('Y-m-d')), $request->input('period', 'day'), $request->integer('product_id') ?: null, $request->integer('page', 1)) : null;
        $data['savedReports'] = $section === 'ledger' ? $this->office->query(LiveEntity::Report, $tenant->id)->where('report_kind',\Plugins\Yutiv\LiveCommerce\Enums\ReportKind::Operational->value)->orderByDesc('id')->paginate(10, ['id', 'uuid', 'created_at', 'actor_id'], 'reports_page')->withQueryString() : null;
        $data['summary'] = in_array($section, ['dashboard', 'statistics'], true) ? $this->statistics->summary($tenant->id, $request->input('from', now()->subMonth()->format('Y-m-d')), $request->input('to', now()->format('Y-m-d')),
            $request->input('basis', 'created_at'), $request->input('period', 'day'), $request->only(['broadcast_id', 'channel_id', 'showhost_id', 'product_id', 'option_id'])) : null;

        return view('teewide::seller.office', array_merge($data, ['tenant' => $tenant, 'menu' => $menu, 'menuTitle' => self::MENU[$section][0], 'role' => $role,
            'portalUrl' => TeeWidePresenter::portalUrl(), 'liveUrl' => TeeWidePresenter::liveUrl(), 'teeWideUser' => $user]));
    }

    public function store(LiveCatalogRequest $request): JsonResponse
    {
        $tenant = LiveContext::requireTenant();
        $user = LiveContext::user();
        $record = $this->catalog->save(LiveForms::entity($request->route('entity')), $tenant->id, $user->id, $this->office->catalogData($request), ((int) $request->route('record')) ?: null);

        return ResponseHelper::success('common.success', ['saved' => true, 'record_id' => $record->id]);
    }

    public function receive(LiveReceiptRequest $request): JsonResponse
    {
        $result=app(LiveReceiptService::class)->receive(LiveContext::requireTenant()->id, LiveContext::user()->id, $request->validated());
        $result['return_url']=app(\Plugins\Yutiv\LiveCommerce\Services\LiveProductWorkspace::class)->back(($request->input('list')??''),$result['receipt_id']);
        return ResponseHelper::success('common.success',$result);
    }

    public function saveReport(LiveOfficeRequest $request)
    {
        $user = LiveContext::authorize('settlement');
        $tenant = LiveContext::requireTenant();
        $uuid = app(LiveLedgerService::class)->save($tenant->id, $user->id, $request->input('from', now($tenant->timezone)->format('Y-m-d')), $request->input('to', now($tenant->timezone)->format('Y-m-d')), $request->input('period', 'day'), $request->integer('product_id') ?: null);

        return redirect('/seller/reports/'.$uuid);
    }

    public function savedReport(LiveOfficeRequest $request, string $uuid): View
    {
        $user = LiveContext::authorize('settlement');
        $tenant = LiveContext::requireTenant();

        return view('teewide::seller.saved-report', array_merge(app(LiveLedgerService::class)->saved($tenant->id, $user->id, $uuid, $request->integer('part', 1)), [
            'tenant' => $tenant, 'teeWideUser' => $user, 'portalUrl' => TeeWidePresenter::portalUrl(), 'liveUrl' => TeeWidePresenter::liveUrl(),
        ]));
    }

    public function order(string $uuid): View
    {
        $user = LiveContext::authorize('orders');
        $tenant = LiveContext::requireTenant();
        $e = LiveEntity::class;
        $staff = $this->office->query($e::Staff, $tenant->id)->where('user_id', $user->id)->firstOrFail();
        $role = StaffRole::from($staff->role);
        $order = $this->office->query($e::Order, $tenant->id)->where('uuid', $uuid)->firstOrFail();
        $events = $this->office->query($e::Event, $tenant->id)->where('order_id', $order->id)->orderBy('id')->get();
        $lines = $this->office->query($e::OrderLine, $tenant->id)->where('order_id', $order->id)->get();
        $effectiveIncentives = [];
        foreach ($lines as $line) {
            $correction = $events->last(fn ($event) => $event->type === 'attribution_correction' && (int) ($event->data['line_id'] ?? 0) === $line->id);
            $effectiveIncentives[$line->id] = $correction?->data['after']['incentives'] ?? $line->snapshot['incentives'];
        }

        return view('teewide::seller.order', ['tenant' => $tenant, 'teeWideUser' => $user, 'order' => $order, 'role' => $role,
            'menu' => $this->menu($role),
            'lines' => $lines, 'effectiveIncentives' => $effectiveIncentives,
            'events' => $events,
            'financeActive' => $this->office->query(LiveEntity::FinanceState,$tenant->id)->exists(),
            'channels' => $this->office->query($e::Channel, $tenant->id)->where('active', true)->get(),
            'hosts' => $this->office->query($e::Showhost, $tenant->id)->where('active', true)->get(),
            'banks' => $role->allows('banks') ? $this->office->query($e::Bank, $tenant->id)->where('active', true)->get() : collect(),
            'portalUrl' => TeeWidePresenter::portalUrl(), 'liveUrl' => TeeWidePresenter::liveUrl()]);
    }

    private function menu(StaffRole $role): array
    {
        $menu = array_filter(self::MENU, fn ($item) => $role->allows($item[1]));
        unset($menu['banks'],$menu['statistics'],$menu['ledger'],$menu['inventory'],$menu['labels'], $menu['options'], $menu['receive'], $menu['product-receive'], $menu['receipts']);
        if ($role->allows('statistics') && ! $role->allows('finance.read')) {
            unset($menu['accounting']);
            $menu['accounting/sales']=['매출·현재 주문 분석','statistics'];
        }
        if ($role->allows('inventory') && ! $role->allows('catalog')) {
            $menu['products'] = ['상품관리', 'inventory'];
        }

        return $menu;
    }
}
