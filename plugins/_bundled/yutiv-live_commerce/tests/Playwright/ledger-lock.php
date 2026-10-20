<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Services\LiveLedgerService;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 5);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$meta = DB::connection()->getPdo()->query('SELECT DATABASE() db,@@port port')->fetch(PDO::FETCH_ASSOC);
if (! str_contains(str_replace('\\', '/', base_path()), '/live-workflow-browser') || $meta['db'] !== 'live_workflow_isolated_browser' || (int) $meta['port'] !== 33319) {
    exit(90);
}
$input = json_decode(file_get_contents(base_path('ledger040-fixture.json')), true);
$tenant = LiveTenant::where('slug', 'golfif')->firstOrFail();
$started = false;
$worker = null;
$locking = [];
DB::listen(function ($query) use (&$started, &$worker, &$locking) {
    if (str_contains(strtolower($query->sql), 'for update') || str_contains(strtolower($query->sql), 'lock in share')) {
        $locking[] = $query->sql;
    }
    if (! $started && str_starts_with($query->sql, 'insert into `it_live_reports`')) {
        $started = true;
        $begin = hrtime(true);
        $process = new Process([PHP_BINARY, __DIR__.'/ledger-lock-worker.php', bin2hex(random_bytes(6))], base_path(), null, null, 12);
        $process->mustRun();
        $worker = ['milliseconds' => round((hrtime(true) - $begin) / 1e6, 2), 'result' => json_decode($process->getOutput(), true)];
    }
});
$service = app(LiveLedgerService::class);
$before = $service->report($tenant->id, 0, '2026-10-01', '2026-10-31', 'month', $input['product']);
$uuid = $service->save($tenant->id, 0, '2026-10-01', '2026-10-31', 'month', $input['product']);
$saved = $service->saved($tenant->id, 0, $uuid);
$sum = 0;
for ($part = 1; $part <= $saved['parts']; $part++) {
    foreach ($service->saved($tenant->id, 0, $uuid, $part)['rows'] as $row) {
        if ($row->kind === 'option') {
            $sum += $row->data['stock']['closing'];
        }
    }
}
$after = $service->report($tenant->id, 0, '2026-10-01', '2026-10-31', 'month', $input['product']);
if (! $started || ! $worker['result']['ok'] || $locking || $sum !== $before['stock_totals']['closing'] || $after['stock_totals']['closing'] !== $sum + 1 || $saved['report']->result['stock_totals']['closing'] !== $sum) {
    throw new RuntimeException('Snapshot/writer concurrency mismatch');
}
echo json_encode(['worker' => $worker, 'locking_queries' => $locking, 'before_closing' => $before['stock_totals']['closing'], 'saved_summary_closing' => $saved['report']->result['stock_totals']['closing'], 'saved_detail_closing' => $sum, 'after_closing' => $after['stock_totals']['closing']], JSON_PRETTY_PRINT);
