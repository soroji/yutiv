<?php

namespace Plugins\Yutiv\ProductImport\Services;

use App\Extension\ModuleManager;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Services\SequenceService;
use Plugins\Yutiv\ProductImport\Enums\ImportStatus as S;
use Plugins\Yutiv\ProductImport\Models\ImportIdentity;
use Plugins\Yutiv\ProductImport\Models\ImportRow;
use Plugins\Yutiv\ProductImport\Support\ImageFetcher;
use Plugins\Yutiv\ProductImport\Support\ImportAccess;
use Plugins\Yutiv\ProductImport\Support\Workbook;

class ImportProcessor
{
    public function __construct(private ProductService $products, private ProductImageService $images,
        private SequenceService $sequence, private ImportService $imports, private ImageFetcher $fetcher) {}

    public function process(int $rowId): void
    {
        $previous = Auth::user();
        $row = null;
        try {
            // Persist deterministic file cleanup journal before opening the product transaction.
            DB::transaction(function () use ($rowId, &$row) {
                $row = ImportRow::whereKey($rowId)->lockForUpdate()->firstOrFail();
                if (! in_array($row->status, [S::Queued, S::Processing], true)) {
                    return;
                }
                $user = User::find($row->run->user_id);
                abort_unless(ImportAccess::allowed($user), 403);
                Auth::setUser($user);
                $this->cleanup($row);
                $storage = app(ModuleManager::class)->getModule('sirsoft-ecommerce')->getStorageFor('images');
                $row->update(['product_code' => $row->product_code ?? $this->sequence->generateCode(SequenceType::PRODUCT),
                    'storage_disk' => $storage->getDisk(), 'status' => S::Processing, 'attempts' => $row->attempts + 1]);
            });
            DB::transaction(function () use ($rowId, &$row) {
                $row = ImportRow::whereKey($rowId)->lockForUpdate()->firstOrFail();
                if (! in_array($row->status, [S::Queued, S::Processing], true)) {
                    return;
                }
                $user = User::find($row->run->user_id);
                abort_unless(ImportAccess::allowed($user), 403);
                Auth::setUser($user);
                $r = $this->imports->revalidate($row);
                if ($r['errors']) {
                    Workbook::reject(implode(' ', array_column($r['errors'], 'message')));
                }
                $identity = ImportIdentity::where('row_id', $row->id)->lockForUpdate()->firstOrFail();
                if ($identity->product_id) {
                    $row->update(['status' => S::Succeeded, 'product_id' => $identity->product_id]);

                    return;
                }
                $this->cleanup($row); // Recover files left by a killed worker; product does not exist yet.
                $payload = $row->payload;
                $payload['product_code'] = $row->product_code;
                $product = $this->products->create($payload);
                // Keep import publication policy even when an installed extension filters defaults.
                $product->update(['display_status' => ProductDisplayStatus::HIDDEN,
                    'sales_status' => ProductSalesStatus::SUSPENDED]);
                foreach ($row->image_urls as $url) {
                    $file = $this->fetcher->fetch($url, $this->downloadPath($row));
                    try {
                        $image = $this->images->upload($file, $product->id, 'main');
                        $storage = app(ModuleManager::class)->getModule('sirsoft-ecommerce')->getStorageFor('images');
                        if ($storage->getDisk() !== $image->disk) {
                            $storage = $storage->withDisk($image->disk);
                        }
                        if (! $storage->exists('images', $image->path)) {
                            Workbook::reject('이미지를 저장하지 못했습니다.');
                        }
                    } finally {
                        if (is_file($file->getRealPath())) {
                            unlink($file->getRealPath());
                        }
                    }
                }
                $identity->update(['product_id' => $product->id]);
                $row->update(['product_id' => $product->id, 'status' => S::Succeeded, 'error' => null]);
            });
        } catch (\Throwable $e) {
            // Rollback removes product/options/categories/media rows; files require independent cleanup.
            if ($row) {
                DB::transaction(function () use ($rowId, $e) {
                    $failed = ImportRow::whereKey($rowId)->lockForUpdate()->first();
                    if ($failed && $failed->status !== S::Succeeded) {
                        try {
                            $this->cleanup($failed);
                        } catch (\Throwable $cleanup) {
                            Log::error('Import media cleanup failed', ['row_id' => $rowId, 'exception' => $cleanup]);
                        }
                        $failed->update(['status' => S::Failed, 'error' => mb_substr($e instanceof ValidationException ? implode(' ', array_merge(...array_values($e->errors()))) : __('상품 등록 또는 이미지 처리가 실패했습니다. 관리자에게 작업 ID를 알려주세요.'), 0, 2000)]);
                    }
                });
            }
            Log::warning('Product import failed', ['row_id' => $rowId, 'exception' => $e]);
        } finally {
            if ($previous) {
                Auth::setUser($previous);
            } else {
                Auth::forgetUser();
            }
        }
    }

    public function cleanup(ImportRow $row): void
    {
        if (is_file($this->downloadPath($row))) {
            unlink($this->downloadPath($row));
        }
        if (! $row->product_code || Product::where('product_code', $row->product_code)->exists()) {
            return;
        }
        $storage = app(ModuleManager::class)->getModule('sirsoft-ecommerce')->getStorageFor('images');
        if ($row->storage_disk && $row->storage_disk !== $storage->getDisk()) {
            $storage = $storage->withDisk($row->storage_disk);
        }
        // Code was allocated by SequenceService and persisted, never taken from workbook input.
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $row->product_code)) {
            Workbook::reject('생성 상품코드의 저장 경로가 잘못되었습니다.');
        }
        $directory = 'products/'.$row->product_code;
        $storage->deleteDirectory('images', $directory);
        if ($storage->files('images', $directory)) {
            Workbook::reject('실패 작업의 이미지 정리가 완료되지 않았습니다. 저장소 연결을 확인하세요.');
        }
    }

    private function downloadPath(ImportRow $row): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'yutiv-import-'.$row->run_id.'-'.$row->id.'.image';
    }
}
