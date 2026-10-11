<?php

namespace Modules\Yutiv\PersonalStore\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Yutiv\PersonalStore\Models\Store;
use Modules\Yutiv\PersonalStore\Repositories\StoreRepository;
use Modules\Yutiv\PersonalStore\Tests\ModuleTestCase;
use Symfony\Component\Process\Process;

/** 별도 격리 DB에서만 실행하는 실제 두 연결/프로세스 경쟁 검증입니다. */
class MySqlConcurrencyTest extends ModuleTestCase
{
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        // Worker-visible fixtures are committed. Force the next case to rebuild the isolated DB.
        RefreshDatabaseState::$migrated = false;
    }

    public function test_mysql_concurrent_outbox_unique_ledger_wallet_and_opposite_moves(): void
    {
        if (getenv('YUTIV_PERSONAL_STORE_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Explicit isolated MySQL concurrency opt-in required.');
        }
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertStringStartsWith('yutiv_isolated_', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel(), 'Subprocess fixtures must be committed.');
        $repo = app(StoreRepository::class);
        $owner = User::factory()->create();
        $repo->manage($owner->id, 'apply', ['slug' => 'concurrent-root']);
        $a = Store::where('user_id', $owner->id)->firstOrFail();
        $a->update(['status' => 'active']);
        $order = OrderFactory::new()->create();
        $events = [];
        for ($i = 0; $i < 2; $i++) {
            DB::transaction(fn () => $repo->snapshot(['line_uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'store_id' => $a->id, 'created_at' => now(), 'payload' => ['quantity' => 10, 'reward' => ['allocations' => [['store_id' => $a->id, 'amount' => 1000]]]]]));
            $id = DB::table('personal_store_line_snapshots')->max('id');
            $repo->outbox('concurrent:'.$i, 'state', ['snapshot_id' => $id, 'confirmed_quantity' => 10, 'cancelled_confirmed_quantity' => 0, 'review_required' => false]);
            $events[] = DB::table('personal_store_outbox')->max('id');
        }
        $processes = [];
        $outputs = [];
        foreach (array_merge($events, $events) as $id) {
            $process = new Process([PHP_BINARY, dirname(__DIR__).'/Support/MySqlWorker.php', base_path(), 'outbox', (string) $id], base_path(), null, null, 60);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $outputs[] = $process->getOutput();
        }
        $this->assertSame(2000, (int) DB::table('personal_store_wallets')->where('store_id', $a->id)->value('available'), implode('; ', $outputs));
        $this->assertSame(2, DB::table('personal_store_ledger')->where('kind', 'earn')->count());
        $bOwner = User::factory()->create();
        $repo->manage($bOwner->id, 'apply', ['slug' => 'concurrent-other']);
        $b = Store::where('user_id', $bOwner->id)->firstOrFail();
        $b->update(['status' => 'active']);
        $processes = [];
        foreach ([[$a->id, $b->id], [$b->id, $a->id]] as [$child, $parent]) {
            $process = new Process([PHP_BINARY, dirname(__DIR__).'/Support/MySqlWorker.php', base_path(), 'move', (string) $child, (string) $parent, (string) $owner->id], base_path(), null, null, 60);
            $process->start();
            $processes[] = $process;
        }
        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $outputs[] = trim($process->getOutput());
        }
        sort($outputs);
        $this->assertSame(['cycle-rejected', 'moved'], $outputs);
        $this->assertSame(0, $repo->rebuildClosure()['missing_or_wrong_depth']);
    }
}
