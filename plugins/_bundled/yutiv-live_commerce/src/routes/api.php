<?php

use Illuminate\Support\Facades\Route;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\Admin\LiveManagementController;

Route::get('admin/office', [LiveManagementController::class, 'index'])->name('admin.office');
Route::post('admin/{tenant}/logo', [LiveManagementController::class, 'logo'])->whereNumber('tenant')->name('admin.logo');
Route::post('admin/{tenant}/{action}', [LiveManagementController::class, 'store'])->whereNumber('tenant')->where('action', 'tenant|domain|account|reset-password|permission|lock|legacy')->name('admin.store');
