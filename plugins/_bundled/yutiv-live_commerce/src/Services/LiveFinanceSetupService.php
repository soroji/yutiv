<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;

class LiveFinanceSetupService extends LiveWorkflow
{
    public function activate(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-activate:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            abort_unless(! $this->records->query(E::FinanceState, $tenant)->exists(), 409);
            $state = $this->records->create(E::FinanceState, $tenant, ['actor_id' => $actor, 'started_at' => now(), 'data' => ['aging_warning_days' => 90, 'aging_risk_days' => 180, 'labor_ratio_bps' => 2000, 'start_movement_id' => (int) ($this->records->query(E::Movement, $tenant)->max('id') ?? 0), 'start_receipt_item_id' => (int) ($this->records->query(E::ReceiptItem, $tenant)->max('id') ?? 0), 'recognition' => 'shipment', 'opening' => 'actual-owned-at-first-followup', 'reason' => $d['reason']]]);
            app(LiveExpenseService::class)->defaults($tenant);
            $this->audit($tenant, $actor, 'finance.activated', ['started_at' => $state->started_at->toISOString(), 'reason' => $d['reason'], 'revenue_basis' => 'shipment', 'fifo_opening' => 'actual-owned-opening']);

            return ['activated' => true];
        });
    }

    public function settings(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-settings:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $state = $this->records->query(E::FinanceState, $tenant)->lockForUpdate()->firstOrFail();
            $data = $state->data;
            $data = array_merge($data, array_intersect_key($d, array_flip(['aging_warning_days', 'aging_risk_days', 'labor_ratio_bps'])));
            $state->update(['data' => $data]);
            $this->audit($tenant, $actor, 'finance.settings_changed', ['values' => $data, 'reason' => $d['reason']]);

            return ['saved' => true];
        });
    }

    public function bank(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-bank:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $fund = ! empty($d['fund_id']) ? $this->row(E::Fund, $tenant, $d['fund_id']) : null;
            if ($d['customer_visible'] && $fund) {
                abort_unless($fund->currency === 'KRW', 422, __('yutiv-live_commerce::live.finance_currency'));
            }$values = array_intersect_key($d, array_flip(['bank', 'account_number', 'holder', 'active', 'default', 'customer_visible', 'fund_id']));
            if (! empty($d['id']) && empty($values['account_number'])) {
                unset($values['account_number']);
            }$bank = app(LiveCatalogService::class)->save(E::Bank, $tenant, $actor, $values, $d['id'] ?? null);

            return ['bank_account_id' => $bank->id];
        });
    }

    public function logistics(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'logistics:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $unit = $this->records->query(E::Unit, $tenant)->where('uuid', $d['unit'])->lockForUpdate()->firstOrFail();
            $before = $unit->logistics_stage;
            $unit->update(['logistics_stage' => $d['logistics_stage']]);
            $this->audit($tenant,$actor,'inventory.logistics',['unit_uuid' => $unit->uuid, 'from' => $before, 'to' => $d['logistics_stage'], 'reason' => $d['reason'], 'stock_quantity_changed' => false]);

            return ['saved' => true];
        });
    }
}
