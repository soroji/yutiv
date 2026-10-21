<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Support\Query\PaginationLimits;
use Carbon\CarbonImmutable as Date;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;

/** 방송 목록·상세는 기존 원장과 공유 상품을 조회하며 별도 상품을 만들지 않습니다. */
class LiveBroadcastWorkspace extends LiveWorkflow
{
    use PaginatesWithDeferredJoin;

    public function queryRecord(E $entity, int $tenant, int $id)
    {
        return $this->row($entity, $tenant, $id);
    }

    public function queryRecordByUser(int $tenant, int $user)
    {
        return $this->records->query(E::Staff, $tenant)->where('user_id', $user)->firstOrFail();
    }

    public function listing(int $tenant, array $filters): array
    {
        $zone = LiveTenant::findOrFail($tenant)->timezone;
        $q = $this->records->query(E::Broadcast, $tenant);
        if (! empty($filters['search'])) {
            $q->where('name', 'like', '%'.addcslashes($filters['search'], '%_\\').'%');
        }
        if (! empty($filters['channel_id'])) {
            $q->where('channel_id', $filters['channel_id']);
        }
        if (! empty($filters['from'])) {
            $q->where('starts_at', '>=', Date::parse($filters['from'], $zone)->utc());
        }
        if (! empty($filters['to'])) {
            $q->where('starts_at', '<', Date::parse($filters['to'], $zone)->addDay()->utc());
        }
        $state = $filters['state'] ?? '';
        if (in_array($state, ['draft', 'closed'], true)) {
            $q->where('state', $state);
        }
        if ($state === 'scheduled') {
            $q->where('state', 'selling')->where('sale_starts_at', '>', now());
        }
        if ($state === 'selling') {
            $q->where('state', 'selling')->where('sale_starts_at', '<=', now())->where('sale_ends_at', '>', now());
        }
        if ($state === 'expired') {
            $q->where('state', 'selling')->where('sale_ends_at', '<=', now());
        }
        $rows = $this->paginateWithDeferredJoin($q, ['id', 'name', 'channel_id', 'starts_at', 'ends_at', 'sale_starts_at', 'sale_ends_at', 'host_shares', 'state'], [['column' => 'starts_at', 'direction' => 'desc'], ['column' => 'id', 'direction' => 'desc']], 20, $filters['page'] ?? null, resultCap: PaginationLimits::resultCap('live.broadcasts'))->withQueryString();
        $counts = $this->records->query(E::BroadcastItem, $tenant)->whereIn('broadcast_id', $rows->pluck('id'))->selectRaw('broadcast_id, COUNT(*) quantity')->groupBy('broadcast_id')->pluck('quantity', 'broadcast_id');

        return compact('rows', 'counts') + $this->choices($tenant);
    }

    public function choices(int $tenant): array
    {
        return ['channels' => $this->records->query(E::Channel, $tenant)->orderBy('name')->orderBy('id')->get(),
            'hosts' => $this->records->query(E::Showhost, $tenant)->orderBy('name')->orderBy('id')->get(),
            'banks' => $this->records->query(E::Bank, $tenant)->orderByDesc('default')->orderBy('id')->get(),
            'categories' => $this->records->query(E::Category, $tenant)->orderBy('name')->orderBy('id')->get()];
    }

    public function detail(int $tenant, int $id, array $filters): array
    {
        $editing = $this->row(E::Broadcast, $tenant, $id);
        $selected = $this->paginateWithDeferredJoin($this->records->query(E::BroadcastItem, $tenant)->where('broadcast_id', $id), ['id', 'broadcast_id', 'option_id', 'position', 'active', 'channel_rate', 'host_rate'], [['column' => 'position', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']], 20, $filters['items_page'] ?? null, pageName: 'items_page', resultCap: PaginationLimits::resultCap('live.broadcast-items'))->withQueryString();
        $options = $this->records->query(E::Option, $tenant)->whereIn('id', $selected->pluck('option_id'))->get()->keyBy('id');
        $products = $this->records->query(E::Product, $tenant)->whereIn('id', $options->pluck('product_id'))->get()->keyBy('id');
        $stock = $this->records->query(E::Unit, $tenant)->where('state', 'available')->whereIn('option_id', $options->keys())->selectRaw('option_id, COUNT(*) quantity')->groupBy('option_id')->pluck('quantity', 'option_id');
        $pq = $this->records->query(E::Product, $tenant);
        if (! empty($filters['product_search'])) {
            $pq->where(fn ($q) => $q->where('name', 'like', '%'.addcslashes($filters['product_search'], '%_\\').'%')->orWhere('code', 'like', '%'.addcslashes($filters['product_search'], '%_\\').'%'));
        }
        if (! empty($filters['category_id'])) {
            $pq->where('category_id', $filters['category_id']);
        }
        $oq = $this->records->query(E::Option, $tenant)->whereIn('product_id', $pq->select('id'));
        if (($filters['stock'] ?? '') === 'yes') {
            $oq->whereIn('id', $this->records->query(E::Unit, $tenant)->where('state', 'available')->select('option_id'));
        }
        $candidates = $this->paginateWithDeferredJoin($oq, ['id', 'product_id', 'name', 'price', 'discount', 'active'], [['column' => 'product_id', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']], 20, $filters['candidates_page'] ?? null, pageName: 'candidates_page', resultCap: PaginationLimits::resultCap('live.broadcast-candidates'))->withQueryString();
        $candidateProducts = $this->records->query(E::Product, $tenant)->whereIn('id', $candidates->pluck('product_id'))->get()->keyBy('id');
        $candidateStock = $this->records->query(E::Unit, $tenant)->where('state', 'available')->whereIn('option_id', $candidates->pluck('id'))->selectRaw('option_id, COUNT(*) quantity')->groupBy('option_id')->pluck('quantity', 'option_id');
        $candidateItems = $this->records->query(E::BroadcastItem, $tenant)->where('broadcast_id', $id)->whereIn('option_id', $candidates->pluck('id'))->get()->keyBy('option_id');
        $sales = $this->records->query(E::OrderLine, $tenant)->where('snapshot->broadcast_id', $id)->selectRaw('COUNT(*) line_count, COALESCE(SUM(quantity),0) quantity, COALESCE(SUM(shipped),0) shipped')->first();

        return compact('editing', 'selected', 'options', 'products', 'stock', 'candidates', 'candidateProducts', 'candidateStock', 'candidateItems', 'sales') + $this->choices($tenant);
    }

    public function context(string $list): string
    {
        parse_str($list, $q);

        return http_build_query(array_filter(array_intersect_key($q, array_flip(['search', 'channel_id', 'from', 'to', 'state', 'page'])), fn ($v) => is_scalar($v)));
    }

    public function back(string $list): string
    {
        $query = $this->context($list);

        return '/seller/broadcasts'.($query ? '?'.$query : '');
    }
}
