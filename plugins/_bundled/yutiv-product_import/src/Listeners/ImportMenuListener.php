<?php

namespace Plugins\Yutiv\ProductImport\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Yutiv\ProductImport\Plugin;

/** Restore the cross-extension parent after the official plugin menu sync. */
class ImportMenuListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugins.updated' => ['method' => 'handle', 'type' => 'action', 'priority' => 100, 'sync' => true],
            'core.plugins.activated' => ['method' => 'handle', 'type' => 'action', 'priority' => 100, 'sync' => true],
        ];
    }

    public function handle(...$args): void
    {
        if (($args[0] ?? null) === 'yutiv-product_import') {
            (new Plugin)->syncProductMenu();
        }
    }
}
