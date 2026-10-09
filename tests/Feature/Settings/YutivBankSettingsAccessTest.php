<?php

namespace Tests\Feature\Settings;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\EcommerceSettingsController;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Tests\TestCase;

class YutivBankSettingsAccessTest extends TestCase
{
    private string $previousStorage;

    private string $fixture;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addPsr4('Modules\\Sirsoft\\Ecommerce\\', base_path('modules/_bundled/sirsoft-ecommerce/src'));
        }
        $this->previousStorage = storage_path();
        $this->fixture = storage_path('framework/testing/bank-access-'.uniqid());
        File::ensureDirectoryExists($this->fixture.'/logs');
        $this->app->useStoragePath($this->fixture);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->previousStorage);
        File::deleteDirectory($this->fixture);
        parent::tearDown();
    }

    public function test_existing_route_and_bank_screen_remain_in_menu_plan(): void
    {
        $layout = json_decode(File::get(base_path('plugins/_bundled/yutiv-admin_menu/config/menu-layout.json')), true);
        $this->assertSame('쇼핑몰 설정', $layout['settings_parent']['name']['ko']);
        $this->assertArrayHasKey('sirsoft-ecommerce-settings', $layout['settings_children']);
        $routes = json_decode(File::get(base_path('modules/_bundled/sirsoft-ecommerce/resources/routes/admin.json')), true);
        $settings = collect($routes['routes'])->firstWhere('layout', 'admin_ecommerce_settings');
        $this->assertSame('*/admin/ecommerce/settings', $settings['path']);
        $this->assertSame('sirsoft-ecommerce.settings.read', $settings['permission']);
        $tab = json_decode(File::get(base_path('modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_order_settings.json')), true);
        $this->assertStringContainsString('order_settings', $tab['if']);
        $this->assertStringContainsString('bank_accounts_card', json_encode($tab));
    }

    public function test_account_save_and_disable_are_visible_in_public_response_without_manual_cache_clear(): void
    {
        $service = new EcommerceSettingsService;
        $controller = new EcommerceSettingsController($service);
        // Prime the service cache before saving to exercise invalidation.
        $service->getPublicPaymentSettings();
        $account = ['bank_code' => '004', 'account_number' => 'TEST-ONLY', 'account_holder' => 'Fixture', 'is_active' => true, 'is_default' => true];
        $this->assertTrue($service->saveSettings(['order_settings' => ['bank_accounts' => [$account]]]));
        $response = $controller->payment();
        $this->assertSame(200, $response->getStatusCode());
        $accounts = $response->getData(true)['data']['order_settings']['bank_accounts'];
        $this->assertSame('TEST-ONLY', $accounts[0]['account_number']);
        $this->assertTrue($accounts[0]['is_active']);
        $this->assertNotEmpty($accounts[0]['bank_name']);
        $this->assertFileExists(storage_path('framework/testing/modules/sirsoft-ecommerce/settings/order_settings.json'));
        $account['is_active'] = false;
        $this->assertTrue($service->saveSettings(['order_settings' => ['bank_accounts' => [$account]]]));
        $accounts = $controller->payment()->getData(true)['data']['order_settings']['bank_accounts'];
        $this->assertCount(0, array_filter($accounts, fn ($a) => $a['is_active']));
        $this->assertFalse((new EcommerceSettingsService)->getPublicPaymentSettings()['bank_accounts'][0]['is_active']);
    }

    public function test_existing_request_rejects_incomplete_active_account(): void
    {
        $request = StoreEcommerceSettingsRequest::create('/api/modules/sirsoft-ecommerce/admin/settings', 'PUT');
        $rules = $request->rules();
        $bankRules = array_filter($rules, fn ($key) => str_starts_with($key, 'order_settings.bank_accounts'), ARRAY_FILTER_USE_KEY);
        $validator = app('validator')->make(['order_settings' => ['bank_accounts' => [['bank_code' => '', 'account_number' => '', 'account_holder' => '', 'is_active' => true, 'is_default' => true]]]], $bankRules);
        $this->assertTrue($validator->fails());
        foreach (['bank_code', 'account_number', 'account_holder'] as $field) {
            $this->assertTrue($validator->errors()->has('order_settings.bank_accounts.0.'.$field));
        }
    }
}
