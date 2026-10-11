<?php

namespace Modules\Yutiv\PersonalStore\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Yutiv\PersonalStore\Exceptions\StorePolicyException;
use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;
use Modules\Yutiv\PersonalStore\Services\AttributionProof;
use Modules\Yutiv\PersonalStore\Services\OrderSnapshotService;

class StoreOrderListener implements HookListenerInterface
{
    public function __construct(private StoreRepositoryInterface $stores, private OrderSnapshotService $snapshots, private AttributionProof $proof) {}

    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.order.created_in_transaction' => ['method' => 'created', 'sync' => true],
            'sirsoft-ecommerce.order_option.changed_in_transaction' => ['method' => 'optionChanged', 'sync' => true],
            'sirsoft-ecommerce.order.cancellation_completed_in_transaction' => ['method' => 'state', 'sync' => true],
            'sirsoft-ecommerce.order-option.after_confirm' => ['method' => 'state', 'sync' => true],
            'sirsoft-ecommerce.order.after_payment_complete' => ['method' => 'state', 'sync' => true],
            'sirsoft-ecommerce.order.after_deposit_recorded' => ['method' => 'state', 'sync' => true],
            'sirsoft-ecommerce.cart.filter_add_data' => ['method' => 'cart', 'type' => 'filter'],
            'sirsoft-ecommerce.cart.filter_direct_attribution' => ['method' => 'direct', 'type' => 'filter'],
            'sirsoft-ecommerce.temp_order.before_create' => ['method' => 'tempCreating', 'sync' => true],
            'sirsoft-ecommerce.temp_order.before_update' => ['method' => 'tempUpdating', 'sync' => true],
        ];
    }

    public function handle(...$args): void {}

    public function created(...$args): void
    {
        $this->snapshots->create($args[0], $args[2], $args[3]);
    }

    public function state(...$args): void
    {
        $this->stores->captureOrderState($args[0]->id);
    }

    public function optionChanged(...$args): void
    {
        $this->stores->captureOrderState($args[0]->order_id);
    }

    public function cart(array $data): array
    {
        // 임의 store/attribution ID는 필터에서 덮어씁니다. 증명 검증을 우회할 수 없습니다.
        $data['attribution_key'] = $this->verifiedReference((int) $data['product_id']);

        return $data;
    }

    public function direct($value, array $item, $option): ?string
    {
        return $this->verifiedReference((int) $option->product_id);
    }

    public function tempCreating($items, ...$args): void
    {
        foreach ($items as $item) {
            $this->validateReference($item->attribution_key, (int) $item->product_id);
        }
    }

    public function tempUpdating($tempOrder, ...$args): void
    {
        foreach ($tempOrder->items as $item) {
            $this->validateReference($item['attribution_key'] ?? null, (int) $item['product_id']);
        }
    }

    private function validateReference(?string $reference, int $productId): void
    {
        if ($reference !== null) {
            $storeId = $this->stores->attribution($reference);
            if ($storeId === null || ! $this->stores->allowed($storeId, $productId)) {
                throw new StorePolicyException(__('yutiv-personal_store::messages.unavailable'));
            }
            $this->stores->path($storeId);
        }
    }

    private function verifiedReference(int $productId): ?string
    {
        $reference = $this->proof->verify(request()->header('X-Yutiv-Store-Origin'), 'sale');
        $this->validateReference($reference, $productId);

        return $reference;
    }
}
