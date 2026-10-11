<?php

namespace Modules\Yutiv\PersonalStore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;

class ProcessOutboxCommand extends Command
{
    use LocksMaintenance;

    protected $signature = 'yutiv-personal_store:outbox {--limit=500} {--max-seconds=50}';

    protected $description = '개인 스토어 미처리 outbox를 멱등 처리합니다.';

    public function handle(StoreRepositoryInterface $stores): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $seconds = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10000 || $seconds === false || $seconds < 1 || $seconds > 3600) {
            $this->error('limit must be 1..10000; max-seconds must be 1..3600.');

            return self::FAILURE;
        }

        return $this->runLocked(function () use ($stores, $limit, $seconds) {
            $deadline = hrtime(true) + $seconds * 1000000000;
            foreach (DB::table('personal_store_outbox')->whereNull('processed_at')->orderBy('id')->limit($limit)->pluck('id') as $id) {
                if (hrtime(true) >= $deadline) {
                    break;
                }
                $stores->processOutbox((int) $id);
            }

            return self::SUCCESS;
        });
    }
}
