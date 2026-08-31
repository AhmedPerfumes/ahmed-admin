<?php

use Botble\Base\Facades\AdminHelper;
use Ahmed\CompleteOrderProducts\Http\Controllers\CompleteOrderProductController;
use Illuminate\Support\Facades\Route;

AdminHelper::registerRoutes(function () {
    Route::group(['prefix' => 'complete-order-products', 'as' => 'complete-order-product.'], function () {
        Route::get('', [CompleteOrderProductController::class, 'index'])->name('index');
        Route::post('', [CompleteOrderProductController::class, 'store'])->name('store');
    });
});
