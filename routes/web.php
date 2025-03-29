<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SmsaController;
use App\Http\Controllers\DynamicSectionController;

// Define a route group with a prefix
Route::prefix('admin/ecommerce/smsa')->group(function () {
    Route::get('/', [SmsaController::class, 'index'])->name('smsa.index');
    Route::get('/getData', [SmsaController::class, 'getData'])->name('smsa.data');
    Route::get('/edit/{id}', [SmsaController::class, 'edit'])->name('smsa.edit');
    Route::post('/bulkEdit', [SmsaController::class, 'bulkEdit'])->name('smsa.bulk-edit');
    Route::post('/submit', [SmsaController::class, 'submit'])->name('smsa.submit');
    Route::post('/bulkSubmit', [SmsaController::class, 'bulkSubmit'])->name('smsa.bulk-submit');
    Route::post('/bulkPrint', [SmsaController::class, 'bulkPrint'])->name('smsa.bulk-print');
    Route::get('/track/{awb}', [SmsaController::class, 'track'])->name('smsa.track');
});

Route::prefix('admin/ecommerce/dynamic')->group(function () {
    Route::get('/', [DynamicSectionController::class, 'index'])->name('dynamic.index');
    Route::post('/submit', [DynamicSectionController::class, 'submit'])->name('dynamic.section.submit');
});


