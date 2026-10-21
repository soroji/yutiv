<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers\Customer;

use Illuminate\Contracts\View\View;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;
use Plugins\Yutiv\LiveCommerce\Services\LiveOfficeService;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideAuth;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;

class LiveShopController
{
    public function __construct(private readonly LiveOfficeService $office) {}

    public function index(): View
    {
        return $this->page();
    }

    public function product(int $product): View
    {
        $tenant = LiveContext::requireTenant();
        $record = $this->office->query(E::Product, $tenant->id)->where('published', true)->findOrFail($product);

        return $this->page(null, null, $record->id);
    }

    public function broadcast(int $broadcast): View
    {
        return $this->page($broadcast);
    }

    public function saleLink(string $code): View
    {
        $tenant = LiveContext::requireTenant();
        $link = $this->office->query(E::SaleLink, $tenant->id)->where('active', true)->where('code', $code)->firstOrFail();
        abort_if($link->expires_at && $link->expires_at <= now(), 404);

        return $this->page($link->broadcast_id, $link);
    }

    private function page(?int $selected = null, ?LiveRecord $link = null, ?int $product = null): View
    {
        $tenant = LiveContext::requireTenant();
        $query = $this->office->query(E::Broadcast, $tenant->id)->where('state', 'selling')->where('sale_starts_at', '<=', now())->where('sale_ends_at', '>', now());
        if ($selected) {
            $query->whereKey($selected);
        }
        $broadcasts = $query->orderBy('sale_starts_at')->get();
        if ($selected) {
            abort_if($broadcasts->isEmpty(), 404);
        }
        $cards = [];
        foreach ($broadcasts as $broadcast) {
            $items = $this->office->query(E::BroadcastItem, $tenant->id)->where('broadcast_id', $broadcast->id)->where('active', true)->orderBy('position')->orderBy('id')->get();
            foreach ($items as $item) {
                if ($link?->option_id && $item->option_id !== $link->option_id) {
                    continue;
                }
                $option = $this->office->query(E::Option, $tenant->id)->where('active', true)->find($item->option_id);
                if (! $option || ($product && $option->product_id !== $product)) {
                    continue;
                }
                $p = $this->office->query(E::Product, $tenant->id)->where('published', true)->where('sellable', true)->find($option->product_id);
                if ($p) {
                    $available=$this->office->query(E::Unit,$tenant->id)->where('option_id',$option->id)->where('state','available')->count();
                    $cards[] = ['broadcast' => $broadcast, 'option' => $option, 'product' => $p,'available'=>$available];
                }
            }
        }

        return view('teewide::customer.shop', ['cards' => $cards, 'broadcasts' => $broadcasts, 'saleCode' => $link?->code, 'tenant' => $tenant,
            'portalUrl' => TeeWidePresenter::portalUrl(), 'liveUrl' => TeeWidePresenter::liveUrl(), 'teeWideUser' => TeeWideAuth::user()]);
    }
}
