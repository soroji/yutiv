<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Carbon\CarbonImmutable as Date;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;

class LiveFinanceAssetService extends LiveWorkflow
{
    public function grouped(int $tenant, array $filters = [], ?string $reference = null)
    {
        $state = $this->records->query(E::FinanceState, $tenant)->first();
        $settings = $state?->data ?? [];
        $warning = (int) ($settings['aging_warning_days'] ?? 90);
        $risk = (int) ($settings['aging_risk_days'] ?? 180);
        $now = $reference ? Date::parse($reference, 'UTC') : Date::now('UTC');
        $a = $now->subDays($risk)->format('Y-m-d H:i:s');
        $b = $now->subDays($warning)->format('Y-m-d H:i:s');
        $prefix = $this->records->query(E::Unit, $tenant)->getModel()->getConnection()->getTablePrefix();
        $u = $prefix.E::Unit->value;
        $c = $prefix.E::ReceiptItem->value;
        $q = app(LiveFifoService::class)->ownedUnits($tenant)->leftJoin(E::ReceiptItem->value, E::ReceiptItem->value.'.id', '=', E::Unit->value.'.receipt_item_id')->join(E::Option->value, E::Option->value.'.id', '=', E::Unit->value.'.option_id')->join(E::Product->value, E::Product->value.'.id', '=', E::Option->value.'.product_id');
        if (! empty($filters['product_id'])) {
            $q->where(E::Product->value.'.id', $filters['product_id']);
        }if (! empty($filters['search'])) {
            $q->where(fn ($q) => $q->where(E::Product->value.'.name', 'like', '%'.$filters['search'].'%')->orWhere(E::Product->value.'.code', 'like', '%'.$filters['search'].'%'));
        }if (! empty($filters['location'])) {
            $q->where(E::Unit->value.'.location', $filters['location']);
        }
        $columns = [E::Unit->value.'.option_id', E::Unit->value.'.receipt_key', E::Unit->value.'.receipt_item_id', E::Unit->value.'.location', E::Unit->value.'.logistics_stage', E::Product->value.'.name as product', E::Product->value.'.code as code', E::Option->value.'.name as option', E::ReceiptItem->value.'.currency', E::ReceiptItem->value.'.unit_original', E::ReceiptItem->value.'.exchange_rate', E::ReceiptItem->value.'.unit_cost'];
        $group = array_map(fn ($col) => explode(' as ', $col)[0], $columns);
        $q->select($columns)->selectRaw("COUNT(*) quantity,SUM(COALESCE($u.original_cost_krw,$c.unit_cost,0)) known_krw,SUM(CASE WHEN COALESCE($u.original_cost_krw,$c.unit_cost) IS NULL THEN 1 ELSE 0 END) unknown_quantity,MIN($u.created_at) first_received,MAX($u.created_at) last_received,TIMESTAMPDIFF(DAY,MAX($u.created_at),?) min_days,TIMESTAMPDIFF(DAY,MIN($u.created_at),?) max_days,CASE WHEN $u.created_at<=? THEN 'risk' WHEN $u.created_at<=? THEN 'warning' ELSE 'normal' END aging_bucket", [$now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $a, $b])->groupBy([...$group, 'aging_bucket']);
        if (! empty($filters['aging_bucket'])) {
            $q->having('aging_bucket', $filters['aging_bucket']);
        }

return $q;
    }

    public function totals(int $tenant, array $filters = [], ?string $reference = null): array
    {
        $q = $this->grouped($tenant, $filters, $reference);
        $db = $q->getModel()->getConnection();
        $total = $db->query()->fromSub($q->toBase(), 'assets')->selectRaw('COALESCE(SUM(quantity),0) quantity,COALESCE(SUM(known_krw),0) known_krw,COALESCE(SUM(unknown_quantity),0) unknown_quantity')->first();
        $aging = $db->query()->fromSub($this->grouped($tenant, $filters, $reference)->toBase(), 'assets')->select('aging_bucket')->selectRaw('SUM(quantity) quantity,SUM(known_krw) known_krw,SUM(unknown_quantity) unknown_quantity')->groupBy('aging_bucket')->get()->map(fn ($r) => (array) $r)->all();
        $fifo = $this->records->query(E::FifoLayer, $tenant)->selectRaw('COALESCE(SUM(remaining_quantity),0) quantity,COALESCE(SUM(remaining_krw),0) known_krw,COALESCE(SUM(CASE WHEN remaining_krw IS NULL THEN remaining_quantity ELSE 0 END),0) unknown_quantity')->first();
        $pending = $this->records->query(E::Option, $tenant)->whereIn('id', app(LiveFifoService::class)->ownedUnits($tenant)->select('option_id'))->whereNotIn('id', $this->records->query(E::FifoOption, $tenant)->select('option_id'))->count();

        return ['actual' => array_map('intval', (array) $total), 'aging' => $aging, 'fifo' => ['quantity' => (int) $fifo->quantity, 'known_krw' => (int) $fifo->known_krw, 'unknown_quantity' => (int) $fifo->unknown_quantity], 'fifo_pending_options' => $pending];
    }
}
