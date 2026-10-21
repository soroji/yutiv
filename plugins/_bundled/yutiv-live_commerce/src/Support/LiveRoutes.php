<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

use Illuminate\Support\Facades\Route;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\Auth\LoginController;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\Auth\RegisterController;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\Customer\LiveCustomerController;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\Customer\LiveShopController;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\LiveAssetController;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\LiveMediaController;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\PortalController;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveOfficeController;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveWorkflowController;
use Plugins\Yutiv\LiveCommerce\Http\Middleware\RequireTeeWideUser;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;

final class LiveRoutes
{
    public static function register(array $stack): void
    {
        foreach (['root' => TeeWideConfig::rootHost(), 'live' => TeeWideConfig::liveHost()] as $role => $host) {
            Route::domain($host)->middleware($stack)->name('teewide.ops.'.$role.'.')->group(fn () => self::operations());
        }
        try {
            $domains = LiveRecord::entity(E::Domain)->newQuery()->orderBy('id')->get(['id', 'host']);
        } catch (\Throwable) {
            return;
        }
        foreach ($domains as $domain) {
            if (in_array($domain->host, [TeeWideConfig::rootHost(), TeeWideConfig::liveHost()], true)) {
                continue;
            }
            Route::domain($domain->host)->middleware($stack)->name('teewide.domain.'.$domain->id.'.')->group(function () {
                Route::get('/', [PortalController::class, 'index'])->name('portal');
                Route::get('/login', [LoginController::class, 'show'])->name('login');
                Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
                Route::get('/register', [RegisterController::class, 'show'])->name('register');
                Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:10,1')->name('register.store');
                Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
                Route::get('/account', [LiveCustomerController::class, 'account'])->middleware(RequireTeeWideUser::class)->name('account');
                self::operations();
            });
        }
    }

    private static function operations(): void
    {
        Route::post('/seller/media/{kind}', [LiveMediaController::class, 'store'])->where('kind', 'logo|product')->middleware(RequireTeeWideUser::class)->name('seller.media');
        Route::get('/live-media/{kind}/{record}', [LiveMediaController::class, 'show'])->where('kind', 'logo|product')->where('record', '[0-9]+|logo')->name('media');
        Route::get('/seller/accounting/reports/{uuid}', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveFinanceController::class,'saved'])->whereUuid('uuid')->middleware(RequireTeeWideUser::class)->name('finance.report');
        Route::get('/seller/accounting/evidence/{id}', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveFinanceController::class,'download'])->whereNumber('id')->middleware(RequireTeeWideUser::class)->name('finance.evidence.show');
        Route::post('/seller/accounting/evidence', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveFinanceController::class,'evidence'])->middleware(RequireTeeWideUser::class)->name('finance.evidence.store');
        Route::post('/seller/accounting/actions/{action}', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveFinanceController::class,'store'])->where('action','[a-z-]+')->middleware(RequireTeeWideUser::class)->name('finance.action');
        Route::get('/seller/accounting/{section?}', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveFinanceController::class,'index'])->where('section','overview|sales|expenses|accounts|assets|reports')->middleware(RequireTeeWideUser::class)->name('finance.index');
        Route::get('/seller/product-stock', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveStockCountController::class,'index'])->middleware(RequireTeeWideUser::class)->name('products.stock');
        Route::post('/seller/product-stock/{action}', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveStockCountController::class,'store'])->where('action','draft|confirm')->middleware(RequireTeeWideUser::class)->name('products.stock.store');
        Route::get('/seller/inventory', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveStockCountController::class,'legacy'])->middleware(RequireTeeWideUser::class)->name('products.stock.legacy');
        Route::get('/seller/products', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveProductWorkspaceController::class, 'index'])->middleware(RequireTeeWideUser::class)->name('products.index');
        Route::get('/seller/products/{product}', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveProductWorkspaceController::class, 'detail'])->whereNumber('product')->middleware(RequireTeeWideUser::class)->name('products.show');
        Route::get('/seller/product-receive', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveProductWorkspaceController::class, 'receiveForm'])->middleware(RequireTeeWideUser::class)->name('products.receive');
        Route::get('/seller/labels', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveProductWorkspaceController::class, 'legacyLabels'])->middleware(RequireTeeWideUser::class)->name('products.legacy-labels');
        Route::get('/seller/receive', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveProductWorkspaceController::class, 'legacyReceive'])->middleware(RequireTeeWideUser::class)->name('products.legacy-receive');
        Route::get('/seller/product-qr', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveProductWorkspaceController::class, 'qr'])->middleware(RequireTeeWideUser::class)->name('products.qr');
        Route::post('/seller/product-actions/{action}', [\Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller\LiveProductWorkspaceController::class, 'action'])->where('action','category|product|preview|print')->middleware(RequireTeeWideUser::class)->name('products.action');
        Route::get('/seller/login', [LoginController::class, 'show'])->name('seller.login');
        Route::post('/seller/reports', [LiveOfficeController::class, 'saveReport'])->middleware(RequireTeeWideUser::class)->name('seller.reports.store');
        Route::get('/seller/reports/{uuid}', [LiveOfficeController::class, 'savedReport'])->whereUuid('uuid')->middleware(RequireTeeWideUser::class)->name('seller.reports.show');
        Route::post('/seller/receipt', [LiveOfficeController::class, 'receive'])->middleware(RequireTeeWideUser::class)->name('seller.receipt');
        Route::get('/seller/order/{uuid}', [LiveOfficeController::class, 'order'])->whereUuid('uuid')->middleware(RequireTeeWideUser::class)->name('seller.order');
        Route::get('/seller/{section?}', [LiveOfficeController::class, 'index'])->where('section', '[a-z-]+')->middleware(RequireTeeWideUser::class)->name('seller');
        Route::post('/seller/catalog/{entity}/{record?}', [LiveOfficeController::class, 'store'])->where('entity', 'products|options|channels|showhosts|broadcasts|broadcast-items|sale-links|banks')->whereNumber('record')->middleware(RequireTeeWideUser::class)->name('seller.catalog');
        Route::post('/seller/tasks/{action}', [LiveWorkflowController::class, 'store'])->where('action', '[a-z-]+')->middleware(RequireTeeWideUser::class)->name('seller.task');
        Route::get('/shop', [LiveShopController::class, 'index'])->name('shop');
        Route::get('/shop/products/{product}', [LiveShopController::class, 'product'])->whereNumber('product')->name('product');
        Route::get('/shop/broadcasts/{broadcast}', [LiveShopController::class, 'broadcast'])->whereNumber('broadcast')->name('broadcast');
        Route::get('/s/{code}', [LiveShopController::class, 'saleLink'])->where('code', '[a-f0-9]{48}')->name('sale-link');
        Route::get('/cart', [LiveCustomerController::class, 'cart'])->name('cart');
        Route::get('/orders/{uuid}', [LiveCustomerController::class, 'order'])->whereUuid('uuid')->middleware(RequireTeeWideUser::class)->name('order');
        Route::get('/my', [LiveCustomerController::class, 'account'])->middleware(RequireTeeWideUser::class)->name('my');
        Route::get('/password/forgot', [LiveCustomerController::class, 'password'])->name('password.forgot');
        Route::get('/password/reset/{token}', [LiveCustomerController::class, 'password'])->where('token', '[a-f0-9]{64}')->name('password.reset');
        Route::get('/password/change', [LiveCustomerController::class, 'password'])->middleware(RequireTeeWideUser::class)->name('password.change');
        Route::post('/customer/tasks/{action}', [LiveCustomerController::class, 'store'])->where('action', 'forgot|reset|password|profile|address|cart|order')->middleware('throttle:20,1')->name('customer.task');
        Route::post('/orders/{uuid}/{action}', [LiveCustomerController::class, 'store'])->whereUuid('uuid')->where('action', 'cancel|return|confirm')->middleware(RequireTeeWideUser::class)->name('order.task');
        Route::dualSuffixSegment('/live-assets', 'js', [LiveAssetController::class, 'script'])->name('assets.js');
        Route::dualSuffixSegment('/live-styles', 'css', [LiveAssetController::class, 'styles'])->name('assets.css');
    }
}
