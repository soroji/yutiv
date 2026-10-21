<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

use Plugins\Yutiv\LiveCommerce\Enums\StaffRole;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveOfficeController;

/** 기존 주 메뉴를 원본으로 삼아 모든 판매자 화면의 권한·순서·명칭을 일치시킵니다. */
final class LiveSellerNavigation
{
    public static function menu(StaffRole $role): array
    {
        $hidden = ['banks', 'statistics', 'ledger', 'inventory', 'labels', 'options', 'receive', 'product-receive', 'receipts'];
        $menu = [];
        foreach (LiveOfficeController::MENU as $key => $item) {
            if (in_array($key, $hidden, true)) {
                continue;
            }
            if ($key === 'products' && $role->allows('inventory')) {
                $item = ['상품 관리', 'inventory'];
            }
            if (! $role->allows($item[1])) {
                continue;
            }
            if ($key === 'accounting' && ! $role->allows('finance.read')) {
                $key = 'accounting/sales';
            }
            if (str_starts_with($key, 'accounting')) {
                $item[0] = '회계관리';
            }
            $menu[$key] = $item;
        }

        return $menu;
    }

    public static function active(string $path): string
    {
        $part = explode('/', trim($path, '/'));
        $section = $part[array_search('seller', $part, true) + 1] ?? '';
        if (in_array($section, ['accounting', 'reports'], true)) {
            return 'accounting';
        }
        if (in_array($section, ['broadcasts', 'channels', 'showhosts', 'broadcast-items'], true)) {
            return 'broadcasts';
        }
        if (in_array($section, ['products', 'product-receive', 'product-stock', 'product-qr', 'options', 'receive', 'receipts', 'labels', 'inventory'], true)) {
            return 'products';
        }

        return $section === 'order' ? 'orders' : ($section ?: 'dashboard');
    }
}
