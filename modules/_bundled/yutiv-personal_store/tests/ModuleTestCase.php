<?php

namespace Modules\Yutiv\PersonalStore\Tests;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Route;
use Modules\Yutiv\PersonalStore\Listeners\RegistrationListener;
use Modules\Yutiv\PersonalStore\Listeners\StoreLayoutListener;
use Modules\Yutiv\PersonalStore\Listeners\StoreOrderListener;
use Modules\Yutiv\PersonalStore\Providers\PersonalStoreServiceProvider;

abstract class ModuleTestCase extends \Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase
{
    protected array $requiredExtensions = ['modules/sirsoft-ecommerce', 'modules/yutiv-personal_store'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(PersonalStoreServiceProvider::class);
        Route::prefix('api/modules/yutiv-personal_store')->name('api.modules.yutiv-personal_store.')->middleware('api')->group(base_path('modules/_bundled/yutiv-personal_store/src/routes/api.php'));
        foreach ([StoreOrderListener::class, RegistrationListener::class, StoreLayoutListener::class] as $listener) {
            foreach ($listener::getSubscribedHooks() as $name => $config) {
                $callback = [$this->app->make($listener), $config['method']];
                if (($config['type'] ?? null) === 'filter') {
                    HookManager::addFilter($name, $callback);
                } else {
                    HookManager::addAction($name, $callback);
                }
            }
        }
    }
}
