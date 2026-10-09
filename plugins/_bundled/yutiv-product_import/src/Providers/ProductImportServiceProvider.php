<?php

namespace Plugins\Yutiv\ProductImport\Providers;

use App\Extension\BasePluginServiceProvider;
use App\Extension\Traits\CachesPluginStatus;
use Plugins\Yutiv\ProductImport\Console\DispatchImports;

class ProductImportServiceProvider extends BasePluginServiceProvider
{
    use CachesPluginStatus;

    protected string $pluginIdentifier = 'yutiv-product_import';

    public function register(): void
    {
        parent::register();
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/import.php', 'yutiv-product-import');
    }

    public function boot(): void
    {
        if (in_array($this->pluginIdentifier, self::getActivePluginIdentifiers(), true) && $this->app->runningInConsole()) {
            $this->commands([DispatchImports::class]);
        }
    }
}
