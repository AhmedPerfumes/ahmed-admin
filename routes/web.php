<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SmsaController;
use App\Http\Controllers\DynamicSectionController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ProductReviewController;
use Botble\Ecommerce\Http\Controllers\ProductFragranceNoteController;


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
    Route::post('/submit', [DynamicSectionController::class, 'submit'])->name('newsletter.submit');
    Route::delete('/admin/dynamic-section/{id}', [DynamicSectionController::class, 'destroy'])->name('dynamic-section.destroy');
});

Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
Route::get('promotions/create', [PromotionController::class, 'create'])->name('promotions.create');
Route::get('promotions/data', [PromotionController::class, 'data'])->name('promotions.data');
Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
Route::get('promotions/{promotion}/edit', [PromotionController::class, 'edit'])->name('promotions.edit');
Route::put('promotions/{promotion}', [PromotionController::class, 'update'])->name('promotions.update');
Route::delete('/promotions/bulk-delete', [PromotionController::class, 'bulkDelete'])->name('promotions.bulkDelete');
Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy'])->name('promotions.destroy');

// Creates all the necessary admin pages for managing reviews under a single address
Route::resource('/admin/product-reviews', ProductReviewController::class);

if (is_in_admin(true)) {

    // This group ensures the routes are only for the admin panel,
    // require a user to be logged in, and gives them the correct name prefix.
    Route::group([
        'prefix' => 'admin/product-reviews',
        'as' => 'product-reviews.',
        'middleware' => ['web', 'auth'],
    ], function () {
        // ... (Your 'index', 'show', and 'destroy' routes are here) ...
        Route::get('/', [
            'as' => 'index',
            'uses' => '\App\Http\Controllers\ProductReviewController@index',
        ]);

        Route::get('/{product_review}', [
            'as' => 'show',
            'uses' => '\App\Http\Controllers\ProductReviewController@show',
        ]);

        // v-- ADD THIS NEW ROUTE FOR THE APPROVE ACTION --v
        Route::post('/{product_review}/approve', [
            'as' => 'approve',
            'uses' => '\App\Http\Controllers\ProductReviewController@approve',
        ]);

        Route::delete('/{product_review}', [
            'as' => 'destroy',
            'uses' => '\App\Http\Controllers\ProductReviewController@destroy',
        ]);
    });

    dashboard_menu()->registerItem([
        'id' => 'cms-plugins-product-reviews',
        'priority' => 5,
        'parent_id' => 'cms-plugins-ecommerce',
        'name' => 'Product Reviews',
        'icon' => 'ti ti-star',
        'url' => route('product-reviews.index'),
        'permissions' => ['product-reviews.index'],
    ]);

    // V V V PASTE THE NEW CODE STARTING HERE V V V

    // --- START: Fragrance Profiles ---
    Route::group([
        'prefix' => 'admin/product-fragrance-notes',
        'as' => 'product-fragrance-notes.',
        'middleware' => ['web', 'auth'],
    ], function () {
        Route::resource('', ProductFragranceNoteController::class)->parameters(['' => 'id']);
        Route::delete('items/destroy', [
            'as' => 'deletes',
            'uses' => '\Botble\Ecommerce\Http\Controllers\ProductFragranceNoteController@destroy',
            'permission' => 'products.destroy', // Reuse existing permission
        ]);
    });

    dashboard_menu()->registerItem([
        'id' => 'cms-plugins-product-fragrance-notes',
        'priority' => 6,
        'parent_id' => 'cms-plugins-ecommerce',
        'name' => 'Fragrance Profiles',
        'icon' => 'fa fa-vial',
        'url' => route('product-fragrance-notes.index'),
        'permissions' => ['products.index'], // Reuse existing permission
    ]);
    // --- END: Fragrance Profiles ---
}