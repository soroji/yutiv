<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Enums\LiveCurrency;
use Plugins\Yutiv\LiveCommerce\Support\LiveMoney;

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
                $product = $catalog->save(E::Product, $tenant, $actor, array_intersect_key($data, array_flip(['name', 'code', 'description', 'published', 'sellable', 'category_id'])));
            }
            $items = [];
            $units = [];
            $costs = [];
            foreach ($data['items'] as $item) {
                if ($data['mode'] === 'existing' && ! empty($item['option_id'])) {
                    $option = $this->row(E::Option, $tenant, (int) ($item['option_id'] ?? 0), true);
                    abort_unless($option->product_id === $product->id, 404);
                } else {
                    $option = $catalog->save(E::Option, $tenant, $actor, ['product_id' => $product->id, 'name' => $item['name'], 'price' => $item['price'], 'discount' => $item['discount'], 'active' => true, 'attributes' => $item['attributes'] ?? null]);
                }
                if ($item['quantity'] > 0) {
                    $result = app(LiveInventoryService::class)->receive($tenant, $actor, $option->id, $item['quantity'], $data['location'], $data['operation_key'].'-'.$option->id, $data['operation_key']);
                    array_push($units, ...$result['units']);
                    $items[] = ['option_id' => $option->id, 'name' => $option->name, 'quantity' => $item['quantity']];
                    $costs[] = ['option_id' => $option->id, 'quantity' => $item['quantity'], 'unit_cost' => $item['unit_cost'] ?? null, 'units' => $result['units']];
                }
            }
            $receipt = $this->records->create(E::Receipt, $tenant, ['receipt_key' => $data['operation_key'], 'actor_id' => $actor, 'product_id' => $product->id,
                'received_at' => now(), 'location' => $data['location'], 'memo' => $data['memo'] ?? null, 'items' => $items, 'quantity' => count($units)]);
            foreach ($costs as $line) {
                $currency=LiveCurrency::from($data['currency']??'KRW');$known=$line['unit_cost']!==null;$rate=$currency===LiveCurrency::KRW?'1':$data['exchange_rate'];$minor=$known?LiveMoney::minor((string)$line['unit_cost']):null;$unitKrw=$known?LiveMoney::convert((string)$line['unit_cost'],$currency,$rate)['amount_krw']:null;$totalKrw=$known?LiveMoney::convert(LiveMoney::decimal($minor*$line['quantity']),$currency,$rate)['amount_krw']:null;
                $cost=$this->records->create(E::ReceiptItem,$tenant,['receipt_id'=>$receipt->id,'product_id'=>$product->id,'option_id'=>$line['option_id'],'unit_cost'=>$unitKrw,'quantity'=>$line['quantity'],'purchase_amount'=>$totalKrw,'currency'=>$currency->value,'unit_original'=>$known?LiveMoney::decimal($minor):null,'exchange_rate'=>$known?$rate:null,'rate_date'=>$known?($data['rate_date']??now(\Plugins\Yutiv\LiveCommerce\Models\LiveTenant::findOrFail($tenant)->timezone)->format('Y-m-d')):null,'rate_source'=>$known?($data['rate_source']??'manual'):null]);
                foreach($line['units'] as $index=>$uuid)$this->records->query(E::Unit,$tenant)->where('uuid',$uuid)->update(['receipt_item_id'=>$cost->id,'original_cost_krw'=>$known?LiveMoney::allocation($totalKrw,$line['quantity'],$index,1):null]);
                app(LiveFifoService::class)->receipt($cost);
            }
            $this->audit($tenant, $actor, 'receipt.confirmed', ['receipt_id' => $receipt->id, 'quantity' => count($units)]);

            return ['receipt_id' => $receipt->id, 'quantity' => count($units), 'product_id' => $product->id];
        });
    }
}
