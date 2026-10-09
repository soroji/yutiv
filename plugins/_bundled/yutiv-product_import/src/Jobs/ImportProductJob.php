<?php

namespace Plugins\Yutiv\ProductImport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugins\Yutiv\ProductImport\Services\ImportProcessor;

class ImportProductJob implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $timeout = 450;

    public int $tries = 3;

    public function __construct(public int $rowId) {}

    public function handle(ImportProcessor $processor): void
    {
        $processor->process($this->rowId);
    }
}
