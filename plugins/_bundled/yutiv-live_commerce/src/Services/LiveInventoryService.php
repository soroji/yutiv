<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Enums\StockState;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;

class LiveInventoryService extends LiveWorkflow
{
    /** Repair custody does not cancel sales or increment sales-shipping counters. */
    public function serviceUnit(int $tenant, int $actor, string $qr, bool $dispatch, string $location, string $reason, string $key): array
    {
        return $this->once($tenant, 'as:'.$key, compact('qr', 'dispatch', 'location', 'reason'), function () use ($tenant, $actor, $qr, $dispatch, $location, $reason, $key) {
            $unit = $this->records->query(E::Unit, $tenant)->where('uuid', $qr)->lockForUpdate()->firstOrFail();
            $before = ['state' => $unit->state, 'location' => $unit->location, 'order_line_id' => $unit->order_line_id];
            if ($dispatch) {
                $intake = $this->records->query(E::Movement, $tenant)->where('unit_id', $unit->id)->latest('id')->lockForUpdate()->firstOrFail();
                abort_unless($unit->state === 'as' && $intake->type === 'as_receive', 409, __('yutiv-live_commerce::live.as_dispatch_state'));
                $next = $intake->from_state;
            } else {
                abort_unless(in_array($unit->state, ['available', 'returned', 'shipped'], true), 409, __('yutiv-live_commerce::live.as_receive_state'));
                $next = 'as';
            }
            $unit->fill(['state' => $next, 'location' => $location])->save();
            $this->movement($unit, $actor, $key, $dispatch ? 'as_dispatch' : 'as_receive', $before, $reason);
            $this->audit($tenant, $actor, $dispatch ? 'inventory.as_dispatched' : 'inventory.as_received', ['unit_id' => $unit->id, 'order_line_id' => $unit->order_line_id, 'reason' => $reason]);

            return ['unit' => $unit->uuid, 'state' => $unit->state, 'sales_unchanged' => true];
        });
    }

    public function correct(int $tenant, int $actor, string $qr, int $movementId, string $reason, string $key): array
    {
        return $this->once($tenant, 'stock-correction:'.$key, compact('qr', 'movementId', 'reason'), function () use ($tenant, $actor, $qr, $movementId, $reason, $key) {
            $unit = $this->records->query(E::Unit, $tenant)->where('uuid', $qr)->lockForUpdate()->firstOrFail();
            $last = $this->records->query(E::Movement, $tenant)->where('unit_id', $unit->id)->orderByDesc('id')->lockForUpdate()->firstOrFail();
            abort_unless($last->id === $movementId && in_array($last->type, ['move', 'adjust'], true), 409, __('yutiv-live_commerce::live.errors.e_0a086e7c76'));
            abort_unless($unit->state === $last->to_state && $unit->location === $last->to_location && ! in_array($last->from_state, ['reserved', 'shipped'], true), 409, __('yutiv-live_commerce::live.errors.e_cc08717594'));
            $before = ['state' => $unit->state, 'location' => $unit->location, 'order_line_id' => $unit->order_line_id];
            $unit->fill(['state' => $last->from_state, 'location' => $last->from_location, 'order_line_id' => $last->from_state === 'available' ? null : $last->order_line_id])->save();
            $this->movement($unit, $actor, $key, 'correction', $before, $reason);
            $this->audit($tenant, $actor, 'inventory.corrected', ['unit_id' => $unit->id, 'movement_id' => $movementId, 'reason' => $reason]);

            return ['unit' => $unit->uuid, 'state' => $unit->state];
        });
    }

    public function preview(int $tenant, array $qrs, ?int $orderId): array
    {
        $order = $orderId ? $this->row(E::Order, $tenant, $orderId) : null;
        if ($order) {
            abort_unless($order->payment_state === 'paid', 409, __('yutiv-live_commerce::live.errors.e_1eafb7ca3c'));
        }
        $items = [];
        foreach ($qrs as $qr) {
            if (str_starts_with($qr, 'P:')) {
                abort_if($order, 422, __('yutiv-live_commerce::live.errors.e_e65e06eeec'));
                $option = $this->records->query(E::Option, $tenant)->where('qr_code', substr($qr, 2))->firstOrFail();
                $product = $this->row(E::Product, $tenant, $option->product_id);
                $items[] = ['uuid' => $qr, 'product' => $product->name, 'option' => $option->name, 'state' => '상품/옵션 식별', 'location' => '출고는 개별 물품 QR 사용'];

                continue;
            }
            $unit = $this->records->query(E::Unit, $tenant)->where('uuid', $qr)->firstOrFail();
            $option = $this->row(E::Option, $tenant, $unit->option_id);
            $product = $this->row(E::Product, $tenant, $option->product_id);
            if ($order) {
                abort_unless($unit->state === 'reserved' && $unit->order_line_id, 409, __('yutiv-live_commerce::live.errors.e_47957e7e41'));
                $line = $this->row(E::OrderLine, $tenant, $unit->order_line_id);
                abort_unless($line->order_id === $order->id && $line->option_id === $unit->option_id, 409, __('yutiv-live_commerce::live.errors.e_45013caf90'));
            }
            $items[] = ['uuid' => $unit->uuid, 'product' => $product->name, 'option' => $option->name, 'state' => $unit->state, 'location' => $unit->location];
        }
        $remaining = $order ? $this->records->query(E::Unit, $tenant)->whereIn('order_line_id', $this->records->query(E::OrderLine, $tenant)->where('order_id', $order->id)->select('id'))->where('state', 'reserved')->count() : null;

        return ['items' => $items, 'scanned' => count($items), 'remaining' => $remaining, 'partial' => $remaining !== null && count($items) < $remaining, 'changed' => false];
    }

    public function labelPrint(int $tenant, int $actor, array $qrs, int $width, int $height, string $key, ?int $batch = null): array
    {
        sort($qrs);

        $input = compact('qrs', 'width', 'height');
        if ($batch !== null) {
            $input['batch'] = $batch;
        }

        return $this->once($tenant, 'label-print:'.$key, $input, function () use ($tenant, $actor, $qrs, $width, $height, $batch) {
            abort_unless($this->records->query(E::Unit, $tenant)->whereIn('uuid', $qrs)->count() === count($qrs), 404);
            if ($batch !== null) {
                $receipt = $this->row(E::Receipt, $tenant, $batch);
                abort_unless($this->records->query(E::Unit, $tenant)->where('receipt_key', $receipt->receipt_key)->whereIn('uuid', $qrs)->count() === count($qrs), 404);
            }
            $this->audit($tenant, $actor, 'labels.print_requested', ['units' => $qrs, 'width_mm' => $width, 'height_mm' => $height, 'physical_print_verified' => false]);

            return ['recorded' => true, 'physical_print_verified' => false];
        });
    }

    public function receive(int $tenant, int $actor, int $option, int $quantity, string $location, string $key, ?string $receiptKey = null): array
    {
        $input = compact('option', 'quantity', 'location');
        if ($receiptKey !== null) {
            $input['receiptKey'] = $receiptKey;
        }

        return $this->once($tenant, 'receive:'.$key, $input, function () use ($tenant, $actor, $option, $quantity, $location, $key, $receiptKey) {
            $this->row(E::Option, $tenant, $option, true);
            $units = [];
            for ($i = 0; $i < $quantity; $i++) {
                $unit = $this->records->create(E::Unit, $tenant, ['uuid' => (string) Str::uuid(), 'option_id' => $option, 'state' => 'available', 'location' => $location, 'receipt_key' => $receiptKey ?? $key]);
                $this->movement($unit, $actor, $key, 'receive', null, '입고');
                $units[] = $unit->uuid;
            }
            $this->audit($tenant, $actor, 'inventory.receive', ['option_id' => $option, 'quantity' => $quantity]);

            return ['units' => $units];
        });
    }

    public function transition(int $tenant, int $actor, string $qr, string $state, string $location, string $reason, string $key): array
    {
        return $this->once($tenant, 'unit:'.$key, compact('qr', 'state', 'location', 'reason'), function () use ($tenant, $actor, $qr, $state, $location, $reason, $key) {
            $unit = $this->records->query(E::Unit, $tenant)->where('uuid', $qr)->firstOrFail();
            $this->row(E::Option, $tenant, $unit->option_id, true);
            $unit = $this->row(E::Unit, $tenant, $unit->id, true);
            $allowed = array_map(fn ($value) => $value->value, StockState::from($unit->state)->adjustments());
            abort_unless(in_array($state, $allowed, true), 409, __('yutiv-live_commerce::live.errors.e_7c3959dd9b'));
            $before = ['state' => $unit->state, 'location' => $unit->location, 'order_line_id' => $unit->order_line_id];
            $unit->fill(['state' => $state, 'location' => $location])->save();
            if ($state === 'available') {
                $unit->order_line_id = null;
                $unit->save();
            }
            $this->movement($unit, $actor, $key, $state === $before['state'] ? 'move' : 'adjust', $before, $reason);
            $this->audit($tenant, $actor, 'inventory.transition', ['unit_id' => $unit->id, 'from' => $before['state'], 'to' => $state]);

            return ['unit' => $unit->uuid, 'state' => $state];
        });
    }

    public function movement(LiveRecord $unit, int $actor, string $key, string $type, ?array $before, string $reason): void
    {
        $this->records->create(E::Movement, $unit->tenant_id, ['unit_id' => $unit->id, 'operation_key' => $key, 'type' => $type,
            'from_state' => $before['state'] ?? null, 'to_state' => $unit->state, 'from_location' => $before['location'] ?? null,
            'to_location' => $unit->location, 'actor_id' => $actor, 'reason' => $reason, 'order_line_id' => $before['order_line_id'] ?? $unit->order_line_id]);
    }

    public function reconcile(int $tenant): array
    {
        $mismatches = [];
        $this->records->query(E::Unit, $tenant)->orderBy('id')->chunkById(200, function ($units) use ($tenant, &$mismatches) {
            foreach ($units as $unit) {
                $last = $this->records->query(E::Movement, $tenant)->where('unit_id', $unit->id)->orderByDesc('id')->first();
                if (! $last || $last->to_state !== $unit->state || $last->to_location !== $unit->location) {
                    $mismatches[] = $unit->uuid;
                }
            }
        });

        return ['consistent' => $mismatches === [], 'mismatches' => $mismatches];
    }
}
