<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\FifoAllocationKind;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;
use Plugins\Yutiv\LiveCommerce\Support\LiveMoney;

class LiveFifoService extends LiveWorkflow
{
    public function active(int $tenant): bool
    {
        return $this->records->query(E::FinanceState, $tenant)->exists();
    }

    public function ownedUnits(int $tenant, int $option = 0)
    {
        $latest = $this->records->query(E::Movement, $tenant)->where('type', 'as_receive')->selectRaw('MAX(id)')->groupBy('unit_id');
        $external = $this->records->query(E::Movement, $tenant)->where('type', 'as_receive')->where('from_state', 'shipped')->whereIn('id', $latest)->select('unit_id');

        return $this->records->query(E::Unit, $tenant)->when($option, fn ($q) => $q->where('option_id', $option))->whereIn('state', ['available', 'reserved', 'returned', 'as', 'defective'])->where(fn ($q) => $q->where('state', '!=', 'as')->orWhereNotIn(E::Unit->value.'.id', $external));
    }

    private function beforeOwned(int $tenant, LiveRecord $movement): bool
    {
        if (! in_array($movement->from_state, ['available', 'reserved', 'returned', 'as', 'defective'], true)) {
            return false;
        }if ($movement->from_state !== 'as') {
            return true;
        }$intake = $this->records->query(E::Movement, $tenant)->where('unit_id', $movement->unit_id)->where('type', 'as_receive')->where('id', '<', $movement->id)->orderByDesc('id')->first();

        return $intake?->from_state !== 'shipped';
    }

    public function prepare(int $tenant, int $actor, int $option, string $key): array
    {
        return $this->once($tenant, 'fifo-prepare:'.$key, compact('option'), function () use ($tenant, $option) {
            abort_unless($this->active($tenant), 409);
            $this->row(E::Option, $tenant, $option);
            $this->initialize($tenant, $option);

            return ['option_id' => $option, 'prepared' => true];
        });
    }

    private function layer(int $tenant, int $option, ?LiveRecord $cost, int $quantity, string $kind, string $source, string $received, ?int $explicitAmount = null, bool $override = false): LiveRecord
    {
        $amount = $cost && $cost->purchase_amount !== null && $quantity <= $cost->quantity ? LiveMoney::prorate((int) $cost->purchase_amount, $quantity, (int) $cost->quantity) : null;
        if ($override) {
            $amount = $explicitAmount;
        }

        return $this->records->create(E::FifoLayer, $tenant, ['option_id' => $option, 'receipt_item_id' => $cost?->id, 'source_kind' => \Plugins\Yutiv\LiveCommerce\Enums\FifoLayerSourceKind::from($kind)->value, 'source_key' => $source, 'received_at' => $received, 'quantity' => $quantity, 'remaining_quantity' => $quantity, 'currency' => $cost?->currency ?? 'KRW', 'unit_original' => $cost?->unit_original ?? $cost?->unit_cost, 'exchange_rate' => $cost?->exchange_rate ?? ($cost?->unit_cost !== null ? '1' : null), 'amount_krw' => $amount, 'remaining_krw' => $amount]);
    }

    private function initialize(int $tenant, int $option, ?LiveRecord $unit = null, ?LiveRecord $movement = null): void
    {
        if ($this->records->query(E::FifoOption, $tenant)->where('option_id', $option)->exists()) {
            return;
        }
        $delta = 0;
        if ($unit && $movement) {
            $delta = ($this->beforeOwned($tenant, $movement) ? 1 : 0) - ($this->ownedUnits($tenant, $option)->where('id', $unit->id)->exists() ? 1 : 0);
        }
        $adjusted = false;
        $page = 1;
        do {
            $groups = $this->ownedUnits($tenant, $option)->select('receipt_item_id')->selectRaw('COUNT(*) quantity,MIN(created_at) received,COALESCE(SUM(original_cost_krw),0) assigned_krw,SUM(CASE WHEN original_cost_krw IS NULL THEN 1 ELSE 0 END) unassigned')->groupBy('receipt_item_id')->orderBy('receipt_item_id')->forPage($page++, 200)->get();
            $costs = $this->records->query(E::ReceiptItem, $tenant)->whereIn('id', $groups->pluck('receipt_item_id')->filter())->get()->keyBy('id');
            foreach ($groups as $group) {
                $quantity = (int) $group->quantity;
                $assigned = (int) $group->assigned_krw;
                $unassigned = (int) $group->unassigned;
                if ($unit && $group->receipt_item_id === $unit->receipt_item_id) {
                    $quantity += $delta;
                    if ($unit->original_cost_krw === null) {
                        $unassigned += $delta;
                    } else {
                        $assigned += $delta * (int) $unit->original_cost_krw;
                    }$adjusted = true;
                }if ($quantity <= 0) {
                    continue;
                }$cost = $costs[$group->receipt_item_id] ?? null;
                $this->layer($tenant, $option, $cost, $quantity, 'opening', $option.':'.($group->receipt_item_id ?? 'unknown'), (string) $group->received, $unassigned > 0 && $cost?->unit_cost === null ? null : $assigned + $unassigned * (int) ($cost?->unit_cost ?? 0), true);
            }
        } while ($groups->count() === 200);
        if (! $adjusted && $delta > 0) {
            $cost = $unit->receipt_item_id ? $this->row(E::ReceiptItem, $tenant, $unit->receipt_item_id) : null;
            $this->layer($tenant, $option, $cost, $delta, 'opening', $option.':'.($unit->receipt_item_id ?? 'unknown'), $unit->created_at->format('Y-m-d H:i:s'), $unit->original_cost_krw ?? $cost?->unit_cost, true);
        }
        $this->records->create(E::FifoOption, $tenant, ['option_id' => $option, 'baseline_at' => now()]);
    }

    public function receipt(LiveRecord $cost): void
    {
        $state = $this->records->query(E::FinanceState, $cost->tenant_id)->first();
        if (! $state || $cost->id <= (int) ($state->data['start_receipt_item_id'] ?? 0)) {
            return;
        }$receipt = $this->row(E::Receipt, $cost->tenant_id, $cost->receipt_id);
        $this->layer($cost->tenant_id, $cost->option_id, $cost, $cost->quantity, 'receipt', (string) $cost->id, $receipt->received_at->format('Y-m-d H:i:s'));
    }

    public function movement(LiveRecord $unit, LiveRecord $movement, bool $pendingCost = false): void
    {
        $state = $this->records->query(E::FinanceState, $unit->tenant_id)->first();
        if (! $state || $movement->id <= (int) ($state->data['start_movement_id'] ?? 0)) {
            return;
        }
        $this->once($unit->tenant_id, 'fifo-movement:'.$movement->id, ['unit_id' => $unit->id, 'movement_id' => $movement->id], function () use ($unit, $movement, $pendingCost) {
            $this->processMovement($unit, $movement, $pendingCost);

            return ['processed' => true];
        });
    }

    private function processMovement(LiveRecord $unit, LiveRecord $movement, bool $pendingCost): void
    {
        $tenant = $unit->tenant_id;
        app(LiveFinancePeriodService::class)->assertOpen($tenant);
        $this->initialize($tenant, $unit->option_id, $unit, $movement);
        $ownedBefore = $this->beforeOwned($tenant, $movement);
        $ownedAfter = $this->ownedUnits($tenant, $unit->option_id)->where('id', $unit->id)->exists();
        if ($movement->type === 'ship') {
            $this->consume($unit, $movement, FifoAllocationKind::Sale->value);

            return;
        }
        if ($movement->type === FifoAllocationKind::Return->value) {
            $this->restore($unit, $movement, FifoAllocationKind::Return->value, FifoAllocationKind::Sale->value);

            return;
        }
        if ($ownedBefore && ! $ownedAfter && in_array($unit->state, ['lost', 'disposed'], true)) {
            $this->consume($unit, $movement, FifoAllocationKind::Loss->value);

            return;
        }
        if (! $ownedBefore && $ownedAfter) {
            if ($movement->type === 'receive') {
                if (! $pendingCost) {
                    $this->layer($tenant, $unit->option_id, null, 1, 'unknown', 'movement:'.$movement->id, $movement->created_at->format('Y-m-d H:i:s'));
                }
            } elseif ($movement->from_state === 'lost') {
                $this->restore($unit, $movement, FifoAllocationKind::Restore->value, FifoAllocationKind::Loss->value);
            }
        }
    }

    private function consume(LiveRecord $unit, LiveRecord $movement, string $kind): void
    {
        $layer = $this->records->query(E::FifoLayer, $unit->tenant_id)->where('option_id', $unit->option_id)->where('remaining_quantity', '>', 0)->orderBy('received_at')->orderBy('id')->lockForUpdate()->first();
        abort_unless($layer, 409, __('yutiv-live_commerce::live.fifo_reconcile'));
        $amount = $layer->remaining_krw === null ? null : LiveMoney::prorate($layer->remaining_krw, 1, $layer->remaining_quantity);
        $layer->update(['remaining_quantity' => $layer->remaining_quantity - 1, 'remaining_krw' => $amount === null ? null : $layer->remaining_krw - $amount]);
        $revenue = $kind === FifoAllocationKind::Sale->value ? (int) $this->row(E::OrderLine, $unit->tenant_id, $unit->order_line_id)->unit_price : 0;
        $this->records->create(E::FifoAllocation, $unit->tenant_id, ['movement_id' => $movement->id, 'unit_id' => $unit->id, 'unit_uuid' => $unit->uuid, 'option_id' => $unit->option_id, 'order_line_id' => $movement->order_line_id, 'layer_id' => $layer->id, 'kind' => $kind, 'quantity' => 1, 'amount_krw' => $amount, 'revenue_krw' => $revenue, 'recognized_at' => $movement->created_at]);
    }

    private function restore(LiveRecord $unit, LiveRecord $movement, string $kind, string $originalKind): void
    {
        $used = $this->records->query(E::FifoAllocation, $unit->tenant_id)->whereNotNull('reversal_of')->select('reversal_of');
        $allocation = $this->records->query(E::FifoAllocation, $unit->tenant_id)->where('unit_id', $unit->id)->where('kind', $originalKind)->whereNotIn('id', $used)->orderByDesc('id')->lockForUpdate()->first();
        if ($allocation) {
            $layer = $this->row(E::FifoLayer, $unit->tenant_id, $allocation->layer_id, true);
            $layer->update(['remaining_quantity' => $layer->remaining_quantity + 1, 'remaining_krw' => $allocation->amount_krw === null ? null : $layer->remaining_krw + $allocation->amount_krw]);
            $amount = $allocation->amount_krw === null ? null : -$allocation->amount_krw;
            $layerId = $layer->id;
            $revenue = -$allocation->revenue_krw;
            $reversal = $allocation->id;
        } else {
            $cost = $unit->receipt_item_id ? $this->row(E::ReceiptItem, $unit->tenant_id, $unit->receipt_item_id) : null;
            $layer = $this->layer($unit->tenant_id,$unit->option_id,$cost,1,FifoAllocationKind::LegacyReturn->value,'movement:'.$movement->id,$unit->created_at->format('Y-m-d H:i:s'));
            $amount = null;
            $layerId = $layer->id;
            $revenue = 0;
            $reversal = null;
            $kind = FifoAllocationKind::LegacyReturn->value;
        }
        $this->records->create(E::FifoAllocation,$unit->tenant_id,['movement_id' => $movement->id, 'unit_id' => $unit->id, 'unit_uuid' => $unit->uuid, 'option_id' => $unit->option_id, 'order_line_id' => $movement->order_line_id, 'layer_id' => $layerId, 'reversal_of' => $reversal, 'kind' => $kind, 'quantity' => -1, 'amount_krw' => $amount, 'revenue_krw' => $revenue, 'recognized_at' => $movement->created_at]);
    }
}
