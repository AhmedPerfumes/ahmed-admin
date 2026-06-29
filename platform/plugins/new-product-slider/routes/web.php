<?php

use Botble\Base\Facades\AdminHelper;
use Ahmed\NewProductSlider\Http\Controllers\NewProductSliderController;
use Illuminate\Support\Facades\Route;

AdminHelper::registerRoutes(function () {
    Route::group(['prefix' => 'new-product-sliders', 'as' => 'new-product-slider.'], function () {
        Route::resource('', NewProductSliderController::class)->parameters(['' => 'new-product-slider']);
    });
});
