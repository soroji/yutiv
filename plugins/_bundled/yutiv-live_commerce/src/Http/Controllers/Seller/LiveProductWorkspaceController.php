<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller;

use App\Helpers\ResponseHelper;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Enums\StaffRole;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveProductActionRequest;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveProductWorkspaceRequest;
use Plugins\Yutiv\LiveCommerce\Services\LiveCatalogService;
use Plugins\Yutiv\LiveCommerce\Services\LiveProductWorkspace;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;

class LiveProductWorkspaceController
{
    public function __construct(private readonly LiveProductWorkspace $workspace) {}

    private function base(): array
    {
        $tenant = LiveContext::requireTenant();
        $user = LiveContext::user();
        $staff = $this->workspace->query(E::Staff, $tenant->id)->where('user_id', $user->id)->where('active', true)->firstOrFail();
        $role = StaffRole::from($staff->role);
        LiveContext::authorize($role->allows('catalog') ? 'catalog' : ($role->allows('inventory') ? 'inventory' : 'labels'));
        $menu = array_filter(LiveOfficeController::MENU, fn ($entry) => $role->allows($entry[1]));
        unset($menu['banks'],$menu['statistics'],$menu['ledger'],$menu['inventory'],$menu['options'],$menu['receive'],$menu['receipts'],$menu['product-receive'],$menu['labels']);
        if ($role->allows('statistics') && ! $role->allows('finance.read')) {
            unset($menu['accounting']);
            $menu['accounting/sales']=['매출·현재 주문 분석','statistics'];
        }
        $menu['products'] = ['상품관리', 'inventory'];

        return ['tenant' => $tenant, 'teeWideUser' => $user, 'role' => $role, 'menu' => $menu, 'section' => 'products', 'portalUrl' => TeeWidePresenter::portalUrl(), 'liveUrl' => TeeWidePresenter::liveUrl()];
    }

    public function index(LiveProductWorkspaceRequest $request)
    {
        $base = $this->base();
        $tenant = $base['tenant']->id;
        if ($request->integer('edit')) {
            return redirect('/seller/products/'.$request->integer('edit').'?list='.rawurlencode(http_build_query($request->only(LiveProductWorkspace::FILTERS))));
        }
        $rows = $this->workspace->products($tenant, $base['tenant']->timezone, $request->validated());
        $completed = $request->integer('completed') ? $this->workspace->query(E::Receipt, $tenant)->findOrFail($request->integer('completed')) : null;
        $purchase = $completed && $base['role']->allows('settlement') ? $this->workspace->query(E::ReceiptItem, $tenant)->where('receipt_id', $completed->id)->sum('purchase_amount') : null;

        return view('teewide::seller.products', array_merge($base, ['rows' => $rows, 'categories' => $this->workspace->query(E::Category, $tenant)->orderBy('name')->get(), 'context' => http_build_query($request->only(LiveProductWorkspace::FILTERS)), 'completed' => $completed, 'purchase' => $purchase]));
    }

    public function detail(LiveProductWorkspaceRequest $request, int $product)
    {
        $base = $this->base();
        $tenant = $base['tenant']->id;
        $row = $this->workspace->query(E::Product, $tenant)->findOrFail($product);
        $options = $this->workspace->query(E::Option, $tenant)->where('product_id', $product)->orderBy('id')->get();
        $states = $this->workspace->query(E::Unit, $tenant)->whereIn('option_id', $options->pluck('id'))->selectRaw('option_id,state,COUNT(*) quantity')->groupBy('option_id', 'state')->get()->groupBy('option_id');
        $units = $this->workspace->query(E::Unit, $tenant)->whereIn('option_id', $options->pluck('id'))->orderByDesc('id')->paginate(20, ['*'], 'units_page')->withQueryString();

        return view('teewide::seller.product-detail', array_merge($base, ['product' => $row, 'options' => $options, 'states' => $states, 'categories' => $this->workspace->query(E::Category, $tenant)->orderBy('name')->get(),
            'receipts' => $this->workspace->query(E::Receipt, $tenant)->where('product_id', $product)->orderByDesc('id')->paginate(10)->withQueryString(),
            'units' => $units,
            'costs' => $base['role']->allows('settlement') ? $this->workspace->query(E::ReceiptItem, $tenant)->whereIn('id', $units->pluck('receipt_item_id')->filter())->get()->keyBy('id') : collect(),
            'context' => ($request->input('list')??''), 'back' => $this->workspace->back(($request->input('list')??''))]));
    }

    public function receiveForm(LiveProductWorkspaceRequest $request)
    {
        LiveContext::authorize('inventory');
        $base = $this->base();
        $tenant = $base['tenant']->id;
        $source = $request->integer('product_id') ? $this->workspace->query(E::Product, $tenant)->findOrFail($request->integer('product_id')) : null;
        if (! $source && ! $base['role']->allows('catalog')) {
            return redirect('/seller/products');
        }
        if ($request->boolean('copy')) {
            LiveContext::authorize('catalog');
        }
        $options = $source ? $this->workspace->query(E::Option, $tenant)->where('product_id', $source->id)->orderBy('id')->get() : collect();
        $counts = $this->workspace->query(E::Unit, $tenant)->whereIn('option_id', $options->pluck('id'))->whereIn('state', ['available', 'reserved'])->selectRaw('option_id,state,COUNT(*) quantity')->groupBy('option_id', 'state')->get();
        foreach ($options as $option) {
            $option->available = (int) $counts->where('option_id', $option->id)->where('state', 'available')->sum('quantity');
            $option->reserved = (int) $counts->where('option_id', $option->id)->where('state', 'reserved')->sum('quantity');
        }

        return view('teewide::seller.product-intake', array_merge($base, ['source' => $source, 'options' => $options, 'copy' => $request->boolean('copy'), 'choices' => $this->workspace->products($tenant, $base['tenant']->timezone, $request->only(['search', 'page'])), 'categories' => $this->workspace->query(E::Category, $tenant)->where('active', true)->orderBy('name')->get(), 'context' => ($request->input('list')??''), 'back' => $this->workspace->back(($request->input('list')??''))]));
    }

    public function qr(LiveProductWorkspaceRequest $request)
    {
        LiveContext::authorize('labels');
        $base = $this->base();
        $tenant = $base['tenant']->id;
        $q = $this->workspace->query(E::Unit, $tenant);
        $original = null;
        if ($request->integer('legacy_audit_id')) {
            $q->whereIn('uuid', $this->workspace->legacyPrint($tenant, $request->integer('legacy_audit_id'))->data['units']);
        }
        if ($request->filled('products')) {
            $ids = $request->input('products');
            abort_unless($this->workspace->query(E::Product, $tenant)->whereIn('id', $ids)->count() === count($ids), 404);
            $q->whereIn('option_id', $this->workspace->query(E::Option, $tenant)->whereIn('product_id', $ids)->select('id'));
        }
        if ($request->integer('batch')) {
            $receipt = $this->workspace->query(E::Receipt, $tenant)->findOrFail($request->integer('batch'));
            $q->where('receipt_key', $receipt->receipt_key);
        }
        if ($request->filled('unit')) {
            $this->workspace->query(E::Unit, $tenant)->where('uuid', $request->input('unit'))->firstOrFail();
            $q->where('uuid', $request->input('unit'));
        }
        if ($request->integer('original_id')) {
            $original = $this->workspace->query(E::LabelRequest, $tenant)->findOrFail($request->integer('original_id'));
            $q->whereIn('uuid', array_column($original->items, 'uuid'));
        }
        if ($request->filled('search')) {
            $q->whereIn('option_id', $this->workspace->query(E::Option, $tenant)->whereIn('product_id', $this->workspace->query(E::Product, $tenant)->where(fn ($p) => $p->where('name', 'like', '%'.$request->input('search').'%')->orWhere('code', 'like', '%'.$request->input('search').'%'))->select('id'))->select('id'));
        }
        if ($request->filled('unit_state')) {
            $q->where('state', $request->input('unit_state'));
        }
        $units = $q->orderByDesc('id')->paginate(50)->withQueryString();
        $options = $this->workspace->query(E::Option, $tenant)->whereIn('id', $units->pluck('option_id'))->get()->keyBy('id');
        $products = $this->workspace->query(E::Product, $tenant)->whereIn('id', $options->pluck('product_id'))->get()->keyBy('id');

        return view('teewide::seller.product-qr', array_merge($base, ['units' => $units, 'options' => $options, 'products' => $products, 'original' => $original, 'recent' => $this->workspace->query(E::Receipt, $tenant)->orderByDesc('id')->paginate(10, ['*'], 'receipts_page')->withQueryString(), 'legacyHistory' => $this->workspace->query(E::Audit, $tenant)->where('action', 'labels.print_requested')->whereNotNull('data->units')->orderByDesc('id')->paginate(10, ['*'], 'legacy_page')->withQueryString(), 'history' => $this->workspace->query(E::LabelRequest, $tenant)->orderByDesc('id')->paginate(10, ['*'], 'history_page')->withQueryString(), 'context' => ($request->input('list')??''), 'back' => $this->workspace->back(($request->input('list')??''))]));
    }

    public function action(LiveProductActionRequest $request)
    {
        $tenant = LiveContext::requireTenant()->id;
        $actor = LiveContext::user()->id;
        $data = $request->validated();
        if ($request->route('action') === 'print') {
            return ResponseHelper::success('common.success', $this->workspace->printRequest($tenant, $actor, $data));
        }
        if ($request->route('action') === 'preview') {
            $base = $this->base();
            if (! empty($data['legacy_audit_id'])) {
                abort_if(array_diff($data['qrs'], $this->workspace->legacyPrint($tenant, (int) $data['legacy_audit_id'])->data['units']), 404);
            }
            $units = $this->workspace->query(E::Unit, $tenant)->whereIn('uuid', $data['qrs'])->orderBy('id')->get();
            abort_unless($units->count() === count($data['qrs']), 404);
            if (! empty($data['original_id'])) {
                $original = $this->workspace->query(E::LabelRequest, $tenant)->findOrFail($data['original_id']);
                abort_if(array_diff($data['qrs'], array_column($original->items, 'uuid')), 404);
            }
            $options = $this->workspace->query(E::Option, $tenant)->whereIn('id', $units->pluck('option_id'))->get()->keyBy('id');
            $products = $this->workspace->query(E::Product, $tenant)->whereIn('id', $options->pluck('product_id'))->get()->keyBy('id');

            return view('teewide::seller.product-label-preview', array_merge($base, compact('units', 'options', 'products', 'data')));
        }
        if ($request->route('action') === 'category') {
            $this->workspace->category($tenant, $actor, $data);
        }
        if ($request->route('action') === 'product') {
            $id = $data['id'];
            unset($data['id']);
            app(LiveCatalogService::class)->save(E::Product,$tenant,$actor,$data,$id);
        }

        return ResponseHelper::success('common.success',['saved' => true]);
    }

    public function legacyLabels(LiveProductWorkspaceRequest $request)
    {
        LiveContext::authorize('labels');

        return redirect('/seller/product-qr'.($request->query() ? '?'.http_build_query($request->query()) : ''));
    }

    public function legacyReceive(LiveProductWorkspaceRequest $request)
    {
        LiveContext::authorize('inventory');

        return redirect('/seller/products');
    }
}
