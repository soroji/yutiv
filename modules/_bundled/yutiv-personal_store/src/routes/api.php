<?php

use Illuminate\Support\Facades\Route;
use Modules\Yutiv\PersonalStore\Http\Controllers\HeadquartersController;
use Modules\Yutiv\PersonalStore\Http\Controllers\OperatorController;
use Modules\Yutiv\PersonalStore\Http\Controllers\PublicStoreController;

Route::get('stores/{slug}', [PublicStoreController::class, 'show'])->name('stores.show');
Route::post('stores/{slug}/origin', [PublicStoreController::class, 'origin'])->name('stores.origin');
Route::get('membership-proof', [PublicStoreController::class, 'membershipProof'])->name('membership-proof');
Route::get('stores/{slug}/products', [PublicStoreController::class, 'products'])->name('stores.products');
Route::get('stores/{slug}/products/{code}', [PublicStoreController::class, 'detail'])->name('stores.products.show');
Route::middleware('auth:sanctum')->group(function () {
    Route::get('operator', [OperatorController::class, 'index'])->name('operator.index');
    Route::post('operator', [OperatorController::class, 'update'])->name('operator.update');
    Route::get('operator/catalog', [OperatorController::class, 'catalog'])->name('operator.catalog');
    Route::get('operator/categories', [OperatorController::class, 'categories'])->name('operator.categories');
    Route::get('admin', [HeadquartersController::class, 'index'])->name('admin.index');
    Route::post('admin', [HeadquartersController::class, 'update'])->name('admin.update');
});
