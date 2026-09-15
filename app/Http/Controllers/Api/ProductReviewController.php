<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\Promotion;
use Botble\Base\Enums\BaseStatusEnum;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderProduct;
use Botble\Ecommerce\Models\OrderAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class ProductReviewController extends Controller
{
    /**
     * Fetch order and products details for the dedicated review landing page
     * using HMAC signed tokens for secure, frictionless customer access.
     */
    public function getOrderReviewDetails(Request $request)
    {
        $token = $request->input('q') ?? $request->input('token');
        $signature = $request->input('s') ?? $request->input('signature');

        if (!$token || !$signature) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Missing security credentials in review link.'
            ], 403);
        }

        $orderCode = base64_decode($token, true);
        if (!$orderCode) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Malformed review verification token.'
            ], 400);
        }

        $expectedSignature = hash_hmac('sha256', $orderCode, config('app.key'));
        if (!hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid or tampered review link signature.'
            ], 403);
        }

        $order = Order::select(
            'ec_orders.id',
            'ec_orders.code',
            'ec_orders.status',
            'ec_orders.amount',
            'ec_orders.created_at',
            'ec_orders.updated_at',
            'ec_order_addresses.name as customer_name',
            'ec_order_addresses.email as customer_email',
            'ec_order_addresses.phone as customer_phone'
        )
        ->join('ec_order_addresses', 'ec_order_addresses.order_id', 'ec_orders.id', 'left')
        ->where('ec_orders.code', $orderCode)
        ->first();

        if (!$order) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Order not found.'
            ], 404);
        }

        $products = OrderProduct::select(
            'id',
            'product_id',
            'product_name',
            'product_image',
            'qty',
            'price',
            'order_id'
        )
        ->where('order_id', $order->id)
        ->get();

        $reviewedProductIds = ProductReview::where('order_id', $order->id)
            ->pluck('product_id')
            ->map(fn($id) => (int)$id)
            ->toArray();

        return response()->json([
            'status'               => 'success',
            'order_id'             => $order->id,
            'order_code'           => $order->code,
            'order_status'         => $order->status,
            'created_at'           => $order->created_at,
            'customer_name'        => $order->customer_name,
            'customer_email'       => $order->customer_email,
            'customer_phone'       => $order->customer_phone,
            'products'             => $products,
            'reviewed_product_ids' => $reviewedProductIds,
        ]);
    }
    /**
     * Get all published reviews for ONE specific product.
     */
    public function index(Product $product)
    {
        $reviews = ProductReview::where('product_id', $product->id)
            ->where('status', BaseStatusEnum::PUBLISHED)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($reviews);
    }

    /**
     * Check which product IDs have already been reviewed for an order.
     */
    public function checkOrderReviews(Request $request)
    {
        $orderIdentifier = $request->input('order_id') ?? $request->input('order_number');
        if (!$orderIdentifier) {
            return response()->json(['reviewed_product_ids' => []]);
        }

        $orderId = $orderIdentifier;
        if (!is_numeric($orderIdentifier)) {
            $order = Order::where('code', $orderIdentifier)->first();
            $orderId = $order ? $order->id : null;
        }

        if (!$orderId) {
            return response()->json(['reviewed_product_ids' => []]);
        }

        $reviewedProductIds = ProductReview::where('order_id', $orderId)->pluck('product_id')->toArray();

        return response()->json([
            'order_id' => $orderId,
            'reviewed_product_ids' => $reviewedProductIds
        ]);
    }

    /**
     * Save a new review from the order completed, account, or landing page.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id'     => 'required|exists:ec_products,id',
            'order_id'       => 'nullable',
            'star'           => 'required|integer|min:1|max:5',
            'comment'        => 'required|string',
            'customer_name'  => 'nullable|string|max:100',
            'customer_email' => 'nullable|email|max:100',
            'customer_phone' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();

        // Resolve order_id if passed as order code
        $orderId = null;
        $order = null;
        if (!empty($validatedData['order_id'])) {
            if (is_numeric($validatedData['order_id'])) {
                $orderId = (int) $validatedData['order_id'];
                $order = Order::find($orderId);
            } else {
                $order = Order::where('code', $validatedData['order_id'])->first();
                $orderId = $order ? $order->id : null;
            }
        }

        // Auto-fill customer contact from order address if missing
        if ($order) {
            $address = \Botble\Ecommerce\Models\OrderAddress::where('order_id', $order->id)->first();
            if ($address) {
                $validatedData['customer_name'] = $validatedData['customer_name'] ?: $address->name;
                $validatedData['customer_email'] = $validatedData['customer_email'] ?: $address->email;
                $validatedData['customer_phone'] = $validatedData['customer_phone'] ?: $address->phone;
            }
        }
        $validatedData['customer_name'] = $validatedData['customer_name'] ?: 'Valued Customer';
        $validatedData['customer_email'] = $validatedData['customer_email'] ?: 'customer@ahmedalmaghribi.com';
        $validatedData['customer_phone'] = $validatedData['customer_phone'] ?: '';

        // Prevent duplicate review for the same product in the same order
        if ($orderId) {
            $existing = ProductReview::where('product_id', $validatedData['product_id'])
                ->where('order_id', $orderId)
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'You have already submitted a review for this product from this order.'
                ], 422);
            }
        }

        // Associate authenticated customer ID if logged in
        $customerId = null;
        try {
            if (auth('api')->check()) {
                $customerId = auth('api')->id();
            }
        } catch (\Exception $e) {}

        if (!$customerId && $request->input('customer_id')) {
            $customerId = $request->input('customer_id');
        }

        $reviewData = array_merge($validatedData, [
            'order_id'    => $orderId,
            'customer_id' => $customerId,
            'status'      => 'pending',
        ]);

        $review = ProductReview::create($reviewData);

        return response()->json([
            'status'  => 'success',
            'message' => 'Review submitted successfully and is pending approval.',
            'review'  => $review,
        ], 201);
    }

    /**
     * Get all reviews submitted by the authenticated customer (both published and pending).
     */
    public function customerReviews(Request $request)
    {
        $customer = auth()->user();
        if (!$customer) {
            $customer = auth('api')->user();
        }

        if (!$customer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = ProductReview::with([
            'product' => function ($q) {
                $q->select('id', 'name', 'images', 'image');
            },
            'order' => function ($q) {
                $q->select('id', 'code');
            }
        ])
        ->where(function ($q) use ($customer) {
            $q->where('customer_id', $customer->id);
            if (!empty($customer->email)) {
                $q->orWhere('customer_email', $customer->email);
            }
            if (!empty($customer->phone)) {
                $q->orWhere('customer_phone', $customer->phone);
            }
        });

        // Filter by status if provided
        $statusFilter = $request->input('status');
        if ($statusFilter && in_array(strtolower($statusFilter), ['published', 'pending'])) {
            $query->where('status', strtolower($statusFilter));
        }

        // Count totals
        $baseCountQuery = ProductReview::where(function ($q) use ($customer) {
            $q->where('customer_id', $customer->id);
            if (!empty($customer->email)) {
                $q->orWhere('customer_email', $customer->email);
            }
            if (!empty($customer->phone)) {
                $q->orWhere('customer_phone', $customer->phone);
            }
        });

        $totalAll = (clone $baseCountQuery)->count();
        $totalPublished = (clone $baseCountQuery)->where('status', 'published')->count();
        $totalPending = (clone $baseCountQuery)->where('status', 'pending')->count();

        $pageSize = (int) $request->input('pageSize', 10);
        if ($pageSize < 1 || $pageSize > 50) {
            $pageSize = 10;
        }

        $paginated = $query->orderBy('created_at', 'desc')->paginate($pageSize);

        $productIds = collect($paginated->items())->pluck('product_id')->filter()->unique()->values()->all();
        $productDetailsMap = [];

        if (!empty($productIds)) {
            $rawProducts = DB::table('ec_products')
                ->whereIn('id', $productIds)
                ->select(
                    'id',
                    'name as product_name',
                    'name_ar as product_name_ar',
                    DB::raw('CAST(price AS DECIMAL(8,2)) as price'),
                    'sale_price',
                    'quantity as product_qty',
                    'image',
                    'images',
                    'description',
                    'description_ar',
                    'sku'
                )
                ->get()
                ->keyBy('id');

            // Collections
            $collections = DB::table('ec_product_collection_products')
                ->join('ec_product_collections', 'ec_product_collection_products.product_collection_id', '=', 'ec_product_collections.id')
                ->whereIn('ec_product_collection_products.product_id', $productIds)
                ->pluck('ec_product_collections.name', 'ec_product_collection_products.product_id');

            // Categories & Subcategories
            $catRecords = DB::table('ec_product_category_product')
                ->join('ec_product_categories', 'ec_product_category_product.category_id', '=', 'ec_product_categories.id')
                ->whereIn('ec_product_category_product.product_id', $productIds)
                ->select(
                    'ec_product_category_product.product_id',
                    'ec_product_categories.id as category_id',
                    'ec_product_categories.name as category_name',
                    'ec_product_categories.parent_id'
                )
                ->get();

            $categoryMap = [];
            $subcategoryMap = [];
            foreach ($catRecords as $cat) {
                if ($cat->parent_id == 0) {
                    $categoryMap[$cat->product_id] = [
                        'category_id'   => (int) $cat->category_id,
                        'category_name' => $cat->category_name,
                    ];
                } else {
                    $subcategoryMap[$cat->product_id] = [
                        'subcategory_name' => $cat->category_name,
                    ];
                }
            }

            // Permalinks from slugs table
            $slugs = DB::table('slugs')
                ->where('reference_type', 'Botble\Ecommerce\Models\Product')
                ->whereIn('reference_id', $productIds)
                ->pluck('key', 'reference_id');

            // Product Labels
            $labels = DB::table('ec_product_label_products')
                ->join('ec_product_labels', 'ec_product_label_products.product_label_id', '=', 'ec_product_labels.id')
                ->whereIn('ec_product_label_products.product_id', $productIds)
                ->select('ec_product_label_products.product_id', 'ec_product_labels.name as label_name', 'ec_product_labels.color as label_color')
                ->get()
                ->groupBy('product_id');

            // Product Tags
            $tags = DB::table('ec_product_tag_product')
                ->join('ec_product_tags', 'ec_product_tag_product.tag_id', '=', 'ec_product_tags.id')
                ->whereIn('ec_product_tag_product.product_id', $productIds)
                ->select('ec_product_tag_product.product_id', 'ec_product_tags.name as tag_name')
                ->get()
                ->groupBy('product_id');

            foreach ($productIds as $pid) {
                if (!isset($rawProducts[$pid])) {
                    continue;
                }
                $p = $rawProducts[$pid];
                $imgList = [];
                if (!empty($p->images)) {
                    $decoded = is_string($p->images) ? json_decode($p->images, true) : $p->images;
                    $imgList = is_array($decoded) ? $decoded : [$p->images];
                } elseif (!empty($p->image)) {
                    $imgList = [$p->image];
                }

                $inStock = (int) ($p->product_qty ?? 0) > 0;
                $stockStatus = $inStock ? 'in_stock' : 'out_of_stock';

                // Fetch active individual or group discount
                $discount = null;

                $individualDiscount = Promotion::where('type', 'discount')
                    ->where('start_date', '<=', now())
                    ->where('end_date', '>=', now())
                    ->whereHas('discountRules', function ($query) {
                        $query->where('apply_to', 'individual');
                    })
                    ->whereHas('discountRules.individualRules', function ($query) use ($pid) {
                        $query->where('product_id', $pid);
                    })
                    ->with(['discountRules' => function ($query) {
                        $query->where('apply_to', 'individual')
                            ->select('id', 'promotion_id', 'apply_to');
                    }, 'discountRules.individualRules' => function ($query) use ($pid) {
                        $query->where('product_id', $pid)
                            ->select('discount_rule_id', 'product_id', 'value', 'discount_type', 'product_price', 'discount_amount', 'final_price');
                    }])
                    ->first();

                if ($individualDiscount) {
                    $discountRule = $individualDiscount->discountRules->first();
                    $individualRule = $discountRule ? $discountRule->individualRules->first() : null;
                    if ($individualRule) {
                        $discount = (object) [
                            'value'           => intval($individualRule->value),
                            'apply_to'        => $discountRule->apply_to,
                            'discount_type'   => $individualRule->discount_type,
                            'product_price'   => $individualRule->product_price,
                            'discount_amount' => $individualRule->discount_amount,
                            'final_price'     => $individualRule->final_price,
                            'start_date'      => $individualDiscount->start_date->format('Y-m-d H:i:s'),
                            'end_date'        => $individualDiscount->end_date->format('Y-m-d H:i:s'),
                        ];
                    }
                } else {
                    $groupDiscount = Promotion::where('type', 'discount')
                        ->where('start_date', '<=', now())
                        ->where('end_date', '>=', now())
                        ->whereHas('discountRules', function ($query) {
                            $query->where('apply_to', '!=', 'individual');
                        })
                        ->whereHas('discountRules.products', function ($query) use ($pid) {
                            $query->where('product_id', $pid);
                        })
                        ->with(['discountRules' => function ($query) {
                            $query->where('apply_to', '!=', 'individual')
                                ->select('id', 'promotion_id', 'percentage', 'apply_to');
                        }])
                        ->first();

                    if ($groupDiscount) {
                        $discountRule = $groupDiscount->discountRules->first();
                        if ($discountRule) {
                            $discount = (object) [
                                'value'           => intval($discountRule->percentage),
                                'apply_to'        => $discountRule->apply_to,
                                'discount_type'   => 'percent',
                                'product_price'   => null,
                                'discount_amount' => null,
                                'final_price'     => null,
                                'start_date'      => $groupDiscount->start_date->format('Y-m-d H:i:s'),
                                'end_date'        => $groupDiscount->end_date->format('Y-m-d H:i:s'),
                            ];
                        }
                    }
                }

                // Fetch active coupons for the product
                $coupons = Promotion::where('type', 'coupon')
                    ->where('start_date', '<=', now())
                    ->where('end_date', '>=', now())
                    ->whereHas('couponRules.products', function ($query) use ($pid) {
                        $query->where('product_id', $pid);
                    })
                    ->with(['couponRules' => function ($query) use ($pid) {
                        $query->whereNotNull('coupon_code')
                            ->select('id', 'promotion_id', 'coupon_code', 'percentage')
                            ->with(['products' => function ($subQuery) use ($pid) {
                                $subQuery->where('product_id', $pid)
                                        ->select('id', 'coupon_rule_id', 'product_id');
                            }]);
                    }])
                    ->get();

                $couponList = [];
                foreach ($coupons as $promotion) {
                    foreach ($promotion->couponRules as $couponRule) {
                        if ($couponRule->coupon_code && $couponRule->products->isNotEmpty()) {
                            $couponList[strtolower($couponRule->coupon_code)] = [
                                'code'       => strtolower($couponRule->coupon_code),
                                'value'      => intval($couponRule->percentage),
                                'start_date' => $promotion->start_date->format('Y-m-d H:i:s'),
                                'end_date'   => $promotion->end_date->format('Y-m-d H:i:s'),
                            ];
                        }
                    }
                }

                $productDetailsMap[$pid] = [
                    'product_id'        => (int) $p->id,
                    'id'                => (int) $p->id,
                    'product_name'      => $p->product_name,
                    'product_name_ar'   => $p->product_name_ar,
                    'price'             => (string) $p->price,
                    'sale_price'        => $p->sale_price ? (string) $p->sale_price : null,
                    'product_qty'       => (int) ($p->product_qty ?? 0),
                    'stock_status'      => $stockStatus,
                    'in_stock'          => $inStock,
                    'image'             => $imgList[0] ?? $p->image,
                    'images'            => $imgList,
                    'description'       => $p->description,
                    'description_ar'    => $p->description_ar,
                    'collection_name'   => $collections[$pid] ?? null,
                    'category_id'       => $categoryMap[$pid]['category_id'] ?? null,
                    'category_name'     => $categoryMap[$pid]['category_name'] ?? null,
                    'subcategory'       => $subcategoryMap[$pid] ?? null,
                    'permalink'         => isset($slugs[$pid]) ? ['key' => $slugs[$pid]] : null,
                    'labels'            => $labels->get($pid, collect())->map(function ($l) {
                        return [
                            'label_name'  => $l->label_name,
                            'label_color' => $l->label_color,
                        ];
                    })->values()->all(),
                    'tags'              => $tags->get($pid, collect())->pluck('tag_name')->values()->all(),
                    'discount'          => $discount,
                    'coupon'            => $couponList,
                ];
            }
        }

        $items = collect($paginated->items())->map(function ($item) use ($productDetailsMap) {
            $productObj = $productDetailsMap[$item->product_id] ?? null;
            $order = $item->order;

            $fallbackImage = null;
            if ($item->product) {
                if (!empty($item->product->images)) {
                    $itemImgs = is_string($item->product->images) ? json_decode($item->product->images, true) : $item->product->images;
                    $fallbackImage = is_array($itemImgs) && !empty($itemImgs) ? $itemImgs[0] : null;
                }
                if (!$fallbackImage) {
                    $fallbackImage = $item->product->image;
                }
            }

            $productImage = $productObj ? $productObj['image'] : $fallbackImage;
            $productName = $productObj ? $productObj['product_name'] : ($item->product ? $item->product->name : 'Fragrance');
            $rawPrice = $productObj ? (float) $productObj['price'] : 0;
            $rawSalePrice = ($productObj && $productObj['sale_price']) ? (float) $productObj['sale_price'] : null;

            // Compute effective final sale price if discount is active
            $discountSalePrice = null;
            if ($productObj && !empty($productObj['discount'])) {
                $d = $productObj['discount'];
                if ($d->discount_type === 'amount' && !empty($d->final_price)) {
                    $discountSalePrice = (float) $d->final_price;
                } elseif ($d->discount_type === 'percent' && !empty($d->value)) {
                    $discountSalePrice = round($rawPrice - ($rawPrice * (float)$d->value / 100), 2);
                }
            }

            $effectiveSalePrice = $rawSalePrice ?: $discountSalePrice;
            $price = $effectiveSalePrice ?: $rawPrice;
            $salePrice = $effectiveSalePrice;
            $productQty = $productObj ? (int) $productObj['product_qty'] : (int) ($item->product->quantity ?? 0);
            $inStock = $productQty > 0;

            return [
                'id'             => $item->id,
                'product_id'     => $item->product_id,
                'product_name'   => $productName,
                'product_image'  => $productImage,
                'price'          => $price,
                'sale_price'     => $salePrice,
                'in_stock'       => $inStock,
                'product_qty'    => $productQty,
                'product'        => $productObj,
                'order_id'       => $item->order_id,
                'order_code'     => $order && $order->code ? (str_starts_with($order->code, '#') ? $order->code : '#' . $order->code) : null,
                'star'           => (int) $item->star,
                'comment'        => $item->comment,
                'status'         => $item->status ?: 'pending',
                'can_edit'       => ($item->status === 'pending'),
                'created_at'     => $item->created_at ? $item->created_at->toIso8601String() : null,
                'updated_at'     => $item->updated_at ? $item->updated_at->toIso8601String() : null,
            ];
        });

        return response()->json([
            'status'       => 'success',
            'data'         => $items,
            'total'        => $paginated->total(),
            'counts'       => [
                'all'       => $totalAll,
                'published' => $totalPublished,
                'pending'   => $totalPending,
            ],
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
        ]);
    }

    /**
     * Update a pending review submitted by the authenticated customer.
     */
    public function updateCustomerReview(Request $request, $id)
    {
        $customer = auth()->user();
        if (!$customer) {
            $customer = auth('api')->user();
        }

        if (!$customer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $review = ProductReview::find($id);
        if (!$review) {
            return response()->json(['message' => 'Review not found.'], 404);
        }

        // Ownership verification
        $isOwner = false;
        if ($review->customer_id && (int) $review->customer_id === (int) $customer->id) {
            $isOwner = true;
        } elseif (!empty($review->customer_email) && !empty($customer->email) && strtolower($review->customer_email) === strtolower($customer->email)) {
            $isOwner = true;
        } elseif (!empty($review->customer_phone) && !empty($customer->phone) && $review->customer_phone === $customer->phone) {
            $isOwner = true;
        }

        if (!$isOwner) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        // Only allow editing pending reviews
        if ($review->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending reviews can be edited. Published reviews are locked.'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'star'    => 'required|integer|min:1|max:5',
            'comment' => 'required|string|min:2|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $review->star = (int) $request->input('star');
        $review->comment = trim($request->input('comment'));
        $review->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Review updated successfully and remains pending approval.',
            'review'  => [
                'id'       => $review->id,
                'star'     => (int) $review->star,
                'comment'  => $review->comment,
                'status'   => $review->status,
                'can_edit' => true,
            ],
        ]);
    }

    /**
     * Web cron endpoint to trigger order review reminder emails.
     * Can be invoked via Cloudways / external cron URL or browser.
     * Supported query params: ?order=... &email=... &force=1 &dry_run=1
     */
    public function sendOrderReviewReminders(Request $request)
    {
        Log::info('[ReviewReminderCron] Web endpoint /api/sendOrderReviewReminders invoked', [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'query'      => $request->all(),
        ]);

        $params = [];

        if ($request->filled('order')) {
            $params['--order'] = $request->input('order');
        }

        if ($request->filled('email')) {
            $params['--email'] = $request->input('email');
        }

        if ($request->boolean('force') || $request->input('force') === '1' || $request->input('force') === 'true') {
            $params['--force'] = true;
        }

        if ($request->boolean('dry_run') || $request->boolean('dry-run') || $request->input('dry_run') === '1') {
            $params['--dry-run'] = true;
        }

        try {
            Artisan::call('reviews:send-reminders', $params);
            $output = Artisan::output();

            Log::info('[ReviewReminderCron] Web endpoint execution completed successfully.');

            return response()->json([
                'status'  => 'success',
                'message' => 'Review reminder job executed successfully.',
                'output'  => array_values(array_filter(array_map('trim', explode("\n", $output)))),
            ]);
        } catch (\Exception $e) {
            Log::error('[ReviewReminderCron] Web endpoint execution failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to execute review reminder job: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Web cron endpoint to trigger post-purchase WhatsApp review reminders via WABA.
     * Can be invoked via Cloudways / external cron URL or browser.
     * Supported query params: ?order=... &phone=... &force=1 &dry_run=1
     */
    public function sendWhatsAppReviewReminders(Request $request)
    {
        Log::info('[WhatsAppReviewReminderCron] Web endpoint /api/sendWhatsAppReviewReminders invoked', [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'query'      => $request->all(),
        ]);

        $params = [];

        if ($request->filled('order')) {
            $params['--order'] = $request->input('order');
        }

        if ($request->filled('phone')) {
            $params['--phone'] = $request->input('phone');
        }

        if ($request->boolean('force') || $request->input('force') === '1' || $request->input('force') === 'true') {
            $params['--force'] = true;
        }

        if ($request->boolean('dry_run') || $request->boolean('dry-run') || $request->input('dry_run') === '1') {
            $params['--dry-run'] = true;
        }

        try {
            Artisan::call('reviews:send-whatsapp-reminders', $params);
            $output = Artisan::output();

            Log::info('[WhatsAppReviewReminderCron] Web endpoint execution completed successfully.');

            return response()->json([
                'status'  => 'success',
                'message' => 'WhatsApp review reminder job executed successfully.',
                'output'  => array_values(array_filter(array_map('trim', explode("\n", $output)))),
            ]);
        } catch (\Exception $e) {
            Log::error('[WhatsAppReviewReminderCron] Web endpoint execution failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to execute WhatsApp review reminder job: ' . $e->getMessage(),
            ], 500);
        }
    }
}