<?php

namespace Plugins\Yutiv\ProductImport;

use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Models\Menu;
use Plugins\Yutiv\ProductImport\Listeners\ImportMenuListener;

class Plugin extends AbstractPlugin
{
    public function getHookListeners(): array
    {
        return [ImportMenuListener::class];
    }

    public function getPermissions(): array
    {
        return ['name' => ['ko' => '본사 상품 일괄등록', 'en' => 'Headquarters product import'], 'categories' => [[
            'identifier' => 'headquarters', 'name' => ['ko' => '본사 상품', 'en' => 'Headquarters products'],
            'permissions' => [['action' => 'create', 'name' => ['ko' => '엑셀 신규 등록', 'en' => 'Import new products'],
                'type' => 'admin', 'roles' => ['admin']]],
        ]]];
    }

    public function getAdminMenus(): array
    {
        return [['slug' => 'yutiv-product_import', 'name' => ['ko' => '엑셀 상품 일괄등록', 'en' => 'Product spreadsheet import'],
            'url' => '/admin/plugins/yutiv-product_import/products', 'icon' => 'fas fa-file-excel', 'order' => 15]];
    }

    public function activate(): bool
    {
        $this->syncProductMenu();

        return parent::activate();
    }

    public function syncProductMenu(): void
    {
        $parent = Menu::where('slug', 'sirsoft-ecommerce-products')->first();
        foreach ($this->getAdminMenus() as $menu) {
            app(ExtensionMenuSyncHelper::class)->syncMenuRecursive($menu, ExtensionOwnerType::Plugin,
                $this->getIdentifier(), $parent?->id);
        }

    }

    public function getSchedules(): array
    {
        return [['command' => 'yutiv:product-import-dispatch', 'schedule' => 'everyMinute',
            'description' => '확정된 상품 일괄등록 작업 재전송']];
    }
}
