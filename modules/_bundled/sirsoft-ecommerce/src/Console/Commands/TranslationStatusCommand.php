<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sirsoft\Ecommerce\Enums\TranslationConnectionStatus;
use Modules\Sirsoft\Ecommerce\Services\Translation\TranslationProviderInterface;

/** Read-only deployment probe. Never prints credentials, provider URL/model or catalog text. */
class TranslationStatusCommand extends Command
{
    protected $signature = 'ecommerce:translation-status';

    protected $description = 'Report safe translation configuration and queue readiness';

    public function handle(TranslationProviderInterface $provider): int
    {
        $config = config('sirsoft-ecommerce-translation');
        $present = is_array($config) && isset($config['driver']);
        $status = TranslationConnectionStatus::detect($config, $provider->configured())->value;
        $this->line(json_encode([
            'config_present' => $present,
            'status' => $status,
            'cache_loaded' => app()->configurationIsCached(),
            'queue_database' => config('queue.connections.ecommerce-translation.driver') === 'database',
            'retry_after_240' => config('queue.connections.ecommerce-translation.retry_after') === 240,
        ], JSON_THROW_ON_ERROR));

        return $present ? self::SUCCESS : self::FAILURE;
    }
}
