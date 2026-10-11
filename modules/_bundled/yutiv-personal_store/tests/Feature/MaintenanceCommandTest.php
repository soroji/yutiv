<?php

namespace Modules\Yutiv\PersonalStore\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Yutiv\PersonalStore\Tests\ModuleTestCase;

class MaintenanceCommandTest extends ModuleTestCase
{
    public function test_outbox_and_reconciliation_skip_the_same_mysql_maintenance_lock(): void
    {
        $config = DB::connection()->getConfig();
        $config = array_replace($config, $config['write'] ?? []);
        $config['host'] = is_array($config['host']) ? $config['host'][0] : $config['host'];
        $pdo = new \PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password']);
        $key = 'yutiv-ps:'.substr(hash('sha256', $config['database'].$config['prefix']), 0, 40);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$key]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
        try {
            $this->artisan('yutiv-personal_store:outbox')->expectsOutput('Personal store maintenance is already running; skipped.')->assertSuccessful();
            $this->artisan('yutiv-personal_store:reconcile', ['--enqueue' => true])->expectsOutput('Personal store maintenance is already running; skipped.')->assertSuccessful();
        } finally {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$key]);
        }
        $this->artisan('yutiv-personal_store:outbox', ['--limit' => 0])->assertFailed();
        $this->artisan('yutiv-personal_store:outbox', ['--max-seconds' => 0])->assertFailed();
        $this->artisan('yutiv-personal_store:outbox', ['--limit' => 1, '--max-seconds' => 1])->assertSuccessful();
        $this->assertSame(0, DB::table('personal_store_ledger')->count());
    }
}
