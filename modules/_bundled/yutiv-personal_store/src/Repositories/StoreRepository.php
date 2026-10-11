<?php

namespace Modules\Yutiv\PersonalStore\Repositories;

use App\Enums\PermissionType;
use App\Models\User;
use App\Search\KeywordSearch;
use App\Support\Query\BoundedPage;
use App\Support\Query\BoundedPaginator;
use App\Support\Query\PaginationLimits;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductListResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\PublicProductResource;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Support\CurrencySettingsCache;
use Modules\Yutiv\PersonalStore\DTO\OperatorOrder;
use Modules\Yutiv\PersonalStore\Enums\OutboxType;
use Modules\Yutiv\PersonalStore\Enums\RewardLedgerKind;
use Modules\Yutiv\PersonalStore\Exceptions\StorePolicyException;
use Modules\Yutiv\PersonalStore\Models\LineSnapshot;
use Modules\Yutiv\PersonalStore\Models\Store;
use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;
use Modules\Yutiv\PersonalStore\Services\RewardCalculator;

class StoreRepository implements StoreRepositoryInterface
{
    private function paginate($query, array $columns, string $pageName, int $page): BoundedPage
    {
        return BoundedPaginator::paginate($query, perPage: 20, page: $page, resultCap: PaginationLimits::resultCap('yutiv-personal_store'), columns: $columns, pageName: $pageName);
    }

    public function categories(int $actorId, array $filters): array
    {
        if (! Store::where('user_id', $actorId)->exists()) {
            return ['data' => [], 'page' => 1, 'total' => 0, 'total_relation' => 'exact', 'has_more' => false, 'last_page' => 1];
        }
        $query = Category::where('is_active', true);
        if (! empty($filters['search'])) {
            KeywordSearch::apply($query, 'name', $filters['search']);
        }
        $page = $this->paginate($query->orderBy('id'), ['id', 'name', 'parent_id'], 'page', $filters['page'] ?? 1);

        return ['data' => $page->map(fn ($category) => ['id' => $category->id, 'name' => $category->getLocalizedName(), 'parent_id' => $category->parent_id])->all(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'total_relation' => $page->totalRelation()->value, 'has_more' => $page->hasMorePages()];
    }

    public function headquarters(int $actorId, array $filters): array
    {
        $user = User::findOrFail($actorId);
        abort_unless($user->isSuperAdmin() || $user->hasPermission('yutiv-personal_store.headquarters.manage', PermissionType::Admin), 403);
        $page = $this->paginate(DB::table('personal_store_applications')->where('status', 'pending')->orderBy('id'), ['id', 'store_id', 'parent_id', 'kind', 'status', 'created_at'], 'page', $filters['page'] ?? 1);

        $history = $this->paginate(DB::table('personal_store_history')->orderByDesc('id'), ['id', 'store_id', 'kind', 'revision', 'payload', 'created_at'], 'history_page', $filters['history_page'] ?? 1);
        $rates = $this->paginate(DB::table('personal_store_product_rates')->orderBy('product_id'), ['product_id', 'rate_bps'], 'rates_page', $filters['rates_page'] ?? 1);
        $stores = $this->paginate(DB::table('personal_stores')->orderBy('id'), ['id', 'slug', 'parent_id', 'status', 'created_at'], 'stores_page', $filters['stores_page'] ?? 1);

        return ['stores' => $stores->items(), 'stores_page' => $stores->currentPage(), 'stores_has_more' => $stores->hasMorePages(), 'history' => $history->items(), 'history_page' => $history->currentPage(), 'history_has_more' => $history->hasMorePages(), 'rates' => $rates->items(), 'rates_page' => $rates->currentPage(), 'rates_has_more' => $rates->hasMorePages(), 'applications' => $page->items(), 'total' => $page->total(), 'total_relation' => $page->totalRelation()->value, 'has_more' => $page->hasMorePages(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'review_count' => DB::table('personal_store_line_states')->where('review_required', true)->count(), 'pending_outbox_count' => DB::table('personal_store_outbox')->whereNull('processed_at')->count()];
    }

    public function catalog(int $actorId, array $filters): array
    {
        return DB::transaction(function () use ($actorId, $filters) {
            $this->lockRevision();
            $store = Store::where('user_id', $actorId)->where('status', 'active')->first();
            if ($store === null) {
                return ['data' => [], 'page' => 1, 'total' => 0, 'total_relation' => 'exact', 'has_more' => false, 'last_page' => 1];
            }
            $path = $this->path($store->id);
            $query = Product::query()->where('display_status', 'visible')->where('sales_status', 'on_sale');
            if (! empty($filters['search'])) {
                KeywordSearch::apply($query, 'name', $filters['search']);
            }
            $page = $this->paginate($query->orderByDesc('id'), ['id', 'product_code', 'name', 'selling_price', 'list_price', 'tax_status', 'sales_status', 'display_status'], 'page', $filters['page'] ?? 1);
            $items = [];
            foreach ($page as $product) {
                $rate = $this->productRate($product->id);
                $currencies = CurrencySettingsCache::currencies();
                $baseCurrency = collect($currencies)->firstWhere('is_default', true)['code'] ?? null;
                $price = (new ProductListResource($product))->resolve(request());
                $calculation = (new RewardCalculator)->allocate($baseCurrency === 'KRW' ? (int) $product->selling_price : 0, $rate, $path);
                $own = end($calculation['allocations']);
                $effective = (new RewardCalculator)->allocate(1000000, $rate, $path);
                $items[] = ['id' => $product->id, 'product_code' => $product->product_code, 'name' => $product->getLocalizedName(), 'selling_price' => $product->selling_price, 'selling_price_formatted' => $price['selling_price_formatted'], 'multi_currency_selling_price' => $price['multi_currency_selling_price'], 'reward_currency_eligible' => $baseCurrency === 'KRW', 'base_rate_bps' => $rate, 'effective_rate_per_million' => end($effective['allocations'])['amount'], 'estimated_points' => $own['amount'], 'selected' => $this->allowed($store->id, $product->id)];
            }

            return ['data' => $items, 'page' => $page->currentPage(), 'total' => $page->total(), 'total_relation' => $page->totalRelation()->value, 'has_more' => $page->hasMorePages(), 'last_page' => $page->lastPage()];
        });
    }

    public function registerMembership(int $userId, string $reference, ?string $proofHash = null): void
    {
        $this->lockRevision();
        $storeId = $this->validateMembershipReference($reference);
        if ($proofHash !== null && DB::table('personal_store_memberships')->where('proof_hash', $proofHash)->exists()) {
            throw new StorePolicyException(__('yutiv-personal_store::messages.invalid_proof'));
        }
        DB::table('personal_store_memberships')->insert(['user_id' => $userId, 'store_id' => $storeId, 'proof_id' => $reference, 'proof_hash' => $proofHash, 'created_at' => now()]);
    }

    public function validateMembershipReference(string $reference): int
    {
        $proof = DB::table('personal_store_attributions')->where('id', $reference)->where('expires_at', '>', now())->first();
        if (! $proof) {
            throw new StorePolicyException(__('yutiv-personal_store::messages.invalid_proof'));
        }
        $this->path((int) $proof->store_id);

        return (int) $proof->store_id;
    }

    public function manage(int $actorId, string $action, array $data): array
    {
        return DB::transaction(function () use ($actorId, $action, $data) {
            $revision = $this->lockRevision();
            $actor = User::findOrFail($actorId);
            $hq = $actor->isSuperAdmin() || $actor->hasPermission('yutiv-personal_store.headquarters.manage', PermissionType::Admin);
            $store = Store::where('user_id', $actorId)->first();
            if (in_array($action, ['approve', 'product_rate', 'status'], true)) {
                abort_unless($hq, 403);
            } elseif ($action !== 'apply') {
                abort_unless($store !== null, 403);
            }
            switch ($action) {
                case 'apply':
                    abort_if($store !== null, 409);
                    $parent = DB::table('personal_store_memberships')->where('user_id', $actorId)->value('store_id');
                    if ($parent !== null) {
                        $this->path((int) $parent);
                    }
                    $store = Store::create(['user_id' => $actorId, 'parent_id' => $parent, 'slug' => $data['slug'], 'status' => $parent === null ? 'pending' : 'active']);
                    DB::table('personal_store_wallets')->insert(['store_id' => $store->id]);
                    DB::table('personal_store_closure')->insert(['ancestor_id' => $store->id, 'descendant_id' => $store->id, 'depth' => 0]);
                    if ($parent !== null) {
                        foreach (DB::table('personal_store_closure')->where('descendant_id', $parent)->get() as $ancestor) {
                            DB::table('personal_store_closure')->insert(['ancestor_id' => $ancestor->ancestor_id, 'descendant_id' => $store->id, 'depth' => (int) $ancestor->depth + 1]);
                        }
                        DB::table('personal_store_allocations')->insert(['parent_id' => $parent, 'child_id' => $store->id, 'rate_bps' => 0]);
                    } else {
                        DB::table('personal_store_applications')->insert(['store_id' => $store->id, 'kind' => 'open', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                    }
                    break;
                case 'profile':
                    $store->update(array_intersect_key($data, array_flip(['slug', 'introduction', 'logo_url', 'banner_url'])));
                    break;
                case 'products':
                    DB::table('personal_store_products')->updateOrInsert(['store_id' => $store->id, 'product_id' => $data['product_id']], ['excluded' => $data['excluded']]);
                    break;
                case 'categories':
                    DB::table('personal_store_categories')->where('store_id', $store->id)->delete();
                    foreach ($data['category_ids'] as $id) {
                        DB::table('personal_store_categories')->insert(['store_id' => $store->id, 'category_id' => $id]);
                    }
                    break;
                case 'allocation':
                    $child = Store::where('parent_id', $store->id)->where('id', $data['child_id'])->firstOrFail();
                    DB::table('personal_store_allocations')->where('child_id', $child->id)->where('parent_id', $store->id)->update(['rate_bps' => $data['rate_bps']]);
                    break;
                case 'request_move':
                    DB::table('personal_store_applications')->insert(['store_id' => $store->id, 'parent_id' => $data['parent_id'] ?? null, 'kind' => 'move', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                    break;
                case 'approve':
                    $application = DB::table('personal_store_applications')->where('id', $data['application_id'])->lockForUpdate()->first();
                    abort_unless($application && $application->status === 'pending', 409);
                    if ($application->kind === 'move') {
                        $this->move((int) $application->store_id, $application->parent_id === null ? null : (int) $application->parent_id, $actorId);
                    }
                    Store::where('id', $application->store_id)->update(['status' => 'active']);
                    DB::table('personal_store_applications')->where('id', $application->id)->update(['status' => 'approved', 'approved_by' => $actorId, 'updated_at' => now()]);
                    break;
                case 'product_rate':
                    DB::table('personal_store_product_rates')->updateOrInsert(['product_id' => $data['product_id']], ['rate_bps' => $data['rate_bps']]);
                    break;
                case 'status':
                    Store::where('id', $data['store_id'])->update(['status' => $data['status']]);
                    break;
                default:
                    abort(422);
            }
            $nextRevision = (int) DB::table('personal_store_revisions')->where('id', 1)->value('revision') + 1;
            DB::table('personal_store_revisions')->where('id', 1)->increment('revision');
            DB::table('personal_store_history')->insert(['store_id' => $store?->id, 'actor_id' => $actorId, 'kind' => $action, 'revision' => $nextRevision, 'payload' => json_encode(array_diff_key($data, array_flip(['logo_url', 'banner_url', 'introduction'])), JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return ['store' => $store ? $this->storeView($store) : null];
        }, 3);
    }

    private function storeView(Store $store): array
    {
        return ['id' => $store->id, 'slug' => $store->slug, 'status' => $store->status->value, 'logo_url' => $store->logo_url, 'banner_url' => $store->banner_url, 'introduction' => $store->introduction];
    }

    public function dashboard(int $actorId, array $filters): array
    {
        $store = Store::where('user_id', $actorId)->first();
        if (! $store) {
            return ['store' => null, 'orders' => [], 'wallet' => ['available' => 0, 'debt' => 0]];
        }
        $query = LineSnapshot::where('store_id', $store->id);
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString().' 00:00:00');
        }
        $page = $this->paginate($query->orderByDesc('id'), ['id', 'order_id', 'payload', 'created_at'], 'page', $filters['page'] ?? 1);
        $orders = [];
        foreach ($page as $line) {
            $state = DB::table('personal_store_line_states')->where('snapshot_id', $line->id)->first();
            $orders[] = (new OperatorOrder(
                // 회원 정보와 원본 주문 번호를 제외한 스토어 범위 참조입니다.
                reference: substr(hash_hmac('sha256', $line->order_id.':'.$store->id, config('app.key')), 0, 16),
                orderedAt: $line->created_at->toIso8601String(),
                product: $line->payload['product_name'],
                quantity: $line->payload['quantity'],
                paymentAmount: max(0, $line->payload['eligible_base'] - $line->payload['points_used_share']),
                currency: $line->payload['currency'],
                reward: DB::table('personal_store_beneficiaries')->where('snapshot_id', $line->id)->where('store_id', $store->id)->value('amount') ?? 0,
                orderStatus: (string) DB::table('ecommerce_orders')->where('id', $line->order_id)->value('order_status'),
                pointStatus: $state->review_required ? 'review_required' : ($state->cancelled_base > 0 ? 'recovered' : ($state->confirmed_base > 0 ? 'confirmed' : 'pending')),
            ))->toArray();
        }
        $wallet = DB::table('personal_store_wallets')->where('store_id', $store->id)->first(['available', 'debt']);
        $children = $this->paginate(Store::where('parent_id', $store->id)->orderBy('id'), ['id', 'slug', 'status'], 'children_page', $filters['children_page'] ?? 1);
        $statistics = $this->paginate((clone $query)->reorder()->limit(null)->offset(null)->select('product_id')->selectRaw('SUM(eligible_base) as eligible_base, SUM(quantity) as quantity')->groupBy('product_id')->orderBy('product_id'), ['*'], 'statistics_page', $filters['statistics_page'] ?? 1);
        $ledger = $this->paginate(DB::table('personal_store_ledger')->where('store_id', $store->id)->orderByDesc('id'), ['id', 'kind', 'amount', 'available_delta', 'debt_delta', 'created_at'], 'ledger_page', $filters['ledger_page'] ?? 1);

        return ['store' => $this->storeView($store), 'orders' => $orders, 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'total_relation' => $page->totalRelation()->value, 'has_more' => $page->hasMorePages(), 'children' => $children->map(fn ($child) => $this->storeView($child) + ['rate_bps' => (int) DB::table('personal_store_allocations')->where('parent_id', $store->id)->where('child_id', $child->id)->value('rate_bps')])->all(), 'children_total' => $children->total(), 'children_has_more' => $children->hasMorePages(), 'children_total_relation' => $children->totalRelation()->value, 'children_page' => $children->currentPage(), 'children_last_page' => $children->lastPage(), 'selected_category_ids' => DB::table('personal_store_categories')->where('store_id', $store->id)->pluck('category_id')->all(), 'descendant_sales' => (int) LineSnapshot::whereIn('store_id', DB::table('personal_store_closure')->select('descendant_id')->where('ancestor_id', $store->id)->where('depth', '>', 0))->when(! empty($filters['from']), fn ($q) => $q->where('created_at', '>=', $filters['from'].' 00:00:00'))->when(! empty($filters['to']), fn ($q) => $q->where('created_at', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString().' 00:00:00'))->sum('eligible_base'), 'descendant_count' => DB::table('personal_store_closure')->where('ancestor_id', $store->id)->where('depth', '>', 0)->count(), 'wallet' => (array) $wallet, 'statistics' => $statistics->items(), 'statistics_page' => $statistics->currentPage(), 'statistics_has_more' => $statistics->hasMorePages(), 'ledger' => $ledger->items(), 'ledger_page' => $ledger->currentPage(), 'ledger_total' => $ledger->total(), 'ledger_has_more' => $ledger->hasMorePages(), 'ledger_total_relation' => $ledger->totalRelation()->value, 'ledger_last_page' => $ledger->lastPage()];
    }

    public function publicStore(string $slug): array
    {
        $store = Store::where('slug', $slug)->where('status', 'active')->firstOrFail();

        return $this->storeView($store);
    }

    public function publicOrigin(string $slug): string
    {
        return DB::transaction(function () use ($slug) {
            $this->lockRevision();
            $store = Store::where('slug', $slug)->where('status', 'active')->firstOrFail();
            $id = DB::table('personal_store_attributions')->where('store_id', $store->id)->value('id') ?? (string) Str::uuid();
            DB::table('personal_store_attributions')->updateOrInsert(['id' => $id], ['store_id' => $store->id, 'expires_at' => now()->addDay(), 'created_at' => now()]);

            return $id;
        });
    }

    public function products(string $slug, array $filters): array
    {
        $store = Store::where('slug', $slug)->where('status', 'active')->firstOrFail();
        $query = $this->selectedProductQuery($store->id);
        if (! empty($filters['search'])) {
            KeywordSearch::apply($query, 'name', $filters['search']);
        }
        $page = $this->paginate($query->orderByDesc('id'), ['id', 'product_code', 'name', 'selling_price', 'list_price', 'display_status', 'sales_status', 'tax_status'], 'page', $filters['page'] ?? 1);
        $items = [];
        foreach ($page as $product) {
            $price = (new ProductListResource($product))->resolve(request());
            $items[] = ['id' => $product->id, 'product_code' => $product->product_code, 'name' => $product->getLocalizedName(), 'selling_price' => $product->selling_price, 'selling_price_formatted' => $price['selling_price_formatted'], 'multi_currency_selling_price' => $price['multi_currency_selling_price']];
        }

        return ['data' => $items, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'total_relation' => $page->totalRelation()->value, 'has_more' => $page->hasMorePages()];
    }

    private function selectedProductQuery(int $storeId): Builder
    {
        $categoryIds = [];
        foreach (DB::table('personal_store_categories')->where('store_id', $storeId)->pluck('category_id') as $categoryId) {
            $categoryIds = array_merge($categoryIds, Category::selfAndDescendantIds((int) $categoryId));
        }
        $include = DB::table('personal_store_products')->select('product_id')->where('store_id', $storeId)->where('excluded', false);
        $exclude = DB::table('personal_store_products')->select('product_id')->where('store_id', $storeId)->where('excluded', true);

        return Product::query()->where('display_status', 'visible')->where('sales_status', 'on_sale')->whereNotIn('id', $exclude)->where(function ($query) use ($include, $categoryIds) {
            $query->whereIn('id', $include)->orWhereHas('categories', fn ($category) => $category->whereIn('ecommerce_product_categories.category_id', array_unique($categoryIds)));
        });
    }

    public function detail(string $slug, string $code): array
    {
        $store = Store::where('slug', $slug)->where('status', 'active')->firstOrFail();
        $product = $this->selectedProductQuery($store->id)->where('product_code', $code)->firstOrFail();
        $product->load(['activeOptions', 'images', 'categories', 'additionalOptions.activeValues', 'shippingPolicy.countrySettings', 'notice', 'commonInfo', 'brand', 'activeLabelAssignments.label']);

        return (new PublicProductResource($product))->resolve(request());
    }

    public function attribution(?string $key): ?int
    {
        return $key === null ? null : DB::table('personal_store_attributions')->where('id', $key)->where('expires_at', '>', now())->value('store_id');
    }

    public function productRate(int $productId): int
    {
        return (int) (DB::table('personal_store_product_rates')->where('product_id', $productId)->value('rate_bps') ?? 0);
    }

    /** 현 상태를 불변 outbox로 캡처합니다. 최초 주문의 금액·계보는 재조회하지 않습니다. */
    public function captureOrderState(int $orderId): void
    {
        if (! LineSnapshot::where('order_id', $orderId)->exists()) {
            return;
        }
        DB::transaction(function () use ($orderId) {
            // 주문 상태 쓰기와 같은 잠금 순서: 주문 → 옵션 → 리워드 상태 → 지갑 ID 오름차순.
            DB::table('ecommerce_orders')->where('id', $orderId)->lockForUpdate()->first();
            $payment = DB::table('ecommerce_order_payments')->where('order_id', $orderId)->first();
            foreach (LineSnapshot::where('order_id', $orderId)->orderBy('id')->cursor() as $snapshot) {
                $options = DB::table('ecommerce_order_options')->where('order_id', $orderId)->where('line_uuid', $snapshot->line_uuid)->orderBy('id')->lockForUpdate()->get(['quantity', 'confirmed_at', 'option_status']);
                $confirmed = $cancelledConfirmed = 0;
                foreach ($options as $option) {
                    if ($option->confirmed_at !== null) {
                        $confirmed += (int) $option->quantity;
                        if ($option->option_status === 'cancelled') {
                            $cancelledConfirmed += (int) $option->quantity;
                        }
                    }
                }
                // 승인된 수동 환불은 완료가 아닙니다. 금액 전용/진행중 환불은 검토에 둡니다.
                $review = DB::table('ecommerce_order_refunds')->where('order_id', $orderId)->where(function ($query) {
                    $query->whereNotIn('refund_status', ['completed', 'rejected'])
                        ->orWhere(fn ($q) => $q->where('refund_status', 'completed')->whereNull('order_cancel_id'));
                })->exists();
                if (! $payment || $payment->paid_at === null) {
                    $confirmed = 0;
                    $cancelledConfirmed = 0;
                }
                $quantity = (int) $snapshot->payload['quantity'];
                if ($confirmed > $quantity || $cancelledConfirmed > $confirmed) {
                    $review = true;
                }
                $payload = ['snapshot_id' => $snapshot->id, 'confirmed_quantity' => min($quantity, $confirmed), 'cancelled_confirmed_quantity' => min($quantity, $cancelledConfirmed), 'review_required' => $review];
                $state = DB::table('personal_store_line_states')->where('snapshot_id', $snapshot->id)->lockForUpdate()->first();
                $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                if ($state->capture_hash === $hash && DB::table('personal_store_outbox')->where('event_key', 'state:'.$snapshot->line_uuid.':'.$state->capture_version)->exists()) {
                    continue;
                }
                $version = $state->capture_hash === $hash ? (int) $state->capture_version : $this->checkedAdd((int) $state->capture_version, 1);
                DB::table('personal_store_line_states')->where('snapshot_id', $snapshot->id)->update(['capture_hash' => $hash, 'capture_version' => $version]);
                $key = 'state:'.$snapshot->line_uuid.':'.$version;
                $this->outbox($key, 'state', $payload);
                if ($review) {
                    $reviewEventId = DB::table('personal_store_outbox')->where('event_key', $key)->value('id');
                    DB::table('personal_store_line_states')->where('snapshot_id', $snapshot->id)->update(['review_required' => true, 'last_event_id' => $reviewEventId]);
                }
            }
        }, 3);
    }

    /** 재시도/역순 전달은 누적 목표와 append-only 원장 차액으로 수렴합니다. */
    public function processOutbox(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $event = DB::table('personal_store_outbox')->where('id', $id)->lockForUpdate()->first();
            if (! $event || $event->processed_at !== null) {
                return false;
            }
            $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);
            if ($event->type === 'state') {
                $snapshot = LineSnapshot::findOrFail($payload['snapshot_id']);
                $state = DB::table('personal_store_line_states')->where('snapshot_id', $snapshot->id)->lockForUpdate()->first();
                if ($state->review_required && $event->id < $state->last_event_id) {
                    DB::table('personal_store_outbox')->where('id', $id)->update(['processed_at' => now()]);

                    return true;
                }
                if ($payload['review_required']) {
                    if ($event->id > $state->last_event_id) {
                        DB::table('personal_store_line_states')->where('snapshot_id', $snapshot->id)->update(['review_required' => true, 'last_event_id' => $event->id]);
                    }
                    DB::table('personal_store_outbox')->where('id', $id)->update(['processed_at' => now()]);

                    return true;
                }
                $quantity = (int) $snapshot->payload['quantity'];
                $confirmed = max((int) $state->confirmed_base, (int) $payload['confirmed_quantity']);
                $cancelled = max((int) $state->cancelled_base, (int) $payload['cancelled_confirmed_quantity']);
                $beneficiaries = DB::table('personal_store_beneficiaries')->where('snapshot_id', $snapshot->id)->orderBy('store_id')->get();
                $calculator = new RewardCalculator;
                foreach ($beneficiaries as $beneficiary) {
                    $storeId = (int) $beneficiary->store_id;
                    $wallet = DB::table('personal_store_wallets')->where('store_id', $storeId)->lockForUpdate()->first();
                    if (! $wallet) {
                        throw new \LogicException(__('yutiv-personal_store::messages.invalid_path'));
                    }
                    $earnTarget = $calculator->floorRatio((int) $beneficiary->amount, $confirmed, $quantity);
                    $recoverTarget = $calculator->floorRatio((int) $beneficiary->amount, $cancelled, $quantity);
                    $earned = (int) DB::table('personal_store_ledger')->where('snapshot_id', $snapshot->id)->where('store_id', $storeId)->where('kind', 'earn')->sum('amount');
                    $recovered = (int) DB::table('personal_store_ledger')->where('snapshot_id', $snapshot->id)->where('store_id', $storeId)->where('kind', 'recover')->sum('amount');
                    $available = (int) $wallet->available;
                    $debt = (int) $wallet->debt;
                    // 신규 확정과 동일 이벤트 취소는 먼저 netting. 이미 지급된 부분만 회수합니다.
                    $earnDelta = max(0, $earnTarget - $earned);
                    $recoverDelta = max(0, $recoverTarget - $recovered);
                    if ($earnDelta > 0) {
                        $this->ledger('earn:'.$snapshot->id.':'.$storeId.':'.$earnTarget, $storeId, $snapshot->id, 'earn', $earnDelta, $earnDelta, 0);
                        $available = $this->checkedAdd($available, $earnDelta);
                    }
                    if ($recoverDelta > 0) {
                        $taken = min($available, $recoverDelta);
                        $shortfall = $recoverDelta - $taken;
                        $this->ledger('recover:'.$snapshot->id.':'.$storeId.':'.$recoverTarget, $storeId, $snapshot->id, 'recover', $recoverDelta, -$taken, 0);
                        $available -= $taken;
                        if ($shortfall > 0) {
                            $this->ledger('shortfall:'.$snapshot->id.':'.$storeId.':'.$recoverTarget, $storeId, $snapshot->id, 'shortfall', $shortfall, 0, $shortfall);
                            $debt = $this->checkedAdd($debt, $shortfall);
                        }
                    }
                    $offset = min($available, $debt);
                    if ($offset > 0 && $earnDelta > 0) {
                        $this->ledger('offset:'.$snapshot->id.':'.$storeId.':'.$earnTarget, $storeId, $snapshot->id, 'offset', $offset, -$offset, -$offset);
                        $available -= $offset;
                        $debt -= $offset;
                    }
                    DB::table('personal_store_wallets')->where('store_id', $storeId)->update(['available' => $available, 'debt' => $debt]);
                }
                $update = ['confirmed_base' => $confirmed, 'cancelled_base' => $cancelled];
                if ($event->id > $state->last_event_id) {
                    $update += ['review_required' => false, 'last_event_id' => $event->id];
                }
                DB::table('personal_store_line_states')->where('snapshot_id', $snapshot->id)->update($update);
            }
            DB::table('personal_store_outbox')->where('id', $id)->update(['processed_at' => now()]);

            return true;
        }, 3);
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($left > PHP_INT_MAX - $right) {
            throw new \OverflowException(__('yutiv-personal_store::messages.invalid_ratio'));
        }

        return $left + $right;
    }

    private function ledger(string $key, int $storeId, int $snapshotId, string $kind, int $amount, int $available, int $debt): void
    {
        $kind = RewardLedgerKind::from($kind)->value;
        DB::table('personal_store_ledger')->insert(['idempotency_key' => $key, 'store_id' => $storeId, 'snapshot_id' => $snapshotId, 'kind' => $kind, 'amount' => $amount, 'available_delta' => $available, 'debt_delta' => $debt, 'created_at' => now()]);
    }

    /** 全ての階層・率・商品選択変更と注文スナップショットが同じ mutex に参加します。 */
    public function lockRevision(): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Store revision lock requires a transaction.');
        }

        return (int) DB::table('personal_store_revisions')->where('id', 1)->lockForUpdate()->value('revision');
    }

    public function path(int $storeId): array
    {
        $ids = DB::table('personal_store_closure')->where('descendant_id', $storeId)->orderByDesc('depth')->pluck('ancestor_id');
        $path = [];
        foreach ($ids as $id) {
            $store = DB::table('personal_stores')->where('id', $id)->first();
            if (! $store || $store->status !== 'active') {
                throw new StorePolicyException(__('yutiv-personal_store::messages.unavailable'));
            }
            if (count($path) > 0 && (int) $store->parent_id !== $path[count($path) - 1]['store_id']) {
                throw new StorePolicyException(__('yutiv-personal_store::messages.invalid_path'));
            }
            $path[] = ['store_id' => (int) $id, 'parent_id' => $store->parent_id, 'pass_bps' => 0];
        }
        foreach ($path as $i => $node) {
            if ($i + 1 < count($path)) {
                $edge = DB::table('personal_store_allocations')->where('parent_id', $node['store_id'])->where('child_id', $path[$i + 1]['store_id'])->first();
                if (! $edge) {
                    throw new StorePolicyException(__('yutiv-personal_store::messages.invalid_path'));
                }
                $path[$i]['pass_bps'] = (int) $edge->rate_bps;
            }
        }
        if ($path === [] || $path[0]['parent_id'] !== null || end($path)['store_id'] !== $storeId) {
            throw new StorePolicyException(__('yutiv-personal_store::messages.invalid_path'));
        }

        return $path;
    }

    public function allowed(int $storeId, int $productId): bool
    {
        $store = DB::table('personal_stores')->where('id', $storeId)->first();
        $product = Product::query()->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->find($productId);
        if (! $store || $store->status !== 'active' || ! $product || ! $product->isPurchasable()) {
            return false;
        }
        $override = DB::table('personal_store_products')->where('store_id', $storeId)->where('product_id', $productId)->first();
        if ($override !== null) {
            return ! $override->excluded;
        }
        // 선택 카테고리의 현재 하위 상품도 포함합니다.
        foreach (DB::table('personal_store_categories')->where('store_id', $storeId)->pluck('category_id') as $category) {
            if (DB::table('ecommerce_product_categories')->where('product_id', $productId)->whereIn('category_id', Category::selfAndDescendantIds((int) $category))->exists()) {
                return true;
            }
        }

        return false;
    }

    public function snapshot(array $data): void
    {
        $snapshot = LineSnapshot::create($data);
        foreach ($data['payload']['reward']['allocations'] as $row) {
            DB::table('personal_store_beneficiaries')->insert(['snapshot_id' => $snapshot->id, 'store_id' => $row['store_id'], 'amount' => $row['amount']]);
        }
        DB::table('personal_store_line_states')->insert(['snapshot_id' => $snapshot->id]);
        $this->outbox('created:'.$data['line_uuid'], 'created', ['snapshot_id' => $snapshot->id, 'snapshot' => $data['payload']]);
    }

    public function outbox(string $key, string $type, array $payload): void
    {
        $type = OutboxType::from($type)->value;
        DB::table('personal_store_outbox')->insertOrIgnore(['event_key' => $key, 'type' => $type, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function move(int $storeId, ?int $parentId, int $actorId): void
    {
        DB::transaction(function () use ($storeId, $parentId, $actorId) {
            $revision = $this->lockRevision();
            $store = DB::table('personal_stores')->where('id', $storeId)->first();
            if (! $store || ($parentId !== null && DB::table('personal_store_closure')->where('ancestor_id', $storeId)->where('descendant_id', $parentId)->exists())) {
                throw new StorePolicyException(__('yutiv-personal_store::messages.invalid_path'));
            }
            $parentId === null || $this->path($parentId);
            $ancestors = $parentId === null ? collect() : DB::table('personal_store_closure')->where('descendant_id', $parentId)->get();
            if ($parentId !== null && $ancestors->isEmpty()) {
                throw new StorePolicyException(__('yutiv-personal_store::messages.invalid_path'));
            }
            $oldAncestors = DB::table('personal_store_closure')->where('descendant_id', $storeId)->where('depth', '>', 0)->pluck('ancestor_id')->all();
            // 하위 내부 관계를 유지하고 외부 조상 연결만 교체합니다.
            $descendants = DB::table('personal_store_closure')->where('ancestor_id', $storeId)->orderBy('descendant_id')->cursor();
            foreach ($descendants as $descendant) {
                DB::table('personal_store_closure')->where('descendant_id', $descendant->descendant_id)->whereIn('ancestor_id', $oldAncestors)->delete();
                foreach ($ancestors as $ancestor) {
                    DB::table('personal_store_closure')->insert(['ancestor_id' => $ancestor->ancestor_id, 'descendant_id' => $descendant->descendant_id, 'depth' => (int) $ancestor->depth + 1 + (int) $descendant->depth]);
                }
            }
            DB::table('personal_stores')->where('id', $storeId)->update(['parent_id' => $parentId, 'updated_at' => now()]);
            DB::table('personal_store_allocations')->where('child_id', $storeId)->delete();
            if ($parentId !== null) {
                DB::table('personal_store_allocations')->insert(['child_id' => $storeId, 'parent_id' => $parentId, 'rate_bps' => 0]);
            }
            DB::table('personal_store_revisions')->where('id', 1)->increment('revision');
            DB::table('personal_store_history')->insert(['store_id' => $storeId, 'actor_id' => $actorId, 'kind' => 'move', 'revision' => $revision + 1, 'payload' => json_encode(['before' => $store->parent_id, 'after' => $parentId]), 'created_at' => now()]);
        }, 3);
    }

    public function rebuildClosure(bool $repair = false): array
    {
        return DB::transaction(function () use ($repair) {
            // 일반 검사는 일관된 읽기/revision 검증만 사용합니다. 전체 검사 중 정책 쓰기를 잠그지 않습니다.
            $revision = $repair ? $this->lockRevision() : (int) DB::table('personal_store_revisions')->where('id', 1)->value('revision');
            $parents = DB::table('personal_stores')->pluck('parent_id', 'id')->all();
            $missing = $extra = 0;
            foreach ($parents as $id => $parent) {
                $expected = [];
                $node = $id;
                $depth = 0;
                while ($node !== null) {
                    if (! array_key_exists($node, $parents) || isset($expected[$node])) {
                        throw new StorePolicyException(__('yutiv-personal_store::messages.invalid_path'));
                    }
                    $expected[$node] = $depth++;
                    $node = $parents[$node];
                }
                $actual = DB::table('personal_store_closure')->where('descendant_id', $id)->pluck('depth', 'ancestor_id')->all();
                foreach ($expected as $ancestor => $distance) {
                    if (! isset($actual[$ancestor]) || (int) $actual[$ancestor] !== $distance) {
                        $missing++;
                    }
                }
                $extra += count(array_diff_key($actual, $expected));
                if ($repair) {
                    DB::table('personal_store_closure')->where('descendant_id', $id)->delete();
                    foreach ($expected as $ancestor => $distance) {
                        DB::table('personal_store_closure')->insert(['ancestor_id' => $ancestor, 'descendant_id' => $id, 'depth' => $distance]);
                    }
                }
            }

            if (! $repair && $revision !== (int) DB::table('personal_store_revisions')->where('id', 1)->value('revision')) {
                throw new StorePolicyException(__('yutiv-personal_store::messages.invalid_path'));
            }

            return ['missing_or_wrong_depth' => $missing, 'extra' => $extra, 'repaired' => $repair];
        });
    }
}
