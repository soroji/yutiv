<?php

namespace Modules\Yutiv\PersonalStore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Yutiv\PersonalStore\Models\LineSnapshot;
use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;

class ReconcileCommand extends Command
{
    use LocksMaintenance;

    protected $signature = 'yutiv-personal_store:reconcile {--enqueue : 현재 확정·환불 상태를 outbox에 재등록} {--repair-closure : 계층 closure 복구}';

    protected $description = '개인 스토어 closure·리워드 잔액 정합성을 검사합니다.';

    public function handle(StoreRepositoryInterface $stores): int
    {
        return $this->runLocked(fn () => $this->reconcile($stores));
    }

    private function reconcile(StoreRepositoryInterface $stores): int
    {
        $closure = $stores->rebuildClosure((bool) $this->option('repair-closure'));
        $errors = $closure['missing_or_wrong_depth'] + $closure['extra'];
        $this->line(json_encode($closure, JSON_THROW_ON_ERROR));
        $missingCreated = $missingState = 0;
        foreach (LineSnapshot::orderBy('id')->cursor() as $snapshot) {
            $key = 'created:'.$snapshot->line_uuid;
            if (! DB::table('personal_store_outbox')->where('event_key', $key)->exists()) {
                $missingCreated++;
                if ($this->option('enqueue')) {
                    $stores->outbox($key, 'created', ['snapshot_id' => $snapshot->id, 'snapshot' => $snapshot->payload]);
                }
            }
            $version = (int) DB::table('personal_store_line_states')->where('snapshot_id', $snapshot->id)->value('capture_version');
            if ($version > 0 && ! DB::table('personal_store_outbox')->where('event_key', 'state:'.$snapshot->line_uuid.':'.$version)->exists()) {
                $missingState++;
            }
        }
        $this->line(json_encode(['missing_created_outbox' => $missingCreated, 'missing_latest_state_outbox' => $missingState, 'pending_outbox' => DB::table('personal_store_outbox')->whereNull('processed_at')->count(), 'review_lines' => DB::table('personal_store_line_states')->where('review_required', true)->count()], JSON_THROW_ON_ERROR));
        if (! $this->option('enqueue')) {
            $errors += $missingCreated + $missingState;
        }
        foreach (DB::table('personal_store_wallets')->orderBy('store_id')->cursor() as $wallet) {
            $consistent = DB::transaction(function () use ($wallet) {
                $current = DB::table('personal_store_wallets')->where('store_id', $wallet->store_id)->lockForUpdate()->first();
                $available = (int) DB::table('personal_store_ledger')->where('store_id', $wallet->store_id)->sum('available_delta');
                $debt = (int) DB::table('personal_store_ledger')->where('store_id', $wallet->store_id)->sum('debt_delta');

                return $available === (int) $current->available && $debt === (int) $current->debt;
            }, 3);
            if (! $consistent) {
                $errors++;
                $this->error('스토어 '.$wallet->store_id.' 원장 집계 불일치');
            }
        }
        if ($this->option('enqueue')) {
            DB::table('personal_store_line_snapshots')->select('order_id')->distinct()->orderBy('order_id')->chunk(200, function ($rows) use ($stores) {
                foreach ($rows as $row) {
                    $stores->captureOrderState((int) $row->order_id);
                }
            });
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
