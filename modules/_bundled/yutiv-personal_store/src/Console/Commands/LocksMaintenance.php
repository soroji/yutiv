<?php

namespace Modules\Yutiv\PersonalStore\Console\Commands;

use Closure;
use Illuminate\Support\Facades\DB;

/** 두 유지보수 명령을 같은 MySQL 세션 잠금으로 직렬화합니다. 연결 종료 시 자동 해제됩니다. */
trait LocksMaintenance
{
    private function runLocked(Closure $callback): int
    {
        $connection = DB::connection();
        $key = 'yutiv-ps:'.substr(hash('sha256', $connection->getDatabaseName().$connection->getTablePrefix()), 0, 40);
        if ((int) $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$key], false)->acquired !== 1) {
            $this->warn('Personal store maintenance is already running; skipped.');

            return self::SUCCESS;
        }
        try {
            return $callback();
        } finally {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$key], false);
        }
    }
}
