<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sirsoft\Ecommerce\Models\CatalogTranslationJob;

class PruneCatalogTranslationsCommand extends Command
{
    protected $signature = 'ecommerce:prune-catalog-translations';

    protected $description = 'Remove temporary catalog translation jobs older than 24 hours';

    public function handle(): int
    {
        CatalogTranslationJob::where('created_at', '<', now()->subDay())->delete();

        return self::SUCCESS;
    }
}
