<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller;

use App\Helpers\ResponseHelper;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveStockCountRequest;
use Plugins\Yutiv\LiveCommerce\Services\LiveProductWorkspace;
use Plugins\Yutiv\LiveCommerce\Services\LiveStockCountService;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;

class LiveStockCountController
{
    public function __construct(private readonly LiveStockCountService $counts) {}

    public function index(LiveStockCountRequest $request)
    {
        $tenant = LiveContext::requireTenant();
        $user = LiveContext::user();
        $count = $request->integer('count') ? $this->counts->query(E::StockCount, $tenant->id)->findOrFail($request->integer('count')) : null;
        $optionId = $count?->option_id ?? $request->integer('option_id');
        if ($request->filled('unit')) {
            $unit = $this->counts->query(E::Unit, $tenant->id)->where('uuid', $request->input('unit'))->firstOrFail();
            $optionId = $unit->option_id;
        }
        $option = $optionId ? $this->counts->query(E::Option, $tenant->id)->findOrFail($optionId) : null;
        $product = $option ? $this->counts->query(E::Product, $tenant->id)->findOrFail($option->product_id) : ($request->integer('product_id') ? $this->counts->query(E::Product, $tenant->id)->findOrFail($request->integer('product_id')) : null);
        $location = $count?->location ?? $request->input('location', isset($unit) ? $unit->location : '');
        $units = $this->counts->query(E::Unit, $tenant->id)->when($option, fn ($q) => $q->where('option_id', $option->id))->when($location !== '', fn ($q) => $q->where('location', $location))->orderBy('id')->paginate(50, ['id', 'uuid', 'option_id', 'state', 'location']);
        $history = $this->counts->query(E::StockCount, $tenant->id)->when($product, fn ($q) => $q->whereIn('option_id', $this->counts->query(E::Option, $tenant->id)->where('product_id', $product->id)->select('id')))->orderByDesc('id')->paginate(20, ['*'], 'history_page');
        $unitDetail = $unit ?? null;
        $movements = $unitDetail ? $this->counts->query(E::Movement, $tenant->id)->where('unit_id', $unitDetail->id)->orderByDesc('id')->paginate(20, ['*'], 'movements_page')->withQueryString() : null;

        return view('teewide::seller.stock-count', ['role'=>\Plugins\Yutiv\LiveCommerce\Enums\StaffRole::from($this->counts->query(E::Staff,$tenant->id)->where('user_id',$user->id)->firstOrFail()->role),'unitDetail' => $unitDetail, 'movements' => $movements, 'tenant' => $tenant, 'teeWideUser' => $user, 'count' => $count, 'product' => $product, 'option' => $option, 'options' => $product ? $this->counts->query(E::Option, $tenant->id)->where('product_id', $product->id)->orderBy('id')->get(['id', 'name']) : collect(), 'location' => $location, 'units' => $units, 'history' => $history, 'context' => ($request->input('list') ?? ''), 'back' => app(LiveProductWorkspace::class)->back(($request->input('list') ?? '')), 'portalUrl' => TeeWidePresenter::portalUrl(), 'liveUrl' => TeeWidePresenter::liveUrl()]);
    }

    public function store(LiveStockCountRequest $request)
    {
        $tenant = LiveContext::requireTenant()->id;
        $actor = LiveContext::user()->id;
        $d = $request->validated();
        $result = $request->route('action') === 'confirm' ? $this->counts->confirm($tenant, $actor, (int) $d['id'], (int) $d['revision'], $d['operation_key']) : $this->counts->draft($tenant, $actor, $d);
        $result['return_url'] = '/seller/product-stock?count='.$result['count_id'].'&list='.rawurlencode($d['list'] ?? '');

        return ResponseHelper::success('common.success', $result);
    }

    public function legacy(LiveStockCountRequest $request)
    {
        return redirect('/seller/product-stock'.($request->query() ? '?'.http_build_query($request->query()) : ''));
    }
}
