<?php

use Illuminate\Support\Facades\Route;
use Plugins\Yutiv\ProductImport\Http\Controllers\ImportController;
use Plugins\Yutiv\ProductImport\Http\Middleware\HeadquartersOnly;

Route::prefix('admin/product-imports')->middleware(['auth:sanctum', HeadquartersOnly::class])->group(function () {
    Route::get('template', [ImportController::class, 'template'])->name('admin.product-imports.template');
    Route::get('/', [ImportController::class, 'index'])->name('admin.product-imports.index');
    Route::post('/', [ImportController::class, 'preview'])->middleware('throttle:10,1')->name('admin.product-imports.preview');
    Route::get('{id}', [ImportController::class, 'show'])->whereUuid('id')->name('admin.product-imports.show');
    Route::post('{id}/confirm', [ImportController::class, 'confirm'])->whereUuid('id')->name('admin.product-imports.confirm');
    Route::post('{id}/retry', [ImportController::class, 'retry'])->whereUuid('id')->name('admin.product-imports.retry');
    Route::get('{id}/result', [ImportController::class, 'result'])->whereUuid('id')->name('admin.product-imports.result');
});
