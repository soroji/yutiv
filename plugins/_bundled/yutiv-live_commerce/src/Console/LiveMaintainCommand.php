<?php

namespace Plugins\Yutiv\LiveCommerce\Console;

use Illuminate\Console\Command;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Plugin;
use Plugins\Yutiv\LiveCommerce\Services\LiveExpenseService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFifoService;
use Plugins\Yutiv\LiveCommerce\Services\LiveIncentiveService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOrderService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LiveMaintainCommand extends Command
{
    protected $signature = 'live:maintain {--batch=100} {--seconds=45}';

    protected $description = '라이브 예약 만료 및 스냅샷 기반 인센티브 대사';

    public function handle(LiveOrderService $orders, LiveIncentiveService $incentives): int
    {
        $connection = LiveRecord::entity(E::Order)->getConnection();
        $database = $connection->selectFromWriteConnection('SELECT DATABASE() AS name')[0]->name;
        $lock = 'live-maintain:'.substr(hash('sha256', $database.$connection->getTablePrefix()), 0, 40);
        if ((int) $connection->selectFromWriteConnection('SELECT GET_LOCK(?, 0) AS acquired', [$lock])[0]->acquired !== 1) {
            $this->line('이미 처리 중입니다.');

            return self::SUCCESS;
        }
        $deadline = hrtime(true) + min(45, max(1, (int) $this->option('seconds'))) * 1000000000;
        $batch = min(200, max(1, (int) $this->option('batch')));
        $processed = 0;
        try {
            $due = LiveRecord::entity(E::Order)->newQuery()->whereIn('payment_state', ['pending', 'review'])->whereNull('reservation_released_at')->where('expires_at', '<=', now())->orderBy('id')->limit($batch)->get();
            foreach ($due as $order) {
                if (hrtime(true) >= $deadline) {
                    break;
                }
                $orders->expire($order->tenant_id, $order->id);
                $processed++;
            }
            $cache = (new Plugin)->getCache();
            $key = 'maintenance.cursor.'.hash('sha256', $lock);
            $cursor = (int) $cache->get($key, 0);
            $lines = LiveRecord::entity(E::OrderLine)->newQuery()->where('id', '>', $cursor)->orderBy('id')->limit($batch)->get();
            if ($lines->isEmpty()) {
                $cache->put($key, 0, 86400);
            }
            foreach ($lines as $line) {
                if (hrtime(true) >= $deadline) {
                    break;
                }
                try {
                    $incentives->sync($line->tenant_id, $line->id);
                } catch (HttpException $error) {
                    if ($error->getStatusCode() !== 409) {
                        throw $error;
                    }
                    $this->warn('관리 잠금으로 인센티브 대사 보류: '.$line->id);
                }
                $cache->put($key, $line->id, 86400);
                $processed++;
            }
            // One rule-month or one option per iteration; no scheduler configuration changes.
            $ruleKey = $key.'.recurring';
            $rules = LiveRecord::entity(E::RecurringExpense)->newQuery()->where('id', '>', (int) $cache->get($ruleKey, 0))->where('active', true)->where('next_on', '<=', now('UTC')->addDay()->format('Y-m-d'))->orderBy('id')->limit($batch)->get();
            if ($rules->isEmpty()) {
                $cache->put($ruleKey, 0, 86400);
            }
            foreach ($rules as $rule) {
                if (hrtime(true) >= $deadline) {
                    break;
                }
                $tenant = LiveTenant::find($rule->tenant_id);
                if (! $tenant) {
                    continue;
                }
                try {
                    app(LiveExpenseService::class)->generateRecurring($tenant->id, $rule->id, now($tenant->timezone)->format('Y-m-d'));
                    $processed++;
                } catch (HttpException $error) {
                    if ($error->getStatusCode() !== 409) {
                        throw $error;
                    }
                    $this->warn('관리 잠금으로 정기 지출 보류: '.$rule->id);
                }
                $cache->put($ruleKey, $rule->id, 86400);
            }
            $fifoKey = $key.'.fifo';
            $states = LiveRecord::entity(E::FinanceState)->newQuery()->where('id', '>', (int) $cache->get($fifoKey, 0))->orderBy('id')->select('id', 'tenant_id')->limit($batch)->get();
            if ($states->isEmpty()) {
                $cache->put($fifoKey, 0, 86400);
            }
            foreach ($states as $state) {
                if (hrtime(true) >= $deadline) {
                    break;
                }
                $fifo = app(LiveFifoService::class);
                $options = LiveRecord::entity(E::Option)->newQuery()->where('tenant_id', $state->tenant_id)->whereIn('id', $fifo->ownedUnits($state->tenant_id)->select('option_id'))->whereNotIn('id', LiveRecord::entity(E::FifoOption)->newQuery()->where('tenant_id', $state->tenant_id)->select('option_id'))->orderBy('id')->limit($batch)->get(['id']);
                foreach ($options as $option) {
                    if (hrtime(true) >= $deadline) {
                        break;
                    }
                    $fifo->prepare($state->tenant_id, 0, $option->id, 'maintenance-option-'.$option->id);
                    $processed++;
                }
                $cache->put($fifoKey, $state->id, 86400);
            }
            $this->line('처리 완료: '.$processed.'건');

            return self::SUCCESS;
        } finally {
            $connection->selectFromWriteConnection('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
