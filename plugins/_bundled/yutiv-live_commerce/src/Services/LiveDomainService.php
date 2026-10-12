<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideHost;

class LiveDomainService extends LiveWorkflow
{
    public function save(int $tenant, int $actor, string $host, bool $active, bool $primary): LiveRecord
    {
        $host = TeeWideHost::canonical($host);
        $platform = parse_url((string) config('app.url'), PHP_URL_HOST);
        abort_if($host === $platform || ($platform && str_ends_with($host, '.'.$platform)), 422, __('yutiv-live_commerce::live.errors.e_90410c09ca'));
        $legacy = in_array($host, [TeeWideConfig::rootHost(), TeeWideConfig::liveHost()], true);
        $model = LiveTenant::findOrFail($tenant);
        abort_if($legacy && $model->slug !== config(TeeWideConfig::KEY.'.brand_tenant'), 422, __('yutiv-live_commerce::live.errors.e_f57ebe2ab1'));

        return $this->records->transaction(function () use ($tenant, $actor, $host, $active, $primary) {
            LiveTenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $existing = LiveRecord::entity(E::Domain)->newQuery()->where('host', $host)->lockForUpdate()->first();
            abort_if($existing && $existing->tenant_id !== $tenant, 409, __('yutiv-live_commerce::live.errors.e_f9d541d6ae'));
            if ($primary) {
                $this->records->query(E::Domain, $tenant)->update(['primary' => false]);
            }
            $domain = $existing ?? $this->records->create(E::Domain, $tenant, ['host' => $host, 'active' => false]);
            $domain->fill(['active' => $active, 'primary' => $primary])->save();
            $this->audit($tenant, $actor, 'domain.updated', ['host' => $host, 'active' => $active, 'primary' => $primary, 'dns_tls' => 'not_verified']);

            return $domain;
        });
    }

    public function url(LiveTenant $tenant): string
    {
        $domain = $this->records->query(E::Domain, $tenant->id)->where('active', true)->orderByDesc('primary')->orderBy('id')->first();
        if ($domain) {
            return 'https://'.$domain->host;
        }
        abort_unless($tenant->slug === config(TeeWideConfig::KEY.'.brand_tenant') && TeeWideConfig::hostsConfigured(), 409, __('yutiv-live_commerce::live.errors.e_33c0e3d189'));

        return 'https://'.TeeWideConfig::rootHost();
    }
}
