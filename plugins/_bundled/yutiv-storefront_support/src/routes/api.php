<?php

use Illuminate\Support\Facades\Route;
use Plugins\Yutiv\StorefrontSupport\Http\Controllers\BusinessInfoController;

Route::get('/business-info', [BusinessInfoController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('business-info.show');
