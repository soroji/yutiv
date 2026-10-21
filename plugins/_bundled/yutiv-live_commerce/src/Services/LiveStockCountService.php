<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Enums\StockCountStatus;

class LiveStockCountService extends LiveWorkflow
{
    public function query(E $entity, int $tenant)
    {
        return $this->records->query($entity, $tenant);
    }

    public function draft(int $tenant, int $actor, array $data): array
    {
        return $this->once($tenant, 'stock-count-draft:'.$data['operation_key'], $data, function () use ($tenant, $actor, $data) {
            $option = $this->row(E::Option, $tenant, $data['option_id']);
            $existing = ! empty($data['id']) ? $this->row(E::StockCount, $tenant, $data['id'], true) : null;
            if ($existing) {
                abort_unless($existing->status === StockCountStatus::Draft->value && $existing->revision === (int) $data['revision'] && $existing->option_id === $option->id && $existing->location === $data['location'], 409, __('yutiv-live_commerce::live.count_conflict'));
            }
            $values = ['counted_quantity' => $data['counted_quantity'], 'identified_units' => $data['qrs'] ?? [], 'target_state' => $data['target_state'], 'reason' => $data['reason'], 'actor_id' => $actor];
            if (! $existing || ! empty($data['refresh'])) {
                $values += ['option_id' => $option->id, 'location' => $data['location'], 'baseline_at' => now(), 'inventory_revision' => (int) ($this->records->query(E::InventoryRevision, $tenant)->where('option_id', $option->id)->value('revision') ?? 0), 'system_quantity' => $this->records->query(E::Unit, $tenant)->where('option_id', $option->id)->where('location', $data['location'])->whereIn('state', ['available', 'reserved', 'returned', 'as', 'defective'])->count()];
            }
            foreach ($values['identified_units'] as $qr) {
                $this->records->query(E::Unit, $tenant)->where('option_id', $option->id)->where('uuid', $qr)->firstOrFail();
            }
            if ($existing) {
                $values['revision'] = $existing->revision + 1;
                $existing->update($values);
                $count = $existing;
            } else {
                $count = $this->records->create(E::StockCount, $tenant, $values + ['status' => StockCountStatus::Draft->value, 'revision' => 1]);
            }
            $this->audit($tenant, $actor, 'inventory.count_draft', ['count_id' => $count->id, 'revision' => $count->revision]);

            return ['count_id' => $count->id, 'revision' => $count->revision];
        });
    }

    public function confirm(int $tenant, int $actor, int $id, int $revision, string $key): array
    {
        return $this->once($tenant, 'stock-count-confirm:'.$key, compact('id', 'revision'), function () use ($tenant, $actor, $id, $revision, $key) {
            $count = $this->row(E::StockCount, $tenant, $id, true);
            abort_unless($count->status === StockCountStatus::Draft->value && $count->revision === $revision, 409, __('yutiv-live_commerce::live.count_conflict'));
            $current = (int) ($this->records->query(E::InventoryRevision, $tenant)->where('option_id', $count->option_id)->value('revision') ?? 0);
            abort_unless($current === $count->inventory_revision, 409, __('yutiv-live_commerce::live.count_recheck'));
            $difference = $count->counted_quantity - $count->system_quantity;
            $qrs = $count->identified_units;
            sort($qrs);
            abort_unless(count($qrs) === abs($difference), 409, __('yutiv-live_commerce::live.count_identify'));
            $adjustments = [];
            foreach ($qrs as $qr) {
                $unit = $this->records->query(E::Unit, $tenant)->where('option_id', $count->option_id)->where('uuid', $qr)->lockForUpdate()->firstOrFail();
                $before = ['uuid' => $qr, 'state' => $unit->state, 'location' => $unit->location];
                if ($difference < 0) {
                    abort_unless($unit->location === $count->location && in_array($unit->state, ['available', 'returned', 'as', 'defective'], true) && in_array($count->target_state, ['lost', 'disposed'], true), 409, __('yutiv-live_commerce::live.count_reserved'));
                } else {
                    abort_unless($difference > 0 && $unit->state === 'lost' && $count->target_state === 'available', 409, __('yutiv-live_commerce::live.count_identify'));
                }
                $result = app(LiveInventoryService::class)->transition($tenant, $actor, $qr, $count->target_state, $count->location, '실사 #'.$id.': '.$count->reason, $key.'-'.$unit->id);
                $adjustments[] = $before + ['to_state' => $result['state']];
            }
            $count->update(['status' => StockCountStatus::Confirmed->value, 'confirmed_by' => $actor, 'confirmed_at' => now(), 'adjustments' => ['system_quantity' => $count->system_quantity, 'counted_quantity' => $count->counted_quantity, 'difference' => $difference, 'units' => $adjustments]]);
            $this->audit($tenant,$actor,'inventory.count_confirmed',['count_id' => $id, 'difference' => $difference]);

            return ['count_id' => $id, 'confirmed' => true, 'difference' => $difference];
        });
    }
}
