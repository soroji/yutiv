<?php

namespace Plugins\Yutiv\SesMonitor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Plugins\Yutiv\SesMonitor\Models\SesEventLog;
use Plugins\Yutiv\SesMonitor\Support\SesConfig;

/**
 * `yutiv:ses-prune` — 보존기간이 지난 SES 이벤트를 삭제한다.
 *
 * append-only 테이블에서 유일하게 삭제가 일어나는 지점이다. 기본 90일이며
 * `SES_EVENT_RETENTION_DAYS` 로 조정한다.
 *
 * `notification_logs` 는 건드리지 않는다 — 코어의 `PruneNotificationLogsCommand`
 * 가 자기 보존정책으로 따로 관리한다.
 */
class PruneSesEventLogsCommand extends Command
{
    /** @var string */
    protected $signature = 'yutiv:ses-prune
        {--days= : 보존 기간(일). 미지정 시 SES_EVENT_RETENTION_DAYS 또는 90}
        {--dry-run : 삭제하지 않고 대상 건수만 출력한다}
        {--chunk=1000 : 한 번에 삭제할 행 수}';

    /** @var string */
    protected $description = 'SES 이벤트 로그의 보존기간 경과분을 삭제합니다';

    public function handle(): int
    {
        if (! Schema::hasTable('ses_event_logs')) {
            $this->warn('ses_event_logs 테이블이 없습니다. 마이그레이션을 먼저 실행하세요.');

            return self::SUCCESS;
        }

        $days = $this->option('days') !== null ? (int) $this->option('days') : SesConfig::retentionDays();

        if ($days < 1) {
            $this->error('보존 기간은 1일 이상이어야 합니다.');

            return self::INVALID;
        }

        $cutoff = now()->subDays($days);
        $chunk = max(1, (int) $this->option('chunk'));

        $targets = SesEventLog::where('occurred_at', '<', $cutoff)->count();

        $this->line('기준 시각: '.$cutoff->toDateTimeString().' ('.$days.'일 보존)');
        $this->line('대상: '.$targets.'건');

        if ($this->option('dry-run')) {
            $this->info('dry-run 이므로 삭제하지 않았습니다.');

            return self::SUCCESS;
        }

        if ($targets === 0) {
            return self::SUCCESS;
        }

        // 큰 테이블에서 단일 DELETE 가 락을 오래 잡지 않도록 나눠서 지운다.
        $deleted = 0;
        do {
            $affected = SesEventLog::where('occurred_at', '<', $cutoff)->limit($chunk)->delete();
            $deleted += $affected;
        } while ($affected > 0);

        Log::info('ses_monitor.pruned', [
            'plugin' => 'yutiv-ses_monitor',
            'retention_days' => $days,
            'cutoff' => $cutoff->toDateTimeString(),
            'deleted' => $deleted,
        ]);

        $this->info('삭제 완료: '.$deleted.'건');

        return self::SUCCESS;
    }
}
