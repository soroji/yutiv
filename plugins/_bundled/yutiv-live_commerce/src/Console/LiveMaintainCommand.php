<?php

namespace Plugins\Yutiv\LiveCommerce\Console;

use Illuminate\Console\Command;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;
use Plugins\Yutiv\LiveCommerce\Plugin;
use Plugins\Yutiv\LiveCommerce\Services\LiveIncentiveService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOrderService;

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
                $incentives->sync($line->tenant_id, $line->id);
                $cache->put($key, $line->id, 86400);
                $processed++;
            }
            $this->line('처리 완료: '.$processed.'건');

            return self::SUCCESS;
        } finally {
            $connection->selectFromWriteConnection('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
