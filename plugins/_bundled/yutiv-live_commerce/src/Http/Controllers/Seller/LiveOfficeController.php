<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity;
use Plugins\Yutiv\LiveCommerce\Enums\StaffRole;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveCatalogRequest;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveOfficeRequest;
use Plugins\Yutiv\LiveCommerce\Services\LiveCatalogService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOfficeService;
use Plugins\Yutiv\LiveCommerce\Services\LiveStatisticsService;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\LiveForms;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;

class LiveOfficeController
{
    public const MENU = ['dashboard' => ['운영 현황', 'orders'], 'broadcasts' => ['방송 관리', 'broadcast'], 'channels' => ['유튜브 채널', 'broadcast'], 'showhosts' => ['쇼호스트', 'broadcast'],
        'products' => ['상품 관리', 'catalog'], 'options' => ['상품 옵션', 'catalog'], 'broadcast-items' => ['방송 판매 상품', 'broadcast'], 'sale-links' => ['판매 링크', 'broadcast'],
        'orders' => ['주문·입금 확인', 'orders'], 'banks' => ['입금계좌', 'banks'], 'receive' => ['입고', 'inventory'], 'ship' => ['출고', 'shipping'],
        'inventory' => ['재고·실사·조정', 'inventory'], 'returns' => ['반품·A/S', 'inventory'], 'statistics' => ['매출 통계', 'statistics'], 'incentives' => ['인센티브·정산', 'settlement'],
        'labels' => ['QR 라벨 출력', 'labels'], 'brand' => ['브랜드·상담 링크', 'brand'], 'staff' => ['직원·권한', 'staff'], 'audit' => ['작업 이력', 'audit']];

    public function __construct(private readonly LiveOfficeService $office, private readonly LiveCatalogService $catalog, private readonly LiveStatisticsService $statistics) {}

    public function index(LiveOfficeRequest $request, string $section = 'dashboard'): View
    {
        $ability = self::MENU[$section][1] ?? abort(404);
        $user = LiveContext::authorize($ability);
        $tenant = LiveContext::requireTenant();
        $staff = $this->office->query(LiveEntity::Staff, $tenant->id)->where('user_id', $user->id)->firstOrFail();
        $role = StaffRole::from($staff->role);
        $menu = array_filter(self::MENU, fn ($item) => $role->allows($item[1]));
        $data = $this->office->data($tenant->id, $section, $request->integer('edit') ?: null);
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
            'menu' => array_filter(self::MENU, fn ($item) => $role->allows($item[1])),
            'lines' => $lines, 'effectiveIncentives' => $effectiveIncentives,
            'events' => $events,
            'channels' => $this->office->query($e::Channel, $tenant->id)->where('active', true)->get(),
            'hosts' => $this->office->query($e::Showhost, $tenant->id)->where('active', true)->get(),
            'banks' => $role->allows('banks') ? $this->office->query($e::Bank, $tenant->id)->where('active', true)->get() : collect(),
            'portalUrl' => TeeWidePresenter::portalUrl(), 'liveUrl' => TeeWidePresenter::liveUrl()]);
    }
}
