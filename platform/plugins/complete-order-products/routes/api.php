<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => 'api',
    'prefix'     => 'api',
    'namespace'  => 'Ahmed\CompleteOrderProducts\Http\Controllers',
], function () {
    Route::get('complete-order-products', 'PublicCompleteOrderProductController@index');
});
