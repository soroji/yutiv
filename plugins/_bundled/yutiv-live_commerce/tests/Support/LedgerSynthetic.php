<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Support;

use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;

/** Synthetic only. Exercises every former cumulative cap, no production values. */
final class LedgerSynthetic
{
    public static function seed(int $tenant): array
    {
        $r = app(LiveRepositoryInterface::class);
        $stamp = ['tenant_id' => $tenant, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'];
        $insert = function (E $e, array $rows) use ($r, $tenant) {
            foreach (array_chunk($rows, 200) as $chunk) {
                $r->query($e, $tenant)->insert($chunk);
            }
        };
        $product = $r->create(E::Product, $tenant, ['name' => '누적 검증 합성 상품', 'code' => 'SCALE-ALL', 'published' => false, 'sellable' => true]);
        $rows = [];
        for ($i = 0; $i < 520; $i++) {
            $rows[] = $stamp + ['product_id' => $product->id, 'name' => '합성 옵션 '.$i, 'price' => 100, 'qr_code' => (string) Str::uuid()];
        }
        $insert(E::Option, $rows);
        $options = $r->query(E::Option, $tenant)->where('product_id', $product->id)->orderBy('id')->pluck('id')->all();
        $rows = [];
        for ($i = 0; $i < 4200; $i++) {
            $rows[] = $stamp + ['uuid' => (string) Str::uuid(), 'option_id' => $options[$i % 520], 'state' => 'available', 'location' => '합성 창고', 'receipt_key' => 'synthetic-scale'];
        }
        $insert(E::Unit, $rows);
        $r->query(E::Unit, $tenant)->where('receipt_key', 'synthetic-scale')->chunkById(200, function ($units) use ($stamp, $insert) {
            $rows = [];
            foreach ($units as $unit) {
                foreach ([['receive', null, 'available'], ['reserve', 'available', 'reserved'], ['cancel', 'reserved', 'available'], ['as_receive', 'available', 'as'], ['as_dispatch', 'as', 'available'], ['move', 'available', 'available']] as $i => [$type,$from,$to]) {
                    $rows[] = $stamp + ['unit_id' => $unit->id, 'operation_key' => 'scale-'.$i, 'type' => $type, 'from_state' => $from, 'to_state' => $to, 'from_location' => $from ? '합성 창고' : null, 'to_location' => '합성 창고', 'actor_id' => 0, 'reason' => '합성 규모 검증'];
                }
            }
            $insert(E::Movement, $rows);
        });
        $rows = [];
        for ($i = 0; $i < 2601; $i++) {
            $rows[] = ['created_at' => $i === 2600 ? '2026-10-01 01:00:00' : $stamp['created_at']] + $stamp + ['uuid' => (string) Str::uuid(), 'user_id' => 0, 'operation_key' => 'scale-'.$i, 'request_hash' => str_repeat('0', 64), 'payment_state' => 'paid', 'delivery_state' => 'pending', 'subtotal' => 400, 'total' => 400, 'received' => 400, 'bank_snapshot' => 'synthetic-unused', 'delivery' => 'synthetic-unused', 'expires_at' => '2026-12-01 00:00:00'];
        }
        $insert(E::Order, $rows);
        $orderIds = $r->query(E::Order, $tenant)->where('operation_key', 'like', 'scale-%')->orderBy('id')->pluck('id')->all();
        $special = end($orderIds);
        $r->query(E::Order, $tenant)->whereKey($special)->update(['received' => 150]);
        $rows = [];
        foreach ($orderIds as $id) {
            for ($i = 0; $i < 4; $i++) {
                $rows[] = $stamp + ['uuid' => (string) Str::uuid(), 'order_id' => $id, 'option_id' => $options[0], 'quantity' => 1, 'unit_price' => 100, 'snapshot' => json_encode(['product' => '당시 합성 상품', 'channel_id' => 0])];
            }
        }
        $insert(E::OrderLine, $rows);
        $specialLines = $r->query(E::OrderLine, $tenant)->where('order_id', $special)->orderBy('id')->pluck('id')->all();
        $r->query(E::OrderLine, $tenant)->whereKey($specialLines[0])->update(['cancelled' => 1]);
        $r->query(E::OrderLine, $tenant)->whereKey($specialLines[1])->update(['returned' => 1]);
        $rows = [];
        foreach ($orderIds as $id) {
            foreach ([['deposit', 100], ['deposit', 200], ['deposit', 150], ['deposit_review', 150], ['cancel', 100], ['return', 100], ['refund', 200], ['bank_correction', 0]] as $i => [$type,$amount]) {
                $data = in_array($type, ['cancel', 'return']) ? ['line_id' => $specialLines[$type === 'cancel' ? 0 : 1], 'quantity' => 1] : [];
                $rows[] = ['created_at' => $id === $special ? sprintf('2026-10-%02d 01:00:00', $i + 2) : $stamp['created_at']] + $stamp + ['order_id' => $id, 'operation_key' => 'scale-'.$i, 'type' => $id === $special ? $type : 'bank_correction', 'amount' => $id === $special ? $amount : 0, 'data' => json_encode($data), 'actor_id' => 0];
            }
        }
        $insert(E::Event, $rows);

        return ['product' => $product->id, 'option' => $options[0], 'order' => $special, 'line' => $specialLines[2], 'sizes' => ['options' => 520, 'units' => 4200, 'movements' => 25200, 'orders' => 2601, 'lines' => 10404, 'events' => 20808]];
    }
}
