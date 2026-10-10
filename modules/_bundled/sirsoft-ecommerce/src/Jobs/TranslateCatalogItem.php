<?php

namespace Modules\Sirsoft\Ecommerce\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Services\Translation\CatalogTranslationService;

class TranslateCatalogItem implements ShouldQueue
{
    use Queueable;

    // Releasing for a concurrency slot is not a provider retry.
    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public int $created;

    public function __construct(public string $jobId, public string $itemId)
    {
        $this->created = time();
    }

    public function retryUntil(): \DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->created + 900);
    }

    public function handle(CatalogTranslationService $service): void
    {
        if (! $service->process($this->jobId, $this->itemId)) {
            $this->release(5);
        }
    }
}
