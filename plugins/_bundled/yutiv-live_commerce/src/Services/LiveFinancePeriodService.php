<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Carbon\CarbonImmutable;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;

class LiveFinancePeriodService extends LiveWorkflow
{
    public function assertOpen(int $tenant, ?string $date = null): void
    {
        if (! $this->records->query(E::FinanceState, $tenant)->exists()) {
            return;
        }
        $date ??= CarbonImmutable::now(LiveTenant::findOrFail($tenant)->timezone)->format('Y-m-d');
        abort_if($this->records->query(E::FinanceClosing, $tenant)->where('active', true)->where('from_on', '<=', $date)->where('to_on', '>=', $date)->exists(), 409, __('yutiv-live_commerce::live.finance_closed'));
    }
}
