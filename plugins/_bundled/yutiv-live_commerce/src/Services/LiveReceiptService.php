<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;

class LiveReceiptService extends LiveWorkflow
{
    public function receive(int $tenant, int $actor, array $data): array
    {
        return $this->once($tenant, 'receipt:'.$data['operation_key'], $data, function () use ($tenant, $actor, $data) {
            abort_if($this->records->query(E::Unit, $tenant)->where('receipt_key', $data['operation_key'])->exists()
                || $this->records->query(E::Receipt, $tenant)->where('receipt_key', $data['operation_key'])->exists(), 409, __('yutiv-live_commerce::live.receipt_key'));
            $catalog = app(LiveCatalogService::class);
            if ($data['mode'] === 'existing') {
                $product = $this->row(E::Product, $tenant, $data['product_id'], true);
            } else {
                if ($data['mode'] === 'copy') {
                    $this->row(E::Product, $tenant, $data['product_id'], true);
                }
                abort_if($this->records->query(E::Product, $tenant)->where('code', $data['code'])->exists(), 422, __('yutiv-live_commerce::live.receipt_code'));
                $product = $catalog->save(E::Product, $tenant, $actor, array_intersect_key($data, array_flip(['name', 'code', 'description', 'published', 'sellable'])));
            }
            $items = [];
            $units = [];
            foreach ($data['items'] as $item) {
                if ($data['mode'] === 'existing') {
                    $option = $this->row(E::Option, $tenant, (int) ($item['option_id'] ?? 0), true);
                    abort_unless($option->product_id === $product->id, 404);
                } else {
                    $option = $catalog->save(E::Option, $tenant, $actor, ['product_id' => $product->id, 'name' => $item['name'], 'price' => $item['price'], 'discount' => $item['discount'], 'active' => true]);
                }
                if ($item['quantity'] > 0) {
                    $result = app(LiveInventoryService::class)->receive($tenant, $actor, $option->id, $item['quantity'], $data['location'], $data['operation_key'].'-'.$option->id, $data['operation_key']);
                    array_push($units, ...$result['units']);
                    $items[] = ['option_id' => $option->id, 'name' => $option->name, 'quantity' => $item['quantity']];
                }
            }
            $receipt = $this->records->create(E::Receipt, $tenant, ['receipt_key' => $data['operation_key'], 'actor_id' => $actor, 'product_id' => $product->id,
                'received_at' => now(), 'location' => $data['location'], 'memo' => $data['memo'] ?? null, 'items' => $items, 'quantity' => count($units)]);
            $this->audit($tenant, $actor, 'receipt.confirmed', ['receipt_id' => $receipt->id, 'quantity' => count($units)]);

            return ['receipt_id' => $receipt->id, 'quantity' => count($units), 'product_id' => $product->id];
        });
    }
}
