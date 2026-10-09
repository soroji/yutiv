<?php

namespace Plugins\Yutiv\ProductImport\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugins\Yutiv\ProductImport\Enums\ImportStatus as S;
use Plugins\Yutiv\ProductImport\Http\Requests\ImportRowsRequest;
use Plugins\Yutiv\ProductImport\Jobs\ImportProductJob;
use Plugins\Yutiv\ProductImport\Models\ImportIdentity;
use Plugins\Yutiv\ProductImport\Models\ImportRow;
use Plugins\Yutiv\ProductImport\Models\ImportRun;
use Plugins\Yutiv\ProductImport\Support\Workbook;

class ImportService
{
    public function preview(string $path, string $filename, User $user): ImportRun
    {
        $book = app(Workbook::class)->read($path);
        $result = app(ImportRowsRequest::class)->validateWorkbook($book);

        return DB::transaction(function () use ($result, $filename, $user) {
            $run = ImportRun::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'filename' => mb_substr($filename, 0, 255),
                'status' => $result['errors'] ? S::Invalid : S::Preview, 'errors' => $result['errors']]);
            foreach ($result['rows'] as $row) {
                $run->rows()->create($row + ['status' => S::Preview]);
            }

            return $run;
        });
    }

    public function revalidate(ImportRow $row): array
    {
        $source = $row->source;
        $book = ['상품' => [$row->source_row => $source['product']], '옵션' => []];
        foreach ($source['options'] as [$rn,$opt]) {
            $book['옵션'][$rn] = $opt;
        }
        $locale = app()->getLocale();
        try {
            app()->setLocale(array_key_first($row->payload['name'] ?? []) ?? $locale);

            return app(ImportRowsRequest::class)->validateWorkbook($book, $row->id);
        } finally {
            app()->setLocale($locale);
        }
    }

    public function confirm(ImportRun $run): void
    {
        $this->queueConnection();
        DB::transaction(function () use ($run) {
            $run = ImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status === S::Queued) {
                return;
            } // Idempotent retry after a lost response.
            if ($run->status !== S::Preview) {
                Workbook::reject('검증 오류를 수정한 후 새 파일을 올려주세요.');
            }
            $results = [];
            $errors = [];
            foreach ($run->rows()->orderBy('id')->get() as $row) {
                $r = $this->revalidate($row);
                $results[$row->id] = $r['rows'][0];
                $errors = array_merge($errors, $r['errors']);
            }
            if ($errors) {
                throw ValidationException::withMessages(['file' => array_map(fn ($e) => $e['sheet'].' '.$e['row'].'행 '.$e['column'].': '.$e['message'], $errors)]);
            }
            foreach ($run->rows()->get() as $row) {
                // UNIQUE reservation also protects concurrent workbooks/workers.
                try {
                    ImportIdentity::create(['management_code' => strtolower($row->management_code), 'row_id' => $row->id]);
                } catch (QueryException $e) {
                    if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                        throw $e;
                    }
                    Workbook::reject('상품 '.$row->source_row.'행 관리코드가 다른 확정 작업과 중복됩니다. 새 코드로 다시 업로드하세요.');
                }
                $row->update(['payload' => $results[$row->id]['payload'], 'image_urls' => $results[$row->id]['image_urls'], 'status' => S::Queued]);
            }
            $run->update(['status' => S::Queued]);
        });
        $this->dispatch($run->id);
    }

    public function retry(ImportRun $run): void
    {
        $this->queueConnection();
        DB::transaction(function () use ($run) {
            ImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            foreach ($run->rows()->where('status', S::Failed->value)->lockForUpdate()->get() as $row) {
                $r = $this->revalidate($row);
                if ($r['errors']) {
                    throw ValidationException::withMessages(['file' => array_map(fn ($e) => $e['sheet'].' '.$e['row'].'행 '.$e['column'].': '.$e['message'], $r['errors'])]);
                }
                $row->update(['status' => S::Queued, 'dispatched_at' => null, 'error' => null]);
            }
        });
        $this->dispatch($run->id);
    }

    /** Persistent queued rows are an outbox; scheduler recovers crashes before/after dispatch. */
    public function dispatch(?string $runId = null): int
    {
        $connection = $this->queueConnection();
        $count = 0;
        ImportRow::whereIn('status', [S::Queued->value, S::Processing->value])
            ->when($runId, fn ($q) => $q->where('run_id', $runId))
            ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(10)))
            ->orderBy('id')->chunkById(100, function ($rows) use (&$count, $connection) {
                foreach ($rows as $row) {
                    ImportProductJob::dispatch($row->id)->onConnection($connection)->onQueue('yutiv-product-import');
                    $row->update(['dispatched_at' => now()]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueConnection(): string
    {
        $connection = config('yutiv-product-import.connection', 'database');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)
            || (int) config('queue.connections.'.$connection.'.retry_after', 0) < 600) {
            Workbook::reject('일괄등록용 비동기 작업 설정과 재시도 대기 시간(600초 이상)을 확인하세요.');
        }

        return $connection;
    }

    public function summary(ImportRun $run): array
    {
        $rows = $run->rows()->orderBy('source_row')->get();
        $counts = array_fill_keys(['total', 'succeeded', 'failed', 'queued', 'processing', 'preview'], 0);
        $counts['total'] = $rows->count();
        foreach ($rows as $r) {
            $counts[$r->status->value]++;
        }

        return ['id' => $run->id, 'filename' => $run->filename, 'status' => $run->status->value, 'counts' => $counts, 'errors' => $run->errors ?? [],
            'rows' => $rows->map(fn ($r) => ['row' => $r->source_row, 'management_code' => $r->management_code, 'name' => $r->source['product'][1] ?? '',
                'price' => $r->source['product'][3] ?? '', 'stock' => $r->source['product'][4] ?? '',
                'category_codes' => $r->source['product'][2] ?? '', 'image_count' => count($r->image_urls),
                'options' => array_map(fn ($o) => ['row' => $o[0], 'identifier' => $o[1][1] ?? '', 'values' => implode(' / ', array_filter([$o[1][3] ?? '', $o[1][5] ?? '', $o[1][7] ?? ''])),
                    'adjustment' => $o[1][8] ?? '0', 'stock' => $o[1][9] ?? ''], $r->source['options']),
                'status' => $r->status->value, 'product_code' => $r->product_code, 'error' => $r->error])->all()];
    }
}
