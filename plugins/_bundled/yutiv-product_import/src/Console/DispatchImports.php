<?php

namespace Plugins\Yutiv\ProductImport\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Yutiv\ProductImport\Enums\ImportStatus;
use Plugins\Yutiv\ProductImport\Models\ImportRow;
use Plugins\Yutiv\ProductImport\Services\ImportProcessor;
use Plugins\Yutiv\ProductImport\Services\ImportService;

class DispatchImports extends Command
{
    protected $signature = 'yutiv:product-import-dispatch';

    protected $description = '확정된 상품 일괄등록 큐 작업 복구';

    public function handle(ImportService $service): int
    {
        ImportRow::where('status', 'failed')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row) {
                    $locked = ImportRow::whereKey($row->id)->lockForUpdate()->first();
                    if ($locked?->status !== ImportStatus::Failed) {
                        return;
                    }
                    try {
                        app(ImportProcessor::class)->cleanup($locked);
                    } catch (\Throwable $e) {
                        Log::error('Import media cleanup retry failed', ['row_id' => $row->id, 'exception' => $e]);
                    }
                });
            }
        });
        $this->info((string) $service->dispatch());

        return self::SUCCESS;
    }
}
