<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ProductReviewController as ApiProductReviewController;
use App\Http\Controllers\Api\FaqApiController;
use App\Http\Controllers\Api\CartController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
// Auth Routes
Route::middleware(['customLogs', 'restrict.domains'])->group(function () {
    Route::post('/signup', [AuthController::class, 'signup']);

    Route::post('/verifyOTP', [AuthController::class, 'verifyOTP']);

    Route::post('/sendOTP', [AuthController::class, 'sendOTP']);

    Route::post('/signin', [AuthController::class, 'signin']);

    Route::get('/signout', [AuthController::class, 'signout']);

    Route::get('/customer', [AuthController::class, 'getCustomer']);

    Route::post('/submitReview', [AuthController::class, 'submitReview']);

    // Product Category Routes
    Route::withoutMiddleware('customLogs')->post('/productCategories', [ProductCategoryController::class, 'getProductCategories']);
    Route::withoutMiddleware('customLogs')->post('/productCategoriesTemp', [ProductCategoryController::class, 'getProductCategoriesTemp']);
    Route::withoutMiddleware('customLogs')->post('/productCategorySEO', [ProductCategoryController::class, 'getProductCategorySEO']);

    // Product Routes
    Route::withoutMiddleware('customLogs')->post('/products', [ProductController::class, 'getProducts']);

    // All Product Routes
    Route::withoutMiddleware('customLogs')->post('/allProducts', [ProductController::class, 'getAllProducts']);
    Route::withoutMiddleware('customLogs')->post('/exportProducts', [ProductController::class, 'getExportProducts']);
    Route::withoutMiddleware('customLogs')->post('/productSEO', [ProductController::class, 'getProductSEO']);

    // Order Routes
    Route::post('/storeOrder', [OrderController::class, 'storeOrder']);
    Route::withoutMiddleware('restrict.domains')->post('/payTabsPaymentRedirect', [OrderController::class, 'payTabsPaymentRedirect']);
    Route::post('/trackOrder', [OrderController::class, 'trackOrder']);
    Route::post('/orderDetails', [OrderController::class, 'orderDetails']);
    Route::post('/validateCoupon', [OrderController::class, 'validateCoupon']);

    // Blog Routes
    Route::withoutMiddleware('customLogs')->post('/blogs', [BlogController::class, 'getBlogs']);
    Route::withoutMiddleware('customLogs')->post('/getBlogDetails', [BlogController::class, 'getBlogDetails']);
    Route::withoutMiddleware('customLogs')->post('/blogSEO', [BlogController::class, 'getBlogSEO']);

    //News Article Routes
    Route::withoutMiddleware('customLogs')->post('/news-articles', [BlogController::class, 'getNewsArticles']);

    // Contact Route
    Route::post('/contact', [ContactController::class, 'contact']);
    Route::post('/feedback', [ContactController::class, 'feedback']);
    Route::post('/campaign', [ContactController::class, 'campaign']);

    Route::post('/customerDetails', [OrderController::class, 'customerDetails']);
    Route::post('/customerUpdate', [OrderController::class, 'customerUpdate']);
    Route::post('/customerAddressDetails', [OrderController::class, 'customerAddressDetails']);
    Route::post('/customerAddressUpdate', [OrderController::class, 'customerAddressUpdate']);
    Route::get('/customerOrders', [OrderController::class, 'customerOrders']);
    Route::post('/customerOrderDetails', [OrderController::class, 'customerOrderDetails']);
    Route::post('/customerCouponDetails', [OrderController::class, 'customerCouponDetails']);
    Route::post('/customerPasswordCheck', [OrderController::class, 'customerPasswordCheck']);

    Route::get('/getFilters', [ProductController::class, 'getFilters']);

    // Address to get all reviews for a specific product
    Route::get('/products/{product}/reviews', [ApiProductReviewController::class, 'index']);
    // Address to submit a new review
    Route::post('/reviews', [ApiProductReviewController::class, 'store']);


    Route::get('/freeGiftProducts', [ProductController::class, 'freeGiftProducts']);

    Route::get('/bogoProducts', [ProductController::class, 'bogoProducts']);

    Route::get('/getCoupons', [OrderController::class, 'getCoupons']);

    //FAQ API
   

Route::prefix('faqs')->group(function () {
    Route::get('/', [FaqApiController::class, 'faqs']); // all faqs
    Route::get('/categories', [FaqApiController::class, 'categories']); // faqs grouped by category
});

    Route::post('/getCart', [CartController::class, 'getCart']);
    Route::post('/addUpdateCart', [CartController::class, 'addUpdateCart']);
    Route::post('/removeFromCart', [CartController::class, 'removeFromCart']);

    Route::withoutMiddleware('restrict.domains')->post('/tamaraPaymentResponse', [OrderController::class, 'tamaraPaymentResponse']);
    Route::withoutMiddleware('restrict.domains')->any('/tamaraPaymentWebhook', [OrderController::class, 'tamaraPaymentWebhook']);
});
