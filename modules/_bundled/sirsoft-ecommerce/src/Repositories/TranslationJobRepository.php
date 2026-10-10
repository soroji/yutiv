<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\CatalogTranslationItemStatus as ItemStatus;
use Modules\Sirsoft\Ecommerce\Models\CatalogTranslationJob;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\TranslationJobRepositoryInterface;

class TranslationJobRepository implements TranslationJobRepositoryInterface
{
    public function find(string $id): CatalogTranslationJob
    {
        return CatalogTranslationJob::findOrFail($id);
    }

    public function createOnce(int $owner, array $data): CatalogTranslationJob
    {
        return DB::transaction(function () use ($owner, $data) {
            User::whereKey($owner)->lockForUpdate()->firstOrFail();
            $previous = CatalogTranslationJob::where('owner_id', $owner)->where('request_id', $data['request_id'])->first();
            if ($previous) {
                if ($previous->fingerprint !== $data['fingerprint']) {
                    throw ValidationException::withMessages(['request_id' => __('sirsoft-ecommerce::translation.changed_request')]);
                }

                return $previous;
            }
            $active = CatalogTranslationJob::where('owner_id', $owner)->where('cancelled', false)->where('created_at', '>', now()->subMinutes(15))->get();
            foreach ($active as $job) {
                foreach ($job->items as $item) {
                    if (in_array($item['status'], [ItemStatus::Pending->value, ItemStatus::Processing->value], true)) {
                        throw ValidationException::withMessages(['items' => __('sirsoft-ecommerce::translation.busy')]);
                    }
                }
            }

            return CatalogTranslationJob::create(['owner_id' => $owner, ...$data]);
        });
    }

    public function mutate(string $id, callable $callback): CatalogTranslationJob
    {
        return DB::transaction(function () use ($id, $callback) {
            $job = CatalogTranslationJob::whereKey($id)->lockForUpdate()->firstOrFail();
            $callback($job);
            $job->save();

            return $job;
        });
    }

    public function lockOwnerAndCountProcessing(int $owner, string $except): int
    {
        User::whereKey($owner)->lockForUpdate()->firstOrFail();

        return CatalogTranslationJob::where('owner_id', $owner)->where('id', '!=', $except)->where('updated_at', '>', now()->subMinutes(4))->get()
            ->sum(fn ($job) => count(array_filter($job->items, fn ($item) => $item['status'] === ItemStatus::Processing->value && ($item['started_at'] ?? 0) > time() - 210)));
    }
}
