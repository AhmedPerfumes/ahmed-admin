<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => 'api',
    'prefix'     => 'api',
    'namespace'  => 'Ahmed\NewProductSlider\Http\Controllers',
], function () {
    Route::get('new-product-sliders', 'PublicNewproductsliderController@index');
});
