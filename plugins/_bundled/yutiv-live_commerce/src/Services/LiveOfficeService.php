<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveCatalogRequest;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\LiveForms;

class LiveOfficeService extends LiveWorkflow
{
    public function brand(int $tenant, int $actor, array $data): array
    {
        return $this->records->transaction(function () use ($tenant, $actor, $data) {
            $record = LiveTenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $record->fill(['name' => $data['name'], 'contact_links' => ['kakao' => $data['kakao_url'] ?? null, 'telegram' => $data['telegram_url'] ?? null]])->save();
            $this->audit($tenant, $actor, 'brand.updated', ['fields' => ['name', 'contact_links']]);

            return ['saved' => true];
        });
    }

    public function data(int $tenant, string $section, ?int $edit = null): array
    {
        $data = ['section' => $section, 'fields' => [], 'rows' => collect(), 'editing' => null, 'choices' => []];
        if (in_array($section, ['products', 'options', 'broadcasts', 'channels', 'showhosts', 'broadcast-items', 'sale-links', 'banks'], true)) {
            $entity = LiveForms::entity($section);
            $data['rows'] = $this->records->query($entity, $tenant)->orderByDesc('id')->paginate(20);
            if ($section==='sale-links' && request()->integer('broadcast_id')) $data['rows']=$this->records->query($entity,$tenant)->where('broadcast_id',request()->integer('broadcast_id'))->orderByDesc('id')->paginate(20)->withQueryString();
            $data['fields'] = LiveForms::fields($section);
            if ($edit) {
                $data['editing'] = $this->row($entity, $tenant, $edit);
            }
            foreach ($data['fields'] as $name => $field) {
                if ($field['choices'] instanceof E) {
                    $data['choices'][$name] = $this->records->query($field['choices'], $tenant)->orderBy('id')->limit(500)->get();
                }
            }
            if ($section === 'broadcasts') {
                $data['hosts'] = $this->records->query(E::Showhost, $tenant)->where('active', true)->get();
            }
        } elseif ($section === 'orders') {
            $data['rows'] = $this->records->query(E::Order, $tenant)->orderByDesc('id')->paginate(20);
        } elseif (in_array($section, ['inventory', 'receive', 'ship', 'returns', 'labels'], true)) {
            $data['rows'] = $this->records->query(E::Unit, $tenant)->orderByDesc('id')->paginate(20);
            $data['choices']['option_id'] = $this->records->query(E::Option, $tenant)->where('active', true)->get();
            $data['choices']['orders'] = $this->records->query(E::Order, $tenant)->where('payment_state', 'paid')->orderByDesc('id')->limit(100)->get(['id', 'uuid', 'total', 'delivery_state']);
            $data['choices']['return_lines'] = $this->records->query(E::OrderLine, $tenant)->where('shipped', '>', 0)->orderByDesc('id')->limit(100)->get();
            if ($section === 'labels') {
                $query = $this->records->query(E::Unit, $tenant)->orderBy('id');
                if (request()->filled('batch')) {
                    $receipt = $this->row(E::Receipt, $tenant, (int) request('batch'));
                    $query->where('receipt_key', $receipt->receipt_key);
                    $data['receiptBatch'] = $receipt;
                }
                if (request()->filled('receipt')) {
                    $unit = $this->records->query(E::Unit, $tenant)->where('uuid', request('receipt'))->firstOrFail();
                    $query->where('receipt_key', $unit->receipt_key);
                }
                if (request()->filled('unit')) {
                    $query->where('uuid', request('unit'));
                }
                abort_if((clone $query)->count() > 200, 422, __('yutiv-live_commerce::live.label_limit'));
                $data['rows'] = $query->get();
                foreach ($data['rows'] as $unit) {
                    $option = $this->row(E::Option, $tenant, $unit->option_id);
                    $product = $this->row(E::Product, $tenant, $option->product_id);
                    $unit->label_name = $product->name.' · '.$option->name;
                }
                $data['labelWidth'] = (int) request('width', 50);
                $data['labelHeight'] = (int) request('height', 30);
            }
        } elseif ($section === 'staff') {
            $data['rows'] = $this->records->query(E::Staff, $tenant)->orderBy('id')->get();
            $data['accounts'] = TeeWideUser::where('tenant_id', $tenant)->get(['id', 'name', 'username', 'status']);
        } elseif ($section === 'audit') {
            $data['rows'] = $this->records->query(E::Audit, $tenant)->orderByDesc('id')->paginate(20);
        } elseif ($section === 'incentives') {
            $data['rows'] = $this->records->query(E::Incentive, $tenant)->orderByDesc('id')->paginate(20);
            $data['balances'] = $this->records->query(E::Incentive, $tenant)->select('beneficiary', 'kind')->selectRaw('SUM(amount) AS amount')->groupBy('beneficiary', 'kind')->get();
            foreach ($data['balances'] as $balance) {
                [$kind, $id] = explode(':', $balance->beneficiary);
                $balance->display_name = $this->row($kind === 'host' ? E::Showhost : E::Channel, $tenant, (int) $id)->name;
            }
        }
        if ($section === 'inventory' && request()->filled('unit')) {
            $data['unitDetail'] = $this->records->query(E::Unit, $tenant)->where('uuid', request('unit'))->firstOrFail();
            $data['movements'] = $this->records->query(E::Movement, $tenant)->where('unit_id', $data['unitDetail']->id)->orderByDesc('id')->limit(100)->get();
        }
        if ($section === 'statistics') {
            foreach (['broadcast_id' => E::Broadcast, 'channel_id' => E::Channel, 'showhost_id' => E::Showhost, 'product_id' => E::Product, 'option_id' => E::Option] as $field => $entity) {
                $data['choices'][$field] = $this->records->query($entity, $tenant)->orderBy('id')->limit(500)->get(['id', 'name']);
            }
        }

        return $data;
    }

    public function query(E $entity, int $tenant): Builder
    {
        return $this->records->query($entity, $tenant);
    }

    public function catalogData(LiveCatalogRequest $request): array
    {
        $data = $request->validated();
        unset($data['broadcast_workspace'],$data['list'],$data['detail_query'],$data['channel_payment'],$data['separate_sales']);
        foreach (['channel', 'host'] as $rate) {
            $key = $rate.'_percent';
            if (isset($data[$key])) {
                $data[$rate.'_rate'] = IncentiveCalculator::rate((string) $data[$key]);
            }
            unset($data[$key]);
        }
        if (isset($data['host_shares'])) {
            $data['host_shares'] = array_map(fn ($rate) => IncentiveCalculator::rate((string) $rate), $data['host_shares']);
        }
        foreach (['starts_at', 'ends_at', 'sale_starts_at', 'sale_ends_at', 'expires_at'] as $date) {
            if (! empty($data[$date])) {
                $data[$date] = Carbon::parse($data[$date], LiveContext::requireTenant()->timezone)->utc();
            }
        }

        return $data;
    }
}
