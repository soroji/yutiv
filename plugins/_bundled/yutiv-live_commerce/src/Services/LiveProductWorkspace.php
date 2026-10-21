<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Carbon\CarbonImmutable as Date;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;

class LiveProductWorkspace extends LiveWorkflow
{
    public const FILTERS = ['search', 'category_id', 'date_basis', 'from', 'to', 'stock', 'unit_state', 'sort', 'per_page', 'page'];

    public function products(int $tenant, string $timezone, array $filters)
    {
        $q = $this->records->query(E::Product, $tenant)->select('live_products.*');
        if (! empty($filters['search'])) {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')->orWhere('code', 'like', '%'.$filters['search'].'%'));
        }
        if (isset($filters['category_id']) && $filters['category_id'] !== '') {
            (string) $filters['category_id'] === '0' ? $q->whereNull('category_id') : $q->where('category_id', (int) $filters['category_id']);
        }
        $options = fn () => $this->records->query(E::Option, $tenant)->whereColumn('product_id', 'live_products.id');
        $units = fn () => $this->records->query(E::Unit, $tenant)->whereIn('option_id', $options()->select('id'));
        if (($filters['stock'] ?? '') === 'yes') {
            $q->whereExists($units()->where('state', 'available')->selectRaw('1'));
        }
        if (($filters['stock'] ?? '') === 'no') {
            $q->whereNotExists($units()->where('state', 'available')->selectRaw('1'));
        }
        if (! empty($filters['unit_state'])) {
            $q->whereExists($units()->where('state', $filters['unit_state'])->selectRaw('1'));
        }
        $bounds = [];
        if (! empty($filters['from'])) {
            $bounds['>='] = Date::parse($filters['from'], $timezone)->startOfDay()->utc();
        }
        if (! empty($filters['to'])) {
            $bounds['<'] = Date::parse($filters['to'], $timezone)->startOfDay()->addDay()->utc();
        }
        if (($filters['date_basis'] ?? 'created') === 'received' && $bounds) {
            $receipts = $this->records->query(E::Receipt, $tenant);
            $moves = $this->records->query(E::Movement, $tenant)->where('type', 'receive');
            foreach ($bounds as $op => $time) {
                $receipts->where('received_at', $op, $time);
                $moves->where('created_at', $op, $time);
            }
            $legacy = $this->records->query(E::Unit, $tenant)->whereIn('id', $moves->select('unit_id'))->whereNotIn('receipt_key', $this->records->query(E::Receipt, $tenant)->select('receipt_key'));
            $legacyProducts = $this->records->query(E::Option, $tenant)->whereIn('id', $legacy->select('option_id'))->select('product_id');
            $q->where(fn ($q) => $q->whereIn('id', $receipts->select('product_id'))->orWhereIn('id', $legacyProducts));
        } else {
            foreach ($bounds as $op => $time) {
                $q->where('created_at', $op, $time);
            }
        }
        $q->addSelect(['option_count' => $options()->selectRaw('COUNT(*)'), 'min_price' => $options()->selectRaw('MIN(price-discount)'), 'max_price' => $options()->selectRaw('MAX(price-discount)')]);
        foreach (['available', 'reserved', 'lost', 'as', 'defective', 'returned', 'disposed', 'shipped'] as $state) {
            $q->addSelect([$state.'_count' => $units()->where('state', $state)->selectRaw('COUNT(*)')]);
        }
        match ($filters['sort'] ?? 'newest') {
            'name' => $q->orderBy('name'),'oldest' => $q->orderBy('created_at'),default => $q->orderByDesc('created_at')
        };

        return $q->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();
    }

    public function query(E $entity, int $tenant)
    {
        return $this->records->query($entity, $tenant);
    }

    public function back(string $context = '', ?int $receipt = null): string
    {
        parse_str($context, $values);
        $values = array_intersect_key($values, array_flip(self::FILTERS));
        $values = array_filter($values, fn ($v) => is_scalar($v));
        if ($receipt) {
            $values['completed'] = $receipt;
        }

        return '/seller/products'.($values ? '?'.http_build_query($values) : '');
    }

    public function category(int $tenant, int $actor, array $data): void
    {
        $this->records->transaction(function () use ($tenant, $actor, $data) {
            if (! empty($data['id'])) {
                $row = $this->row(E::Category, $tenant, $data['id'], true);
                $row->update(['name' => $data['name'], 'active' => $data['active']]);
            } else {
                $this->records->create(E::Category, $tenant, ['name' => $data['name'], 'active' => true]);
            }
            $this->audit($tenant, $actor, 'category.saved');
        });
    }

    public function legacyPrint(int $tenant, int $id)
    {
        $row = $this->records->query(E::Audit, $tenant)->where('action', 'labels.print_requested')->findOrFail($id);
        abort_unless(is_array($row->data['units'] ?? null) && count($row->data['units']) <= 200, 404);

        return $row;
    }

    public function printRequest(int $tenant, int $actor, array $data): array
    {
        $qrs = $data['qrs'];
        sort($qrs);
        $data['qrs'] = $qrs;

        return $this->once($tenant, 'label-request:'.$data['operation_key'], $data, function () use ($tenant, $actor, $data, $qrs) {
            $units = $this->records->query(E::Unit, $tenant)->whereIn('uuid', $qrs)->get();
            abort_unless($units->count() === count($qrs), 404);
            if (! empty($data['original_id'])) {
                $old = $this->row(E::LabelRequest, $tenant, $data['original_id']);
                abort_if(array_diff($qrs, array_column($old->items, 'uuid')), 422, __('yutiv-live_commerce::live.print_target'));
            }
            $legacy = ! empty($data['legacy_audit_id']) ? $this->legacyPrint($tenant, (int) $data['legacy_audit_id']) : null;
            if ($legacy) {
                abort_if(array_diff($qrs, $legacy->data['units']), 422, __('yutiv-live_commerce::live.print_target'));
            }
            $options = $this->records->query(E::Option, $tenant)->whereIn('id', $units->pluck('option_id'))->get()->keyBy('id');
            $products = $this->records->query(E::Product, $tenant)->whereIn('id', $options->pluck('product_id'))->get()->keyBy('id');
            $receipts = $this->records->query(E::Receipt, $tenant)->whereIn('receipt_key', $units->pluck('receipt_key'))->pluck('id', 'receipt_key');
            $originals = $this->records->query(E::LabelRequestUnit, $tenant)->whereIn('unit_uuid', $qrs)->selectRaw('unit_uuid,MIN(label_request_id) original_id')->groupBy('unit_uuid')->pluck('original_id', 'unit_uuid')->all();
            $items = $units->map(fn ($unit) => ['uuid' => $unit->uuid, 'receipt_key' => $unit->receipt_key, 'receipt_id' => $receipts[$unit->receipt_key] ?? null, 'product_id' => $options[$unit->option_id]->product_id, 'option_id' => $unit->option_id, 'product' => $products[$options[$unit->option_id]->product_id]->name, 'option' => $options[$unit->option_id]->name, 'original_id' => $originals[$unit->uuid] ?? null, 'legacy_audit_id' => $legacy?->id, 'kind' => isset($originals[$unit->uuid]) || $legacy ? 'reprint' : 'first_recorded_request'])->all();
            $record = $this->records->create(E::LabelRequest, $tenant, ['actor_id' => $actor, 'operation_key' => $data['operation_key'], 'original_id' => $data['original_id'] ?? null, 'items' => $items, 'width' => $data['width'], 'height' => $data['height'], 'copies' => $data['copies'], 'total_labels' => count($qrs) * $data['copies']]);
            foreach ($qrs as $qr) {
                $this->records->create(E::LabelRequestUnit,$tenant,['label_request_id' => $record->id, 'unit_uuid' => $qr]);
            }
            $this->audit($tenant,$actor,'labels.print_requested',['request_id' => $record->id, 'physical_print_verified' => false]);

            return ['request_id' => $record->id, 'total_labels' => $record->total_labels, 'physical_print_verified' => false];
        });
    }
}
