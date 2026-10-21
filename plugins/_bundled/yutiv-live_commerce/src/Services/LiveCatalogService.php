<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;

class LiveCatalogService extends LiveWorkflow
{
    public function save(E $entity, int $tenant, int $actor, array $data, ?int $id = null): LiveRecord
    {
        return $this->records->transaction(function () use ($entity, $tenant, $actor, $data, $id) {
            LiveTenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            foreach (['category_id' => E::Category, 'product_id' => E::Product, 'channel_id' => E::Channel, 'bank_account_id' => E::Bank, 'broadcast_id' => E::Broadcast, 'option_id' => E::Option, 'showhost_id' => E::Showhost] as $field => $reference) {
                if (! empty($data[$field])) {
                    $this->row($reference, $tenant, $data[$field], true);
                }
            }
            if ($entity === E::Option) {
                $data['name'] = trim($data['name']);
                abort_if($this->records->query(E::Option, $tenant)->where('product_id', $data['product_id'])->where('name', $data['name'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(), 422, __('yutiv-live_commerce::live.receipt_option'));
                if ($id) {
                    abort_unless($this->row($entity, $tenant, $id, true)->product_id === (int) $data['product_id'], 409, __('yutiv-live_commerce::live.errors.e_9a5489f976'));
                }
                abort_if(($data['discount'] ?? 0) > $data['price'], 422, __('yutiv-live_commerce::live.errors.e_cb154ac0cb'));
                if (! $id) {
                    $data['qr_code'] = (string) Str::uuid();
                }
            }
            if ($entity === E::Broadcast) {
                $data['host_shares'] = $data['host_shares'] ?? [];
                foreach (array_keys($data['host_shares'] ?? []) as $host) {
                    $this->row(E::Showhost, $tenant, (int) $host);
                }
                IncentiveCalculator::amounts(100000, $data['channel_rate'], $data['host_rate'], $data['channel_id'], $data['host_shares']);
                if ($id) {
                    $old = $this->row($entity, $tenant, $id, true);
                    $data['policy_version'] = $old->policy_version + 1;
                    abort_if($old->state === 'closed' && ($data['state'] ?? $old->state) === 'selling', 409, __('yutiv-live_commerce::live.errors.e_b56346d3d0'));
                }
            }
            if ($entity === E::BroadcastItem) {
                $broadcast = $this->row(E::Broadcast, $tenant, $data['broadcast_id']);
                $c = $data['channel_rate'] ?? $broadcast->channel_rate;
                $h = $data['host_rate'] ?? $broadcast->host_rate;
                abort_if($c + $h > 10000, 422);
            }
            if ($entity === E::SaleLink) {
                if (! $id) {
                    $data['code'] = bin2hex(random_bytes(24));
                }
                if (! empty($data['option_id'])) {
                    abort_unless($this->records->query(E::BroadcastItem, $tenant)->where('broadcast_id', $data['broadcast_id'])->where('option_id', $data['option_id'])->where('active', true)->exists(), 422);
                }
            }
            $before = $id ? $this->row($entity, $tenant, $id, true)->only(array_keys($data)) : null;
            if ($entity === E::Bank && isset($before['account_number'])) {
                $before['account_number'] = '***'.substr($before['account_number'], -4);
            }
            if ($entity === E::Bank && ! empty($data['default'])) {
                $this->records->query(E::Bank, $tenant)->update(['default' => false]);
            }
            $record = $id ? $this->row($entity, $tenant, $id, true) : $this->records->create($entity, $tenant, $data);
            if ($id) {
                $record->fill($data)->save();
            }
            // 계좌·개인정보·비밀번호·공개 판매코드는 일반 감사 JSON에 저장하지 않습니다.
            $this->audit($tenant, $actor, 'catalog.'.$entity->name, ['record_id' => $record->id, 'updated_fields' => array_keys($data)]);
            if ($entity === E::Bank || $entity === E::Broadcast) {
                $after = $record->only(array_keys($data));
                if ($entity === E::Bank && isset($after['account_number'])) {
                    $after['account_number'] = '***'.substr($after['account_number'], -4);
                }
                $this->audit($tenant, $actor, $entity === E::Bank ? 'bank.changed' : 'broadcast.policy_changed', ['record_id' => $record->id, 'before' => $before, 'after' => $after]);
            }

            return $record;
        });
    }

    public function attribution(int $tenant, int $broadcastId, int $optionId, ?string $code): array
    {
        $broadcast = $this->row(E::Broadcast, $tenant, $broadcastId, true);
        abort_unless($broadcast->state === 'selling' && $broadcast->sale_starts_at <= now() && $broadcast->sale_ends_at > now(), 409, __('yutiv-live_commerce::live.errors.e_cf1d42433a'));
        $item = $this->records->query(E::BroadcastItem, $tenant)->where('broadcast_id', $broadcastId)->where('option_id', $optionId)->where('active', true)->firstOrFail();
        $link = null;
        if ($code !== null && $code !== '') {
            $link = $this->records->query(E::SaleLink, $tenant)->where('code', $code)->where('broadcast_id', $broadcastId)->where('active', true)->firstOrFail();
            abort_if(($link->expires_at && $link->expires_at <= now()) || ($link->option_id && $link->option_id !== $optionId), 404);
        }

        return ['broadcast_id' => $broadcastId, 'channel_id' => $link?->channel_id ?? $broadcast->channel_id, 'showhost_id' => $link?->showhost_id,
            'sale_link_id' => $link?->id, 'contact' => $link?->contact ?? 'direct', 'policy_version' => $broadcast->policy_version,
            'channel_rate' => $item->channel_rate ?? $broadcast->channel_rate, 'host_rate' => $item->host_rate ?? $broadcast->host_rate,
            'host_shares' => $broadcast->host_shares];
    }
}
