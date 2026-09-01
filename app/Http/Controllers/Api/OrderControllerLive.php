<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Botble\Ecommerce\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderHistory;
use Botble\Ecommerce\Enums\ShippingMethodEnum;
use Botble\Ecommerce\Enums\OrderStatusEnum;
use Botble\Ecommerce\Enums\OrderHistoryActionEnum;
use Botble\Ecommerce\Services\CreatePaymentForOrderService;
use Botble\Ecommerce\Models\OrderAddress;
use Botble\Ecommerce\Models\Address;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Models\OrderProduct;
use Botble\Ecommerce\Models\Invoice;
use Botble\Ecommerce\Models\InvoiceItem;
use Botble\Ecommerce\Facades\Discount;
use Botble\Ecommerce\Models\DiscountProduct;
use Botble\Ecommerce\Models\Discount as DiscountModel;
use Botble\Ecommerce\Models\MobileVerification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Botble\Ecommerce\Models\DiscountCustomer;
use App\Models\Promotion;
use App\Models\CouponRule;
use App\Models\CashbackProduct;
use Botble\Payment\Models\Payment;
use App\Models\ActiveCoupon;
use Illuminate\Support\Facades\Log;
use Botble\Ecommerce\Models\ShippingRule;
use Botble\Ecommerce\Models\Tax;

class OrderController extends Controller
{
    public function storeOrder(\App\Http\Requests\StoreOrderRequest $request, CreatePaymentForOrderService $createPaymentForOrderService) {
        $orderTrackId = \Illuminate\Support\Str::uuid()->toString();

        // This injects the unique ID into every log generated during this request
        \Illuminate\Support\Facades\Log::withContext([
            'order_req_id' => $orderTrackId
        ]);
        Log::info("[$orderTrackId] OrderController: storeOrder() started.");

        // echo base_path('certs/cacert.pem');die;

        $customer = Auth::guard('api')->user();
        if (!$customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // --- PRE-TRANSACTION API CHECKS ---
        $coupon_code = $request->input('couponCode');
        $decode = null; // Initialise — only populated below if a coupon code is present
        
        if (isset($coupon_code) && !empty($coupon_code)) {

            if (!isset($request->couponData) || empty($request->couponData)) {
                return response()->json(['couponMessage' => 'Apply or Remove Coupon First']);
            }

            $couponRegistrationId = $request->couponData['couponRegistrationId'] ?? 0;
            $postData = [
                'salesType' => $request->couponData['salesType'] ?? '',
                'company' => $request->couponData['company'] ?? '',
                'mobileNo' => $request->billingAddress['mobile'] ?? '',
                'email' => $request->billingAddress['email'] ?? '',
                'couponRegistrationId' => $couponRegistrationId,
            ];
            if ($couponRegistrationId == 0) {
                $postData['couponCode'] = $coupon_code;
            }

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => env('SMART_VIEW_COUPON_API_URL') . 'Coupon/ActiveCoupons',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30, // Safety timeout
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($postData),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ]);
            $response = curl_exec($curl);
            curl_close($curl);
            $decode = json_decode($response);
            
            if (!isset($decode->data) || (is_array($decode->data) && empty($decode->data))) {
                return response()->json(['couponMessage' => 'You Have Already Used this Coupon Code']);
            }
        }
        $productIds = collect($request->input('products'))->pluck('product_id')->toArray();
        $dbProducts = Product::whereIn('id', $productIds)->get()->keyBy('id');

        // Parallel API check for StockStatus BEFORE the DB transaction to prevent locking
        $stockResponses = \Illuminate\Support\Facades\Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($request, $dbProducts) {
            foreach ($request->input('products') as $product) {
                if($product['quantity'] <= 0) {
                    return response()->json([
                        'qtyMessage'          => 'Quantity should be greater than 0'
                   ]);
                }
                $exisProduct = $dbProducts->get($product['product_id']);
                if ($exisProduct && $exisProduct->barcode) {
                    $pool->as('product_' . $product['product_id'])
                         ->withHeaders([
                             "Accept" => "application/json",
                             "Company" => "UAE",
                             "Authorization" => env('SMART_VIEW_TOKEN')
                         ])
                         ->post(env('SMART_VIEW_STOCK_API_URL') . "ECommerce/StockStatus?itemCode=" . $exisProduct->barcode);
                }
            }
        });

        $hasPreBook = false;
        $hasRegular = false;

        foreach ($request->input('products') as $product) {
            $exisProduct = $dbProducts->get($product['product_id']);
            if (!$exisProduct) {
                return response()->json(['notFound' => 'Product not found '.$product['product_name']], 500);
            }
            // if ($product['quantity'] <= 0) {
            //     return response()->json(['qtyMessage' => 'Quantity should be greater than 0']);
            // }
            if ($exisProduct->quantity < $product['quantity']) {
                return response()->json(['qtyMessage' => $product['product_name'].' is Out Of Stock.']);
            }
            $maxQty = $exisProduct->maximum_order_quantity;
            if ($maxQty != 0 && $product['quantity'] > $maxQty) {
                return response()->json([
                    'qtyMessage' => $product['product_name'].' exceeds the maximum allowed quantity of '.$maxQty.'.'
                ]);
            }

            if (isset($product['collection_name']) && strtolower($product['collection_name']) === 'pre book') {
                $hasPreBook = true;
            } else {
                $hasRegular = true;
            }

            if ($hasPreBook && $hasRegular) {
                return response()->json([
                    'collectionMessage' => 'You cannot mix Pre Book items with regular products in one order.'
                ]);
            }

            $response = $stockResponses['product_' . $product['product_id']] ?? null;
            if ($response && $response->successful()) {
                $respData = $response->json();
                
                if (isset($respData['data']) && $respData['data'] < $product['quantity']) {
                    return response()->json([
                        'qtyMessage' => $product['product_name'].' is Out Of Stock.'
                    ]);
                }
            } elseif ($response) {
                return response()->json([
                    'qtyMessage' => "We couldn't verify the stock for this product right now. Please try again later. If the issue continues, contact support and mention reference code: STOCK-A7K92PQL."
                ]);
            }
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $createPaymentForOrderService, $productIds, $dbProducts, $decode) {

        
            
            $dbIndividualDiscounts = Promotion::where('type', 'discount')
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->whereHas('discountRules', function ($query) { $query->where('apply_to', 'individual'); })
                ->whereHas('discountRules.individualRules', function ($query) use ($productIds) { $query->whereIn('product_id', $productIds); })
                ->with(['discountRules' => function ($query) {
                    $query->where('apply_to', 'individual')->select('id', 'promotion_id', 'apply_to');
                }, 'discountRules.individualRules' => function ($query) use ($productIds) {
                    $query->whereIn('product_id', $productIds)->select('discount_rule_id', 'product_id', 'value', 'discount_type', 'product_price', 'discount_amount', 'final_price');
                }])->get();

            $dbGroupDiscounts = Promotion::where('type', 'discount')
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->whereHas('discountRules', function ($query) { $query->where('apply_to', '!=', 'individual'); })
                ->whereHas('discountRules.products', function ($query) use ($productIds) { $query->whereIn('product_id', $productIds); })
                ->with([
                    'discountRules' => function ($query) {
                        $query->where('apply_to', '!=', 'individual')->select('id', 'promotion_id', 'percentage', 'apply_to');
                    },
                    'discountRules.products' => function ($query) use ($productIds) {
                        $query->whereIn('product_id', $productIds);
                    }
                ])->get();

            $dbFOCs = Promotion::where('type', 'foc')
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->whereHas('focRules.products', function ($query) use ($productIds) { $query->whereIn('product_id', $productIds); })
                ->with([
                    'focRules' => function ($query) {
                        $query->select('id', 'promotion_id', 'min_threshold', 'max_threshold', 'gift_limit');
                    },
                    'focRules.products' => function ($query) use ($productIds) {
                        $query->whereIn('product_id', $productIds);
                    }
                ])->get();

            $dbBOGOs = Promotion::where('type', 'buy_x_get_y')
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->whereHas('buyXGetYRules.products', function ($query) use ($productIds) { $query->whereIn('product_id', $productIds); })
                ->with([
                    'buyXGetYRules.products' => function ($query) use ($productIds) {
                        $query->whereIn('product_id', $productIds);
                    }
                ])->get();

            // Calculate actual cart total from DB prices for non-gift products (prevents payload tampering of totalPrice)
            $actualDbCartTotal = 0;
            foreach ($request->input('products') as $cartItem) {
                $isGiftItem = (isset($cartItem['is_gift']) && filter_var($cartItem['is_gift'], FILTER_VALIDATE_BOOLEAN)) ||
                              (isset($cartItem['type']) && in_array($cartItem['type'], ['foc', 'bogo']));

                if ($isGiftItem) {
                    continue; // Gifts do not count towards threshold
                }

                $dbItem = $dbProducts->get($cartItem['product_id']);
                if (!$dbItem) {
                    continue;
                }

                $unitPrice = (float)$dbItem->price;

                // Check for individual promotion discount in DB
                $itemIndDiscount = $dbIndividualDiscounts->first(function ($promo) use ($cartItem) {
                    return collect($promo->discountRules)->some(function ($rule) use ($cartItem) {
                        return collect($rule->individualRules)->contains('product_id', $cartItem['product_id']);
                    });
                });

                if ($itemIndDiscount) {
                    $indRule = collect($itemIndDiscount->discountRules)->firstWhere('apply_to', 'individual');
                    $specificRule = collect($indRule->individualRules ?? [])->firstWhere('product_id', $cartItem['product_id']);
                    if ($specificRule) {
                        $discVal = (float)$specificRule->value;
                        $unitPrice = $specificRule->discount_type === 'percent'
                            ? ($unitPrice - ($unitPrice * ($discVal / 100)))
                            : max(0, $unitPrice - $discVal);
                    }
                } else {
                    // Check for group promotion discount in DB
                    $itemGroupDiscount = $dbGroupDiscounts->first(function ($promo) use ($cartItem) {
                        return collect($promo->discountRules)->some(function ($rule) use ($cartItem) {
                            return collect($rule->products)->contains('product_id', $cartItem['product_id']);
                        });
                    });

                    if ($itemGroupDiscount) {
                        $grpRule = collect($itemGroupDiscount->discountRules)->firstWhere('apply_to', '!=', 'individual');
                        if ($grpRule && isset($grpRule->percentage)) {
                            $unitPrice = $unitPrice - ($unitPrice * ((float)$grpRule->percentage / 100));
                        }
                    }
                }

                $qty = isset($cartItem['quantity']) && (int)$cartItem['quantity'] > 0 ? (int)$cartItem['quantity'] : 1;
                $actualDbCartTotal += ($unitPrice * $qty);
            }

            $barcodes = [];

            // Initialize tracking variables BEFORE the loop
            $hasPreBook = false;
            $hasRegular = false;

            foreach ($request->input('products') as $product) {
                
                $exisProduct = $dbProducts->get($product['product_id']);
                // if($exisProduct->quantity < $product['quantity']) {
                //     return response()->json([
                //         'qtyMessage'          => $product['product_name'].' is Out Of Stock.'
                //     ]);
                // }

                // if (!$exisProduct) {
                //     return response()->json([
                //         'notFound' => 'Product not found '.$product['product_name']
                //     ], 500);
                // }

                // if($product['quantity'] <= 0) {
                //     return response()->json([
                //         'qtyMessage'          => 'Quantity should be greater than 0'
                //     ]);
                // }

                // // $url = env('SMART_VIEW_STOCK_API_URL')."ECommerce/StockStatus?itemCode=123456";
                // $url = env('SMART_VIEW_STOCK_API_URL')."ECommerce/StockStatus?itemCode=".$exisProduct->barcode;

                // $ch = curl_init();

                // curl_setopt($ch, CURLOPT_URL, $url);
                // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                // // Set the request method to POST
                // curl_setopt($ch, CURLOPT_POST, true);
                // curl_setopt($ch, CURLOPT_HTTPHEADER, [
                //     "Accept: application/json",
                //     "Company: UAE", 
                //     "Authorization: ". env('SMART_VIEW_TOKEN')
                // ]);

                // $response = curl_exec($ch);

                // if (curl_errno($ch)) {
                //     // echo 'Error: ' . curl_error($ch);
                //     \Log::info('Stock API Error:', ['error' => curl_error($ch)]);
                // }

                // curl_close($ch);
                // $resp = json_decode($response);
                // // print_r($resp->data);die;
                // \Log::info('Stock API Response:', ['response' => $resp]);

                // if(isset($resp->data) && $resp->data < $product['quantity']) {
                //     return response()->json([
                //         'qtyMessage'          => $product['product_name'].' is Out Of Stock.'
                //     ]);
                // }

                // $maxQty = $exisProduct->maximum_order_quantity;

                // // Only check when maxQty is NOT 0
                // if ($maxQty != 0 && $product['quantity'] > $maxQty) {
                //     return response()->json([
                //         'qtyMessage' => $product['product_name'].' exceeds the maximum allowed quantity of '.$maxQty.'.'
                //     ]);
                // }

                // // --- Check product_collection ---
                // if (isset($product['collection_name']) && strtolower($product['collection_name']) === 'pre book') {
                //     $hasPreBook = true;
                // } else {
                //     $hasRegular = true;
                // }

                // // If both types are present → reject
                // if ($hasPreBook && $hasRegular) {
                //     return response()->json([
                //         'collectionMessage' => 'You cannot mix Pre Book items with regular products in one order.'
                //     ]);
                // }

                // if(isset($product['discount']) && !is_null($product['discount'])) {
                $discountFromDb = $dbIndividualDiscounts->first(function ($promo) use ($product) {
                    return collect($promo->discountRules)->some(function ($rule) use ($product) {
                        return collect($rule->individualRules)->contains('product_id', $product['product_id']);
                    });
                });

                if (!$discountFromDb) {
                    // If no individual discount, try to fetch discount for group/all products
                    $discountFromDb = $dbGroupDiscounts->first(function ($promo) use ($product) {
                        return collect($promo->discountRules)->some(function ($rule) use ($product) {
                            return collect($rule->products)->contains('product_id', $product['product_id']);
                        });
                    });
                }

                $requestHasDiscount = !is_null($product['discount']);
                $dbHasDiscount = !is_null($discountFromDb);

                if ($requestHasDiscount && !$dbHasDiscount) {
                    // Request says there should be a discount, but none found in DB
                    return response()->json([
                        'discountMessage' => 'One or more Products were removed. Please add them again to continue. DB'
                    ]);
                }

                // if (!$requestHasDiscount && $dbHasDiscount) {
                //     // Request says there should be no discount, but one exists in DB
                //     return response()->json([
                //         'discountMessage' => 'One or more Products were removed. Please add them again to continue. Request '.$product['product_name']
                //     ]);
                // }

                if ($requestHasDiscount && $dbHasDiscount) {

                    $dbRule = $discountFromDb->discountRules[0] ?? null;

                    if ($dbRule) {

                        $match = false;

                        // -----------------------------------------
                        // Individual product discount
                        // -----------------------------------------
                        if ( $dbRule->apply_to === 'individual' && isset($dbRule->individualRules[0])) {

                            $individualRule = $dbRule->individualRules[0];

                            $match =
                                (float) $product['discount']['value'] === (float) $individualRule->value &&
                                $product['discount']['discount_type'] === $individualRule->discount_type;
                        }

                        // -----------------------------------------
                        // Group / category / all product discount
                        // -----------------------------------------
                        else {

                            $match = (float) $product['discount']['value'] === (float) $dbRule->percentage;
                        }

                        // echo "<pre>";print_r($discountFromDb);die();

                        // -----------------------------------------
                        // Compare dates for both cases
                        // -----------------------------------------
                        $match = $match &&

                            Carbon::parse($product['discount']['start_date'])->toDateString() ===
                            Carbon::parse($discountFromDb->start_date)->toDateString() &&

                            Carbon::parse($product['discount']['end_date'])->toDateString() ===
                            Carbon::parse($discountFromDb->end_date)->toDateString();

                        if (!$match) {

                            return response()->json([
                                'discountMessage' => 'One or more Products were removed. Please add them again to continue.'
                            ]);
                        }
                    }
                }

                $requestHasFOC = (isset($product['type']) && $product['type'] == 'foc') || (isset($product['is_gift']) && filter_var($product['is_gift'], FILTER_VALIDATE_BOOLEAN));

                $focFromDb = null;
                if ($requestHasFOC) {
                    $cartTotalPrice = $actualDbCartTotal;

                    $focFromDb = $dbFOCs->first(function ($promo) use ($product, $cartTotalPrice) {
                        return collect($promo->focRules)->some(function ($rule) use ($product, $cartTotalPrice) {
                            $min = (float)$rule->min_threshold;
                            $max = ($rule->max_threshold !== null && $rule->max_threshold !== '') ? (float)$rule->max_threshold : null;
                            $withinThreshold = ($cartTotalPrice >= $min) && ($max === null || $cartTotalPrice <= $max);

                            return $withinThreshold && collect($rule->products)->contains('product_id', $product['product_id']);
                        });
                    });
                }
                    
                $dbHasFOC = !is_null($focFromDb);

                if ($requestHasFOC && !$dbHasFOC) {
                    // Request says there should be a discount, but none found in DB or does not meet threshold
                    return response()->json([
                        'focMessage' => 'One or more Products were removed. Please add them again to continue. DB'
                    ]);
                }

                if ($requestHasFOC && $dbHasFOC) {
                    $matchedRule = collect($focFromDb->focRules)->first(function ($rule) use ($product, $cartTotalPrice) {
                        $min = (float)$rule->min_threshold;
                        $max = ($rule->max_threshold !== null && $rule->max_threshold !== '') ? (float)$rule->max_threshold : null;
                        return ($cartTotalPrice >= $min) && ($max === null || $cartTotalPrice <= $max) && collect($rule->products)->contains('product_id', $product['product_id']);
                    });

                    $giftLimit = $matchedRule ? (int)($matchedRule->gift_limit ?: 1) : 1;

                    // Validate individual gift item quantity does not exceed 1
                    if (isset($product['quantity']) && (int)$product['quantity'] > 1) {
                        return response()->json([
                            'focMessage' => 'Free gift quantity cannot be greater than 1.'
                        ]);
                    }

                    // Validate total FOC gifts in cart do not exceed the rule's gift_limit
                    $totalFocGifts = collect($request->input('products'))->filter(function ($p) {
                        return (isset($p['type']) && $p['type'] == 'foc') || (isset($p['is_gift']) && filter_var($p['is_gift'], FILTER_VALIDATE_BOOLEAN));
                    })->sum(function ($p) {
                        return isset($p['quantity']) ? (int)$p['quantity'] : 1;
                    });

                    if ($totalFocGifts > $giftLimit) {
                        return response()->json([
                            'focMessage' => 'You can only select up to ' . $giftLimit . ' free gift(s).'
                        ]);
                    }
                }

                    // Step 1: Determine if request says product is a BOGO free item
                $requestHasBOGO = isset($product['type']) && $product['type'] == 'bogo' && isset($product['is_gift']);

                // Step 2: Only run DB BOGO check if the request is for a BOGO free product
                $bogoFromDb = null;

                if ($requestHasBOGO) {
                    // echo "bogo ".$product['product_name'];
                    // echo "\n";
                    $bogoFromDb = $dbBOGOs->first(function ($promo) use ($product) {
                        return collect($promo->buyXGetYRules)->some(function ($rule) use ($product) {
                            return collect($rule->products)->contains('product_id', $product['product_id']);
                        });
                    });
                }

                // Step 3: Validate mismatch between request and DB
                $dbHasBOGO = !is_null($bogoFromDb);

                if ($requestHasBOGO && !$dbHasBOGO) {
                    return response()->json([
                        'bogoMessage' => 'One or more Products were removed. Please add them again to continue. DB'
                    ]);
                }

                // if (!$requestHasBOGO && $dbHasBOGO) {
                //     return response()->json([
                //         'bogoMessage' => 'One or more Products were removed. Please add them again to continue. Request ' . $product['product_name']
                //     ]);
                // }

                // All matched, assign discount
                // $exisProduct->discount = $discountFromDb;
                // }

                array_push($barcodes, $exisProduct->barcode);
            }

            // $coupon_code = $request->input('couponCode');
            // if(isset($coupon_code) && !empty($request->input('couponCode'))) {
            //     $coupon = Promotion::select('type', 'start_date', 'end_date', 'coupon_code AS code', 'percentage As value', 'apply_to')->where('type', 'coupon')->where('coupon_code', $request->input('couponCode'))->where('start_date', '<=', now())->where('end_date', '>=', now())->join('coupon_rules', 'promotions.id', 'coupon_rules.promotion_id', 'left')->first();
            //     if(!$coupon) {
            //         return response()->json(['couponMessage' => 'Invalid Coupon Code']);
            //     }
            //     // $order_address = OrderAddress::where('phone', $request->input('billingAddress.mobile'))->first();
            //     // // echo $order_address;
            //     // if($order_address) {
            //     //     $order = Order::where('id', $order_address->order_id)->first();
            //     //     $customer_discount = DB::table('ec_customer_used_coupons')->where('customer_id', $order->user_id)->where('discount_id', $coupon->id)->first();
            //     //     if($customer_discount) {
            //     //         return response()->json(['couponMessage' => 'You Have Already Used this Coupon Code']);
            //     //     }
            //     // }

            //     $customer = OrderAddress::join('payments', 'payments.order_id', '=', 'ec_order_addresses.order_id')->where('status', 'completed')->where('phone', $request->input('billingAddress.mobile'))->get();

            //     if(!$customer->isEmpty()) {
            //         if(strtolower($request->input('couponCode')) == 'welcome10') {
            //             return response()->json(['couponMessage' => 'You Have Already Used this Coupon Code']);
            //         }
            //         $customer_discount = DB::table('ec_customer_used_coupons')->where('customer_id', $customer[0]->customer_id)->where('discount_id', $coupon->id)->first();
            //         if($customer_discount) {
            //             return response()->json(['couponMessage' => 'You Have Already Used this Coupon Code']);
            //         }
            //     }
            // }

            $coupon_code = $request->input('couponCode');
            // $decode = null;

            // if (isset($coupon_code) && !empty($coupon_code)) {

            //     if (!isset($request->couponData) || empty($request->couponData)) {
            //         return response()->json(['couponMessage' => 'Apply or Remove Coupon First']);
            //     }

            //     $couponRegistrationId = $request->couponData['couponRegistrationId'] ?? 0;

            //     // ✅ Build payload conditionally
            //     $postData = [
            //         'salesType' => $request->couponData['salesType'] ?? '',
            //         'company' => $request->couponData['company'] ?? '',
            //         'mobileNo' => $request->billingAddress['mobile'] ?? '',
            //         'email' => $request->billingAddress['email'] ?? '',
            //         'couponRegistrationId' => $couponRegistrationId,
            //     ];

            //     // ✅ Only include couponCode when registrationId = 0
            //     if ($couponRegistrationId == 0) {
            //         $postData['couponCode'] = $coupon_code;
            //     }

            //     // 🔥 CURL setup
            //     $curl = curl_init();
            //     curl_setopt_array($curl, [
            //         CURLOPT_URL => env('SMART_VIEW_COUPON_API_URL') . 'Coupon/ActiveCoupons',
            //         CURLOPT_RETURNTRANSFER => true,
            //         CURLOPT_ENCODING => '',
            //         CURLOPT_MAXREDIRS => 10,
            //         CURLOPT_TIMEOUT => 0,
            //         CURLOPT_FOLLOWLOCATION => true,
            //         CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            //         CURLOPT_CUSTOMREQUEST => 'POST',
            //         CURLOPT_POSTFIELDS => json_encode($postData),
            //         CURLOPT_HTTPHEADER => [
            //             'Content-Type: application/json',
            //         ],
            //     ]);

            //     $response = curl_exec($curl);
            //     if (curl_errno($curl)) {
            //         // echo 'Error: ' . curl_error($curl);
            //         \Log::info('Active Coupon API Error:', ['error' => curl_error($curl)]);
            //     }
            //     curl_close($curl);

            //     $decode = json_decode($response);

            //     \Log::info('Active Coupon API Response:', ['response' => $decode]);

            //     // ✅ Validation check
            //     if (!isset($decode->data) || (is_array($decode->data) && empty($decode->data))) {
            //         return response()->json(['couponMessage' => 'You Have Already Used this Coupon Code']);
            //     }
            // }

            $cashback = Promotion::select('promotions.name', 'cashback_rules.id', 'cashback_percentage', 'cashback_amount', 'duration')->where('type', 'cashback')->where('start_date', '<=', now())->where('end_date', '>=', now())->leftJoin('cashback_rules', 'promotions.id', '=', 'cashback_rules.promotion_id')->first();
            if($cashback) {
                $coupon_code = !is_null($cashback->cashback_percentage) ? 'CASHBACK'.intval($cashback->cashback_percentage) : 'CASHBACK'.intval($cashback->cashback_amount);
                $coupon_type = !is_null($cashback->cashback_percentage) ? 'percent' : 'amount';
                $cashback_product_ids = CashbackProduct::select('product_id')->where('cashback_rule_id', $cashback->id)->pluck('product_id')->toArray();
                // echo "<pre>";print_r($cashback_products);
            } else {
                $cashback_product_ids = [];
            }

            $customer_id = $request->input('customer_id');

            if (!$customer_id) {
                $validator = Validator::make($request->all(), [
                    'billingAddress.first_name'      => 'required|string|max:255',
                    'billingAddress.last_name'      => 'required|string|max:255',
                    'billingAddress.email'     => 'required|string|max:255',
                    'billingAddress.mobile'     => 'required|numeric',
                    'billingAddress.area'     => 'required|string',
                    'billingAddress.building'     => 'required|string',
                    'billingAddress.emirates'     => 'required|string',
                    ]);
        
                if ($validator->fails()) {
                    return response()->json($validator->errors());
                }
                
                $exisCustomer = Customer::where('email', $request->billingAddress['email'])->orWhere('phone', $request->billingAddress['mobile'])->first();
        
                if (!$exisCustomer) {
                    $customer = Customer::create([
                        'name'      => $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                        'email'     => $request->input('billingAddress.email'),
                        'phone'     => $request->input('billingAddress.mobile'),
                        'password'  => $request->input('password') ? Hash::make($request->input('password')) : Hash::make('123456')
                    ]);

                    Address::create([
                        'name'      => $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                        'email'     => $request->input('billingAddress.email'),
                        'phone'     => $request->input('billingAddress.mobile'),
                        'state' => $request->input('billingAddress.emirates'),
                        'city' => $request->input('billingAddress.emirates'),
                        'country' => $request->input('billingAddress.country'),
                        'address' => $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                        'area' => $request->input('billingAddress.area'),
                        'customer_id' => $customer->id,
                    ]);

                    // $otp = rand(1111, 9999);

                    // $ch = curl_init();

                    // $passw = "11F2";
                    // $pass = "$";
                    // $p = "E89_6C3";
                    // $password = $passw.$pass.$p;

                    // curl_setopt($ch, CURLOPT_URL, "https://myinboxmedia.in/api/mim/SendSMS?userid=MIM2300278&pwd=".$password."&mobile=971".$request->input('billingAddress.mobile')."&sender=Ahmedper&msg=".$otp."".urlencode(' is your OTP for Registration')."&msgtype=16");
                    // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

                    // $result = curl_exec($ch);
                    // if (curl_errno($ch)) {
                    //     echo 'Error:' . curl_error($ch);die;
                    // }
                    // curl_close ($ch);

                    // $customer->otp = $otp;
                    // $customer->save();

                    $customer_id = $customer->id;
                } else {
                    $exisAddress = Address::where('customer_id', $exisCustomer->id)->first();
                    if(!$exisAddress) {
                        Address::create([
                            'name'      => $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                            'email'     => $request->input('billingAddress.email'),
                            'phone'     => $request->input('billingAddress.mobile'),
                            'state' => $request->input('billingAddress.emirates'),
                            'city' => $request->input('billingAddress.emirates'),
                            'country' => $request->input('billingAddress.country'),
                            'address' => $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                            'area' => $request->input('billingAddress.area'),
                            'customer_id' => $exisCustomer->id,
                        ]);
                    }
                    $customer_id = $exisCustomer->id;
                }
            }

            // echo "<pre>";print_r(([
            //     'user_id' => $customer_id,
            //     'shipping_method' => $request->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
            //     'shipping_option' => $request->input('shipping_option'),
            //     'shipping_amount' => $request->input('shippingPrice') ? : 0,
            //     'tax_amount' => (($request->input('finalPrice') - 3) / 100) * 5 ? : 0,
            //     'sub_total' => $request->input('totalPrice') ? : 0,
            //     'amount' => $request->input('finalPrice') ? : 0,
            //     'coupon_code' => $request->input('coupon_code'),
            //     'discount_amount' => $request->input('discount_amount') ? : 0,
            //     'promotion_amount' => $request->input('promotion_amount') ? : 0,
            //     'discount_description' => $request->input('discount_description'),
            //     'description' => $request->input('note'),
            //     'is_confirmed' => 1,
            //     'is_finished' => 1,
            //     'status' => OrderStatusEnum::PROCESSING,
            //     'lang' => $request->input('locale'),
            // ]));die();
            // echo "<pre>";print_r([
            //     'user_id' => $customer_id,
            //     'shipping_method' => $request->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
            //     'shipping_option' => $request->input('shipping_option'),
            //     'shipping_amount' => $request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)),
            //     'shipping_amount_vat' => $request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
            //     'service_amount' => $request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)),
            //     'service_amount_vat' => $request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
            //     'vat' => $request->input('vatTax'),
            //     'tax_amount' => ($request->input('totalPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('codPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)),
            //     'sub_total' => $request->input('totalPrice') ? : 0,
            //     'amount' => $request->input('finalPrice') ? : 0,
            //     'coupon_code' => $request->input('couponCode'),
            //     'discount_amount' => $request->input('discount_amount') ? : 0,
            //     'promotion_amount' => $request->input('promotion_amount') ? : 0,
            //     'discount_description' => $request->input('discount_description'),
            //     'description' => $request->input('note'),
            //     'is_confirmed' => 1,
            //     'is_finished' => 1,
            //     'status' => OrderStatusEnum::PROCESSING,
            //     'lang' => $request->input('locale'),
            //     'cod_charge' => $request->input('codPrice') / (1 + ($request->input('vatTax') / 100)),
            //     'cod_charge_vat' => $request->input('codPriceVat') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
            // ]);die();
            $userId = $customer_id;
            $now = Carbon::now();
            $fiveMinutesAgo = Carbon::now()->subMinutes(5);

            // Optionally, get order contents for matching (e.g. same total or cart hash)
            $total = $request->input('finalPrice'); // Example field

            $existingOrder = Order::where('user_id', $userId)
                ->where('amount', $total)
                ->where('created_at', '>=', $fiveMinutesAgo)
                ->whereHas('payment', function ($query) {
                    $query->where('status', 'completed');
                })
                ->first();

            if ($existingOrder) {
                return response()->json([
                    'duplicateOrderMessage' => 'Your order has been placed already. Order Id: ' . $existingOrder->code
                ]);
            }

            $tax = Tax::select('percentage')->where('status', 'published')->first();

            $order = Order::create([
                'user_id' => $customer_id,
                'shipping_method' => $request->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
                'shipping_option' => $request->input('shipping_option'),
                'shipping_amount' => $request->input('shippingPrice') / (1 + ($tax->percentage / 100)),
                'shipping_amount_vat' => $request->input('shippingPrice') / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100),
                'service_amount' => $request->input('servicePrice') / (1 + ($tax->percentage / 100)),
                'service_amount_vat' => $request->input('servicePrice') / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100),
                'vat' => $tax->percentage,
                'tax_amount' => ($request->input('totalPrice') / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)) + ($request->input('shippingPrice') / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)) + ($request->input('servicePrice') / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)) + ($request->input('codPrice') / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)),
                'sub_total' => $request->input('totalPrice') ? : 0,
                'amount' => $request->input('finalPrice') ? : 0,
                'coupon_code' => $request->input('couponCode'),
                'discount_amount' => $request->input('discount_amount') ? : 0,
                'promotion_amount' => $request->input('promotion_amount') ? : 0,
                'discount_description' => $request->input('discount_description'),
                'description' => $request->input('note'),
                'is_confirmed' => 1,
                'is_finished' => 1,
                'status' => ($request->input('payment_method') == 'paytabs' || $request->input('payment_method') == 'tamara' || $request->input('payment_method') == 'tabby') ? OrderStatusEnum::CANCELED : OrderStatusEnum::PROCESSING,
                'lang' => $request->input('locale'),
                'cod_charge' => $request->input('codPrice') / (1 + ($tax->percentage / 100)),
                'cod_charge_vat' => $request->input('codPrice') / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100),
            ]);

            // echo "<pre>";print_r($order);die();

            if($order) {

                $loopSubTotal = 0;
                $loopTaxTotal = 0;
                $loopGrandTotal = 0;

                if($request->input('customer_id')) {
                    $loggedInCustomer = Customer::where('id', $request->input('customer_id'))->first();
                    $loggedInCustomerAdd = Address::where('customer_id', $loggedInCustomer->id)->first();
                    if(!$loggedInCustomerAdd) {
                        Address::create([
                            'name'      => $loggedInCustomer->name,
                            'email'     => $loggedInCustomer->email,
                            'phone'     => $loggedInCustomer->phone,
                            'state' => $request->input('billingAddress.emirates'),
                            'city' => $request->input('billingAddress.emirates'),
                            'country' => $request->input('billingAddress.country'),
                            'address' => $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                            'area' => $request->input('billingAddress.area'),
                            'customer_id' => $loggedInCustomer->id,
                        ]);
                        $loggedInCustomerAdd = Address::where('customer_id', $loggedInCustomer->id)->first();
                    }
                    OrderAddress::query()->create([
                        'name' => $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $loggedInCustomer->name,
                        'phone' => $request->input('shippingAddress.mobile') ? $request->input('shippingAddress.mobile') : $loggedInCustomer->phone,
                        'email' => $request->input('shippingAddress.email') ? $request->input('shippingAddress.email') : $loggedInCustomer->email,
                        'state' => $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $loggedInCustomerAdd->state,
                        'city' => $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $loggedInCustomerAdd->city,
                        'country' => $request->input('shippingAddress.country') ? $request->input('shippingAddress.country') : $loggedInCustomerAdd->country,
                        'address' => $request->input('shippingAddress.area') ? $request->input('shippingAddress.area').' '.$request->input('shippingAddress.building') : $loggedInCustomerAdd->address,
                        'area' => $request->input('shippingAddress.area') ? $request->input('shippingAddress.area') : $request->input('billingAddress.area'),
                        'order_id' => $order->id,
                        'type' => $request->input('shippingAddress.first_name') ? 'shipping_address' : 'billing_address',
                    ]);

                    if($request->input('payment_method') == 'paytabs' || $request->input('payment_method') == 'tamara') {
                        $data = [
                            "name"=> $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $loggedInCustomer->name,
                            "email"=> $request->input('shippingAddress.email') ? $request->input('shippingAddress.email') : $loggedInCustomer->email,
                            "phone"=> $request->input('shippingAddress.mobile') ? $request->input('shippingAddress.mobile') : $loggedInCustomer->phone,
                            "street1"=> $request->input('shippingAddress.area') ? $request->input('shippingAddress.area').' '.$request->input('shippingAddress.building') : $loggedInCustomerAdd->address,
                            "city"=> $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $loggedInCustomerAdd->city,
                            "state"=> $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $loggedInCustomerAdd->state,
                            "country"=> "AE",
                            "first_name"=> $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name') : $loggedInCustomer->name,
                            "last_name"=> $request->input('shippingAddress.last_name') ? $request->input('shippingAddress.last_name') : $loggedInCustomer->name,
                            // "zip"=> "54321"
                        ];
                        // $resp = $this->payTabsPayment($request, $data);
                        // return response()->json([
                        //     'redirect_url'     => $resp['redirect_url']
                        // ]);
                    }

                } else {
                    OrderAddress::query()->create([
                        'name' => $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                        'phone' => $request->input('shippingAddress.mobile') ? $request->input('shippingAddress.mobile') : $request->input('billingAddress.mobile'),
                        'email' => $request->input('shippingAddress.email') ? $request->input('shippingAddress.email') : $request->input('billingAddress.email'),
                        'state' => $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $request->input('billingAddress.emirates'),
                        'city' => $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $request->input('billingAddress.emirates'),
                        // 'zip_code' => $request->input('shippingAddress.zip_code'),
                        'country' => $request->input('shippingAddress.country') ? $request->input('shippingAddress.country') : $request->input('billingAddress.country'),
                        'address' => $request->input('shippingAddress.area') ? $request->input('shippingAddress.area').' '.$request->input('shippingAddress.building') : $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                        'area' => $request->input('shippingAddress.area') ? $request->input('shippingAddress.area') : $request->input('billingAddress.area'),
                        'order_id' => $order->id,
                        'type' => $request->input('shippingAddress.first_name') ? 'shipping_address' : 'billing_address',
                    ]);

                    if($request->input('payment_method') == 'paytabs' || $request->input('payment_method') == 'tamara') {
                        $data = [
                            "name"=> $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                            "email"=> $request->input('shippingAddress.email') ? $request->input('shippingAddress.email') : $request->input('billingAddress.email'),
                            "phone"=> $request->input('shippingAddress.mobile') ? $request->input('shippingAddress.mobile') : $request->input('billingAddress.mobile'),
                            "street1"=> $request->input('shippingAddress.area') ? $request->input('shippingAddress.area').' '.$request->input('shippingAddress.building') : $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                            "city"=> $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $request->input('billingAddress.emirates'),
                            "state"=> $request->input('shippingAddress.emirates') ? $request->input('shippingAddress.emirates') : $request->input('billingAddress.emirates'),
                            "country"=> "AE",
                            "first_name"=> $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name') : $request->input('billingAddress.first_name'),
                            "last_name"=> $request->input('shippingAddress.last_name') ? $request->input('shippingAddress.last_name') : $request->input('billingAddress.last_name'),
                            // "zip"=> "54321"
                        ];
                        // $resp = $this->payTabsPayment($request, $data);
                        // return response()->json([
                        //     'redirect_url'     => $resp['redirect_url']
                        // ]);
                    }
                }
                // die();
                OrderHistory::query()->create([
                    'action' => OrderHistoryActionEnum::CREATE_ORDER_FROM_WEBSITE,
                    'description' => trans('plugins/ecommerce::order.create_order_from_website'),
                    'order_id' => $order->getKey(),
                ]);

                OrderHistory::query()->create([
                    'action' => OrderHistoryActionEnum::CREATE_ORDER,
                    'description' => trans(
                        'plugins/ecommerce::order.new_order',
                        ['order_id' => $order->code]
                    ),
                    'order_id' => $order->getKey(),
                ]);

                OrderHistory::query()->create([
                    'action' => OrderHistoryActionEnum::CONFIRM_ORDER,
                    'description' => trans('plugins/ecommerce::order.order_was_verified_by'),
                    'order_id' => $order->getKey(),
                    'user_id' => $customer_id,
                ]);

                $prod = array();
        
                foreach ($request->input('products') as $product) {
                    
                    $quantity = $product['quantity'] ? $product['quantity'] : 1;

                    // $exisProduct = Product::where('ec_products.id', $product['product_id'])
                    // // ->join('ec_tax_products', 'ec_products.id', '=', 'ec_tax_products.product_id')->join('ec_taxes', 'ec_taxes.id', '=', 'ec_tax_products.tax_id')
                    // ->first();
                    $exisProduct = $dbProducts->get($product['product_id']);

                    // $exisProduct->discount = DiscountProduct::select('value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->first();

                    // $coupons = DiscountProduct::select('code', 'value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->get();
                    
                    // $couponData = [];
                    // foreach ($coupons as $coupon) {
                    //     $couponData[strtolower($coupon->code)] = [
                    //         'code' => strtolower($coupon->code),
                    //         'value' => $coupon->value,
                    //         'start_date' => $coupon->start_date,
                    //         'end_date' => $coupon->end_date,
                    //     ];
                    // }

                    // $exisProduct->coupon = $couponData;

                    // Fetch active discount for the product
                    $exisProduct->discount = null;

                    $bogoOverrodeDiscount = isset($product['_original_discount']) && $product['_original_discount'] !== null;

                    if (!$bogoOverrodeDiscount) {

                        $individualDiscount = $dbIndividualDiscounts->first(function ($promo) use ($product) {
                            return collect($promo->discountRules)->some(function ($rule) use ($product) {
                                return collect($rule->individualRules)->contains('product_id', $product['product_id']);
                            });
                        });

                        if ($individualDiscount) {
                            $discountRule = collect($individualDiscount->discountRules)->first(function ($rule) use ($product) {
                                return collect($rule->individualRules)->contains('product_id', $product['product_id']);
                            });
                            $individualRule = $discountRule ? collect($discountRule->individualRules)->firstWhere('product_id', $product['product_id']) : null;
                            if ($individualRule) {
                                $exisProduct->discount = (object) [
                                    'name' => $individualDiscount->name,
                                    'value' => intval($individualRule->value),
                                    'apply_to' => $discountRule->apply_to,
                                    'discount_type' => $individualRule->discount_type,
                                    'product_price' => $individualRule->product_price,
                                    'discount_amount' => $individualRule->discount_amount,
                                    'final_price' => $individualRule->final_price,
                                    'start_date' => \Carbon\Carbon::parse($individualDiscount->start_date)->format('Y-m-d H:i:s'),
                                    'end_date' => \Carbon\Carbon::parse($individualDiscount->end_date)->format('Y-m-d H:i:s'),
                                ];
                            }
                        } else {
                            // If no individual discount, try to fetch discount for group/all products
                            $groupDiscount = $dbGroupDiscounts->first(function ($promo) use ($product) {
                                return collect($promo->discountRules)->some(function ($rule) use ($product) {
                                    return collect($rule->products)->contains('product_id', $product['product_id']);
                                });
                            });

                            if ($groupDiscount) {
                                $discountRule = collect($groupDiscount->discountRules)->first(function ($rule) use ($product) {
                                    return collect($rule->products)->contains('product_id', $product['product_id']);
                                });
                                if ($discountRule) {
                                    $exisProduct->discount = (object) [
                                        'name' => $groupDiscount->name,
                                        'value' => intval($discountRule->percentage),
                                        'apply_to' => $discountRule->apply_to,
                                        'discount_type' => 'percent',
                                        'product_price' => null,
                                        'discount_amount' => null,
                                        'final_price' => null,
                                        'start_date' => \Carbon\Carbon::parse($groupDiscount->start_date)->format('Y-m-d H:i:s'),
                                        'end_date' => \Carbon\Carbon::parse($groupDiscount->end_date)->format('Y-m-d H:i:s'),
                                    ];
                                }
                            }
                        }
                    }
                    // Fetch active coupons for the product
                    // $coupons = Promotion::where('type', 'coupon')
                    //     ->whereDate('start_date', '<=', now())
                    //     ->whereDate('end_date', '>=', now())
                    //     ->whereHas('couponRules.products', function ($query) use ($product) {
                    //         $query->where('product_id', $product['product_id']);
                    //     })
                    //     ->with(['couponRules' => function ($query) use ($product) {
                    //         $query->whereNotNull('coupon_code')
                    //             ->select('id', 'promotion_id', 'coupon_code', 'percentage')
                    //             ->with(['products' => function ($subQuery) use ($product) {
                    //                 $subQuery->where('product_id', $product['product_id'])
                    //                         ->select('id', 'coupon_rule_id', 'product_id');
                    //             }]);
                    //     }])
                    //     ->get();

                    // $couponData = [];
                    // foreach ($coupons as $promotion) {
                    //     foreach ($promotion->couponRules as $couponRule) {
                    //         if ($couponRule->coupon_code && $couponRule->products->isNotEmpty()) {
                    //             $couponData[strtolower($couponRule->coupon_code)] = [
                    //                 'code' => strtolower($couponRule->coupon_code),
                    //                 'value' => intval($couponRule->percentage),
                    //                 'start_date' => $promotion->start_date->format('Y-m-d H:i:s'),
                    //                 'end_date' => $promotion->end_date->format('Y-m-d H:i:s'),
                    //             ];
                    //         }
                    //     }
                    // }

                    // $exisProduct->coupon = $couponData;

                    // $customerCouponData = [];

                    // if ($coupons->isEmpty()) {
                    // $customer_coupons = DiscountCustomer::select('code', 'value', 'start_date', 'end_date')->where('customer_id', $customer_id)->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_customers.discount_id', 'left')->get();
                    // foreach ($customer_coupons as $customer_coupon) {
                    //     $customerCouponData[strtolower($customer_coupon->code)] = [
                    //         'code' => strtolower($customer_coupon->code),
                    //         'value' => $customer_coupon->value,
                    //         'start_date' => $customer_coupon->start_date,
                    //         'end_date' => $customer_coupon->end_date,
                    //     ];
                    // }
                    // $exisProduct->customer_coupon = $customerCouponData;

                    // $customerCoupons = Promotion::select('coupon_code AS code', 'percentage', 'amount', 'start_date', 'end_date', 'apply_to AS target', 'coupon_type')
                    //     ->leftJoin('coupon_rules', 'promotions.id', 'coupon_rules.promotion_id')
                    //     ->leftJoin('coupon_customers', 'coupon_rules.id', 'coupon_customers.coupon_rule_id')
                    //     ->where('type', 'coupon')
                    //     ->where('apply_to', 'customer')
                    //     ->where('customer_id', $customer_id)
                    //     ->whereDate('start_date', '<=', now())
                    //     ->whereDate('end_date', '>=', now())
                    //     ->get()
                    //     ->mapWithKeys(function ($coupon) {
                    //         return [
                    //             strtolower($coupon->code) => [
                    //                 'code' => strtolower($coupon->code),
                    //                 'value' => !is_null($coupon->percentage) && $coupon->coupon_type == 'percent' ? intval($coupon->percentage) : intval($coupon->amount),
                    //                 'start_date' => \Carbon\Carbon::parse($coupon->start_date)->format('Y-m-d H:i:s'),
                    //                 'end_date' => \Carbon\Carbon::parse($coupon->end_date)->format('Y-m-d H:i:s'),
                    //                 'type' => $coupon->target,
                    //                 'coupon_type' => $coupon->coupon_type
                    //             ],
                    //         ];
                    //     })
                    //     ->toArray();

                    // $exisProduct->customer_coupon = empty($customerCoupons) ? [] : $customerCoupons;
                    // }

                    $exisProduct->qty = $quantity;

                    // print_r($exisProduct);

                    if((isset($product['is_gift']) && $product['is_gift'] == true)) {
                        $exisProduct->is_gift = 1;
                    }

                    // if((isset($product['is_customer_coupon']) && $product['is_customer_coupon'] == true)) {
                    //     $exisProduct->is_customer_coupon = 1;
                    // }

                    if((isset($product['is_coupon']) && $product['is_coupon'] == true)) {
                        $exisProduct->is_coupon = 1;
                    }

                    array_push($prod, $exisProduct);

                    // $discount_price = '';
                    // $sale_price = '';
                    if(!is_null($exisProduct->discount) && $exisProduct->is_gift != 1 && $exisProduct->is_coupon != 1) {
                        if($exisProduct->discount->discount_type == 'percent') {
                            // echo "Discount Percent";
                            // echo "\n";
                            $price = $exisProduct->price / (1 + ($tax->percentage / 100));
                            $total_amount = $price * $quantity;
                            $discount_percent = $exisProduct->discount->value;
                            $discount_amount = ($total_amount / 100) * $discount_percent;
                            $net_amount = $total_amount - $discount_amount;
                            $tax_amount = ($net_amount / 100) * $tax->percentage;
                            $gross_amount = $net_amount + $tax_amount;
                            $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                        
                            $orderProduct = [
                                'order_id' => $order->id,
                                'product_id' => $product['product_id'],
                                'product_name' => $exisProduct->name,
                                'product_image' => $exisProduct->image,
                                'qty' => $quantity,
                                'weight' => $exisProduct->weight,
                                'price' => $price,
                                'total_amount' => $total_amount,
                                'discount_percent' => $discount_percent,
                                'discount_amount' => $discount_amount,
                                'net_amount' => $net_amount,
                                'tax_amount' => $tax_amount,
                                'gross_amount' => $gross_amount,
                                'product_options' => [],
                                'options' => json_encode($options),
                                'product_type' => $exisProduct->product_type,
                                'product_category' => $product['category_name'] ? $product['category_name'] : '',
                                'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                                'vat' => $tax->percentage,
                                'campaign' => $exisProduct->discount->name,
                            ];   
                        } elseif($exisProduct->discount->discount_type == 'amount') {
                            // echo "Discount Amount";
                            // echo "\n";
                            $price = $exisProduct->price / (1 + ($tax->percentage / 100));
                            $total_amount = $price * $quantity;
                            $sale_price = $exisProduct->discount->final_price / (1 + ($tax->percentage / 100));
                            $discount_percent = 0;
                            $discount_amount = $total_amount - ($sale_price * $quantity);
                            $net_amount = $total_amount - $discount_amount;
                            $tax_amount = ($net_amount / 100) * $tax->percentage;
                            $gross_amount = $net_amount + $tax_amount;
                            // echo "Price ".$price;
                            // echo "\n";
                            // echo "Total Amount ".$total_amount;
                            // echo "\n";
                            // echo "Sales Price ".$sale_price;
                            // echo "\n";
                            // echo "Discount Percent ".$discount_percent;
                            // echo "\n";
                            // echo "Discount Amount ".$discount_amount;
                            // echo "\n";
                            // echo "Net Amount ".$net_amount;
                            // echo "\n";
                            // echo "Tax Amount ".$tax_amount;
                            // echo "\n";
                            // echo "Gross Amount ".$gross_amount;
                            // echo "\n";
                            $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                        
                            $orderProduct = [
                                'order_id' => $order->id,
                                'product_id' => $product['product_id'],
                                'product_name' => $exisProduct->name,
                                'product_image' => $exisProduct->image,
                                'qty' => $quantity,
                                'weight' => $exisProduct->weight,
                                'price' => $price,
                                'total_amount' => $total_amount,
                                'discount_percent' => $discount_percent,
                                'discount_amount' => $discount_amount,
                                'net_amount' => $net_amount,
                                'tax_amount' => $tax_amount,
                                'gross_amount' => $gross_amount,
                                'product_options' => [],
                                'options' => json_encode($options),
                                'product_type' => $exisProduct->product_type,
                                'product_category' => $product['category_name'],
                                'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                                'vat' => $tax->percentage,
                                'campaign' => $exisProduct->discount->name,
                            ];
                        }
                        $loopSubTotal += $gross_amount;
                        $loopTaxTotal += $tax_amount;
                    }
                    // elseif(!empty($product['coupon']) && !is_null($exisProduct->coupon) && !empty($exisProduct->coupon) && isset($exisProduct->coupon) && isset($exisProduct->coupon[strtolower($request->input('couponCode'))]) && $exisProduct->coupon[strtolower($request->input('couponCode'))]['code'] == strtolower($request->input('couponCode'))) {
                    //     $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    //     $total_amount = $price * $quantity;
                    //     $discount_percent = $exisProduct->coupon[strtolower($request->input('couponCode'))]['value'];
                    //     $discount_amount = ($total_amount / 100) * $discount_percent;
                    //     $net_amount = $total_amount - $discount_amount;
                    //     $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                    //     $gross_amount = $net_amount + $tax_amount;
                    //     $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                    //     $orderProduct = [
                    //         'order_id' => $order->id,
                    //         'product_id' => $product['product_id'],
                    //         'product_name' => $exisProduct->name,
                    //         'product_image' => $exisProduct->image,
                    //         'qty' => $quantity,
                    //         'weight' => $exisProduct->weight,
                    //         'price' => $price,
                    //         'total_amount' => $total_amount,
                    //         'discount_percent' => $discount_percent,
                    //         'discount_amount' => $discount_amount,
                    //         'net_amount' => $net_amount,
                    //         'tax_amount' => $tax_amount,
                    //         'gross_amount' => $gross_amount,
                    //         'product_options' => [],
                    //         'options' => json_encode($options),
                    //         'product_type' => $exisProduct->product_type,
                    //         'product_category' => $product['category_name'],
                    //         'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                    //         'vat' => $request->input('vatTax'),
                    //         'campaign' => strtolower($request->input('couponCode')) == 'welcome10' ? 'first_order_discount_2025' : $request->input('couponCode'),
                    //     ];
                    // }
                    // elseif(isset($product['is_customer_coupon']) && !isset($product['is_gift']) && is_null($exisProduct->sale_price) && !is_null($exisProduct->customer_coupon) && !empty($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]) && $exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['code'] == strtolower($request->input('couponCode'))) {
                    elseif(isset($product['is_coupon']) && !isset($product['is_gift']) && is_null($exisProduct->sale_price)) {
                        // if($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['coupon_type'] == 'percent') {
                            // echo 'Customer Coupon';
                            // echo '\n ';
                            $price = $exisProduct->price / (1 + ($tax->percentage / 100));
                            $total_amount = $price * $quantity;
                            $discount_percent = $product['value'];
                            $discount_amount = ($total_amount / 100) * $discount_percent;
                            $net_amount = $total_amount - $discount_amount;
                            $tax_amount = ($net_amount / 100) * $tax->percentage;
                            $gross_amount = $net_amount + $tax_amount;
                            $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                        
                            $orderProduct = [
                                'order_id' => $order->id,
                                'product_id' => $product['product_id'],
                                'product_name' => $exisProduct->name,
                                'product_image' => $exisProduct->image,
                                'qty' => $quantity,
                                'weight' => $exisProduct->weight,
                                'price' => $price,
                                'total_amount' => $total_amount,
                                'discount_percent' => $discount_percent,
                                'discount_amount' => $discount_amount,
                                'net_amount' => $net_amount,
                                'tax_amount' => $tax_amount,
                                'gross_amount' => $gross_amount,
                                'product_options' => [],
                                'options' => json_encode($options),
                                'product_type' => $exisProduct->product_type,
                                'product_category' => $product['category_name'],
                                'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                                'vat' => $tax->percentage,
                                'campaign' => $request->input('couponCode'),
                            ];
                            $loopSubTotal += $gross_amount;
                            $loopTaxTotal += $tax_amount;
                        // } elseif($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['coupon_type'] == 'amount') {
                        //     // echo 'Customer Coupon Amount';
                        //     // echo '\n ';
                        //     $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                        //     $total_amount = $price * $quantity;
                        //     $sale_price = $price - ($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['value'] / (1 + ($request->input('vatTax') / 100)));
                        //     $discount_percent = 0;
                        //     $discount_amount = $total_amount - ($sale_price * $quantity);
                        //     $net_amount = $total_amount - $discount_amount;
                        //     $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                        //     $gross_amount = $net_amount + $tax_amount;

                        //     // echo "Price ".$price;
                        //     // echo "\n";
                        //     // echo "Total Amount ".$total_amount;
                        //     // echo "\n";
                        //     // echo "Sales Price ".$sale_price;
                        //     // echo "\n";
                        //     // echo "Discount Percent ".$discount_percent;
                        //     // echo "\n";
                        //     // echo "Discount Amount ".$discount_amount;
                        //     // echo "\n";
                        //     // echo "Net Amount ".$net_amount;
                        //     // echo "\n";
                        //     // echo "Tax Amount ".$tax_amount;
                        //     // echo "\n";
                        //     // echo "Gross Amount ".$gross_amount;
                        //     // echo "\n";
                        //     // echo $exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['value'];
                            
                        //     $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                        
                        //     $orderProduct = [
                        //         'order_id' => $order->id,
                        //         'product_id' => $product['product_id'],
                        //         'product_name' => $exisProduct->name,
                        //         'product_image' => $exisProduct->image,
                        //         'qty' => $quantity,
                        //         'weight' => $exisProduct->weight,
                        //         'price' => $price,
                        //         'total_amount' => $total_amount,
                        //         'discount_percent' => $discount_percent,
                        //         'discount_amount' => $discount_amount,
                        //         'net_amount' => $net_amount,
                        //         'tax_amount' => $tax_amount,
                        //         'gross_amount' => $gross_amount,
                        //         'product_options' => [],
                        //         'options' => json_encode($options),
                        //         'product_type' => $exisProduct->product_type,
                        //         'product_category' => $product['category_name'],
                        //         'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                        //         'vat' => $request->input('vatTax'),
                        //         'campaign' => $request->input('couponCode'),
                        //     ];
                        // }
                    }
                    // elseif(!is_null($exisProduct->sale_price)) {
                    //     $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                    //     $total_amount = $price * $quantity;
                    //     $sale_price = $exisProduct->sale_price / (1 + ($request->input('vatTax') / 100));
                    //     $discount_percent = 0;
                    //     $discount_amount = $total_amount - ($sale_price * $quantity);
                    //     $net_amount = $total_amount - $discount_amount;
                    //     $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                    //     $gross_amount = $net_amount + $tax_amount;
                    //     $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                    //     $orderProduct = [
                    //         'order_id' => $order->id,
                    //         'product_id' => $product['product_id'],
                    //         'product_name' => $exisProduct->name,
                    //         'product_image' => $exisProduct->image,
                    //         'qty' => $quantity,
                    //         'weight' => $exisProduct->weight,
                    //         'price' => $price,
                    //         'total_amount' => $total_amount,
                    //         'discount_percent' => $discount_percent,
                    //         'discount_amount' => $discount_amount,
                    //         'net_amount' => $net_amount,
                    //         'tax_amount' => $tax_amount,
                    //         'gross_amount' => $gross_amount,
                    //         'product_options' => [],
                    //         'options' => json_encode($options),
                    //         'product_type' => $exisProduct->product_type,
                    //         'product_category' => $product['category_name'],
                    //         'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                    //         'vat' => $request->input('vatTax'),
                    //     ];
                    // }
                    elseif(isset($product['is_gift']) && $product['is_gift'] == true) {
                        $price = $exisProduct->price / (1 + ($tax->percentage / 100));
                        $total_amount = 0.00;
                        $discount_percent = 100;
                        $discount_amount = $exisProduct->price / (1 + ($tax->percentage / 100));
                        $net_amount = 0.00;
                        $tax_amount = 0.00;
                        $gross_amount = 0.00;
                        $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                        $orderProduct = [
                            'order_id' => $order->id,
                            'product_id' => $product['product_id'],
                            'product_name' => $exisProduct->name,
                            'product_image' => $exisProduct->image,
                            'qty' => $quantity,
                            'weight' => $exisProduct->weight,
                            'price' => $price,
                            'total_amount' => $total_amount,
                            'discount_percent' => $discount_percent,
                            'discount_amount' => $discount_amount,
                            'net_amount' => $net_amount,
                            'tax_amount' => $tax_amount,
                            'gross_amount' => $gross_amount,
                            'product_options' => [],
                            'options' => json_encode($options),
                            'product_type' => $exisProduct->product_type,
                            'product_category' => '',
                            'product_subcategory' => '',
                            'vat' => $tax->percentage,
                            'is_gift' => 1,
                            'campaign' => $product['campaign'],
                        ];
                    }
                    else {
                        $price = $exisProduct->price / (1 + ($tax->percentage / 100));
                        $total_amount = $price * $quantity;
                        $discount_percent = 0;
                        $discount_amount = 0.00;
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $tax->percentage;
                        $gross_amount = $net_amount + $tax_amount;
                        $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                        $orderProduct = [
                            'order_id' => $order->id,
                            'product_id' => $product['product_id'],
                            'product_name' => $exisProduct->name,
                            'product_image' => $exisProduct->image,
                            'qty' => $quantity,
                            'weight' => $exisProduct->weight,
                            'price' => $price,
                            'total_amount' => $total_amount,
                            'discount_percent' => $discount_percent,
                            'discount_amount' => $discount_amount,
                            'net_amount' => $net_amount,
                            'tax_amount' => $tax_amount,
                            'gross_amount' => $gross_amount,
                            'product_options' => [],
                            'options' => json_encode($options),
                            'product_type' => $exisProduct->product_type,
                            'product_category' => $product['category_name'],
                            'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                            'vat' => $tax->percentage,
                            'campaign' => !empty($product['collection_name']) ? 'K Series '.$product['product_name'] : '',
                        ];
                        $loopSubTotal += $gross_amount;
                        $loopTaxTotal += $tax_amount;
                    }

                    OrderProduct::query()->create($orderProduct);

                    // Product::query()
                    //     ->where('id', $product['product_id'])
                    //     ->where('with_storehouse_management', 1)
                    //     ->where('quantity', '>=', $quantity)
                    //     ->decrement('quantity', $quantity);

                    // $ch = curl_init();

                    // curl_setopt($ch, CURLOPT_URL, $url);
                    // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    // // Set the request method to POST
                    // curl_setopt($ch, CURLOPT_POST, true);
                    // curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    //     "Accept: application/json",
                    //     "Company: UAE", 
                    //     "Authorization: ". env('SMART_VIEW_TOKEN')
                    // ]);

                    // $response = curl_exec($ch);

                    // if (curl_errno($ch)) {
                    //     echo 'Error: ' . curl_error($ch);
                    // }

                    // curl_close($ch);

                    // echo $response;
                }
                // die(';;;');

                // $url = env('SMART_VIEW_STOCK_API_URL')."ECommerce/StockStatus?itemCode=".implode(',', $barcodes);

                // $ch = curl_init();

                // curl_setopt($ch, CURLOPT_URL, $url);
                // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                // // Set the request method to POST
                // curl_setopt($ch, CURLOPT_POST, true);
                // curl_setopt($ch, CURLOPT_HTTPHEADER, [
                //     "Accept: application/json",
                //     "Company: UAE", 
                //     "Authorization: ". env('SMART_VIEW_TOKEN')
                // ]);

                // $response = curl_exec($ch);

                // if (curl_errno($ch)) {
                //     // echo 'Error: ' . curl_error($ch);
                //     \Log::info('Stock API Error2:', ['error' => curl_error($ch)]);
                // }

                // curl_close($ch);
                // $resp = json_decode($response);
                // // print_r($resp->data);die;
                // \Log::info('Stock API Response2:', ['response' => $resp]);

                if($cashback) {
                    $customer_cash_back_coupon = DB::table('coupon_customers')->where('customer_id', $customer_id)->where('cashback_rule_id', $cashback->id)->first();

                    if (in_array($product['product_id'], $cashback_product_ids) && !$customer_cash_back_coupon) {
                        $start_date = now();
                        $exist_coupon_rule = Promotion::select('coupon_rules.id')->where('coupon_code', $coupon_code)->where('type', 'coupon')->where('start_date', '<=', now())->where('end_date', '>=', now())->leftJoin('coupon_rules', 'promotions.id', '=', 'coupon_rules.promotion_id')->first();

                        if (!$exist_coupon_rule) {
                            $promotion = Promotion::create([
                                'name'      => $coupon_code,
                                'type'     => 'coupon',
                                'start_date'     => $start_date,
                                'end_date' => Carbon::parse($start_date)->addDays($cashback->duration),
                            ]);
                            if($promotion) {
                                $coupon_rule = CouponRule::create([
                                    'promotion_id'      => $promotion->id,
                                    'coupon_code'     => $coupon_code,
                                    'apply_to' => 'customer',
                                    'coupon_type' => $coupon_type,
                                    'percentage' => $cashback->cashback_percentage,
                                    'amount' => $cashback->cashback_amount,
                                ]);
                                if($coupon_rule) {
                                    DB::table('coupon_customers')->insert([
                                        'coupon_rule_id' => $coupon_rule->id,
                                        'cashback_rule_id' => $cashback->id,
                                        'customer_id' => $customer_id,
                                        'created_at' => now()
                                    ]);
                                }
                            }
                        } else {
                            DB::table('coupon_customers')->insert([
                                'coupon_rule_id' => $exist_coupon_rule->id,
                                'cashback_rule_id' => $cashback->id,
                                'customer_id' => $customer_id,
                                'created_at' => now()
                            ]);
                        }
                        
                        // if($promotion) {
                        //     $coupon_rule = CouponRule::create([
                        //         'promotion_id'      => $promotion->id,
                        //         'coupon_code'     => $coupon_code,
                        //         'apply_to' => 'customer',
                        //         'coupon_type' => $coupon_type,
                        //         'percentage' => $cashback->cashback_percentage,
                        //         'amount' => $cashback->cashback_amount,
                        //     ]);

                        //     if($coupon_rule) {
                        //         DB::table('coupon_customers')->insert([
                        //             'coupon_rule_id' => $coupon_rule->id,
                        //             'cashback_rule_id' => $cashback->id,
                        //             'customer_id' => $customer_id,
                        //             'created_at' => now()
                        //         ]);
                        //     }
                        // }
                    }
                }

                $shipping_service_charges = ShippingRule::select('price')->get();
                $serviceAmount = round((float) $shipping_service_charges[1]->price, 2);
                $freeShippingThreshold = round((float) $shipping_service_charges[3]->price, 2);
                $codAmount = 0;
                if($request->input('payment_method') == 'cod') {
                    $codAmount = round((float) $shipping_service_charges[2]->price, 2);
                }
                // echo $freeShippingThreshold.'----'.$loopSubTotal;
                $subtotal = round((float)$loopSubTotal, 2);
                $threshold = (float)($freeShippingThreshold ?? 400);
                if ($subtotal >= $threshold) {
                    $loopGrandTotal = $subtotal;
                    $loopGrandTotal += $serviceAmount;
                    $shippingAmount = 0;
                    // die('if');
                } else {
                    $tax = Tax::select('percentage')->where('status', 'published')->first();
                    $loopGrandTotal = $subtotal;
                    $shippingAmount = round((float) $shipping_service_charges[0]->price, 2);
                    $loopGrandTotal += $shippingAmount;
                    $loopGrandTotal += $serviceAmount;
                    // die('else');
                }

                $order->update([
                    'sub_total' => $subtotal,
                    'tax_amount' => $loopTaxTotal + ($shippingAmount / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)) + ($serviceAmount / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)) + ($codAmount / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)),
                    'amount' => $loopGrandTotal + $codAmount,
                    'shipping_amount' => $shippingAmount / (1 + ($tax->percentage / 100)),
                    'shipping_amount_vat' => $shippingAmount / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100),
                    'service_amount' => $serviceAmount / (1 + ($tax->percentage / 100)),
                    'service_amount_vat' => $serviceAmount / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100),
                    'cod_charge' => $codAmount / (1 + ($tax->percentage / 100)),
                    'cod_charge_vat' => $codAmount / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100),
                ]);

                // if ($couponCode = $request->input('couponCode')) {
                //     // Discount::getFacadeRoot()->afterOrderPlaced($couponCode, $request->input('customer_id') ? $request->input('customer_id') : $customer_id);

                //     $now = Carbon::now();

                //     $coupon = DB::table('coupon_rules')
                //     ->join('promotions', 'promotions.id', '=', 'coupon_rules.promotion_id')
                //     ->where('coupon_code', $couponCode)
                //     ->where('type', 'coupon')
                //     ->where('start_date', '<=', $now)
                //     ->Where('end_date', '>', $now)
                //     ->select('coupon_rules.id', 'coupon_rules.promotion_id')
                //     ->first();

                //     if ($coupon) {
                //         DB::table('coupon_rules')->where('id', $coupon->id)->increment('total_used');
                //         $promotionId = $coupon->promotion_id;

                //         DB::table('ec_customer_used_coupons')->insert([
                //             'customer_id' => $request->input('customer_id') ?? $customer_id,
                //             'discount_id' => $promotionId
                //         ]);
                //     }
                // }

                // $coupon_code = $request->input('couponCode');
                // if(isset($coupon_code) && !empty($request->input('couponCode'))) {
                //     $curl = curl_init();

                //     $payload = [
                //         'couponRegistrationId' => $decode->data[0]->couponRegistrationId,
                //         // 'couponId'             => $decode->data[0]->couponId,
                //         'refDocNo'             => $order->code,
                //         'salesType'            => $decode->data[0]->salesType,
                //         'company'              => $decode->data[0]->company,
                //         'whsCode'              => $decode->data[0]->whsCode,
                //         'custNo'               => $customer_id,
                //         'mobileNo'             => $request->input('billingAddress.mobile'),
                //         // 'discAmount'           => 27.50,
                //         'netAmount'            => $order->amount,
                //     ];
                //     if ($couponRegistrationId == 0) {
                //         $payload['couponCode'] = $coupon_code;
                //     }
                    
                //     \Log::info('Coupon Redeem Payload:', ['request' => $payload]);

                //     curl_setopt_array($curl, [
                //         CURLOPT_URL            => env('SMART_VIEW_COUPON_API_URL') . 'Coupon/Redeem',
                //         CURLOPT_RETURNTRANSFER => true,
                //         CURLOPT_ENCODING       => '',
                //         CURLOPT_MAXREDIRS      => 10,
                //         CURLOPT_TIMEOUT        => 0,
                //         CURLOPT_FOLLOWLOCATION => true,
                //         CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                //         CURLOPT_CUSTOMREQUEST  => 'POST',
                //         CURLOPT_POSTFIELDS     => json_encode($payload),
                //         CURLOPT_HTTPHEADER     => [
                //             'Content-Type: application/json'
                //         ],
                //     ]);

                //     $response = curl_exec($curl);

                //     if (curl_errno($curl)) {
                //         // echo 'order_authorised Curl error: ' . curl_error($ch);
                //         \Log::info('Coupon Redeem API Error:', ['error' => curl_error($curl)]);
                //     }

                //     curl_close($curl);

                //     $redeem_resp = json_decode($response, true);

                //     // echo "<pre>";print_r($refund_resp);exit;

                //     \Log::info('Order Redeem Response:', ['response' => $redeem_resp]);
                // }

                if($request->input('customer_id')) {
                    $loggedInCustomer = Customer::where('id', $request->input('customer_id'))->first();
                } else {
                    $loggedInCustomer = null;
                }

                // $invoice = Invoice::query()->create([
                //     'reference_type' => 'Botble\Ecommerce\Models\Order',
                //     'reference_id' => $order->id,
                //     'customer_name' => $loggedInCustomer ? $loggedInCustomer->name : $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                //     'customer_email' => $loggedInCustomer ? $loggedInCustomer->email : $request->input('billingAddress.email'),
                //     'customer_phone' => $loggedInCustomer ? $loggedInCustomer->phone : $request->input('billingAddress.mobile'),
                //     'customer_address' => $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                //     'sub_total' => $request->input('totalPrice') ? : 0,
                //     'tax_amount' => ($request->input('totalPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)),
                //     'shipping_amount' => $request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)),
                //     'shipping_amount_vat' => $request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
                //     'service_amount' => $request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)),
                //     'service_amount_vat' => $request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
                //     'vat' => $request->input('vatTax'),
                //     'discount_amount' => $request->input('discount_amount') ? : 0,
                //     'shipping_method' => $request->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
                //     'coupon_code' => $request->input('couponCode'),
                //     'discount_description' => $request->input('discount_description'),
                //     'amount' => $request->input('finalPrice'),
                //     'payment_id' => $order->payment_id,
                //     'status' => $request->input('payment_status'),
                // ]);

                // foreach ($request->input('products') as $product) {
                    
                //     $quantity = $product['quantity'] ? $product['quantity'] : 1;

                //     $exisProduct = Product::where('id', $product['product_id'])->first();

                //     // $exisProduct->discount = DiscountProduct::select('value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->first();

                //     // $coupons = DiscountProduct::select('code', 'value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->get();

                //     // // Store in a temporary property or a new array
                //     // $couponData = [];
                //     // foreach ($coupons as $coupon) {
                //     //     $couponData[strtolower($coupon->code)] = [
                //     //         'code' => strtolower($coupon->code),
                //     //         'value' => $coupon->value,
                //     //         'start_date' => $coupon->start_date,
                //     //         'end_date' => $coupon->end_date,
                //     //     ];
                //     // }

                //     // Fetch active discount for the product
                //     $exisProduct->discount = null;

                //     $individualDiscount = Promotion::where('type', 'discount')
                //         ->whereDate('start_date', '<=', now())
                //         ->whereDate('end_date', '>=', now())
                //         ->whereHas('discountRules', function ($query) {
                //             $query->where('apply_to', 'individual');
                //         })
                //         ->whereHas('discountRules.individualRules', function ($query) use ($product) {
                //             $query->where('product_id', $product['product_id']);
                //         })
                //         ->with(['discountRules' => function ($query) {
                //             $query->where('apply_to', 'individual')
                //                 ->select('id', 'promotion_id', 'apply_to');
                //         }, 'discountRules.individualRules' => function ($query) use ($product) {
                //             $query->where('product_id', $product['product_id'])
                //                 ->select('discount_rule_id', 'product_id', 'value', 'discount_type', 'product_price', 'discount_amount', 'final_price');
                //         }])
                //         ->first();

                //     if ($individualDiscount) {
                //         $discountRule = $individualDiscount->discountRules->first();
                //         $individualRule = $discountRule ? $discountRule->individualRules->first() : null;
                //         if ($individualRule) {
                //             $exisProduct->discount = (object) [
                //                 'value' => intval($individualRule->value),
                //                 'apply_to' => $discountRule->apply_to,
                //                 'discount_type' => $individualRule->discount_type,
                //                 'product_price' => $individualRule->product_price,
                //                 'discount_amount' => $individualRule->discount_amount,
                //                 'final_price' => $individualRule->final_price,
                //                 'start_date' => $individualDiscount->start_date->format('Y-m-d H:i:s'),
                //                 'end_date' => $individualDiscount->end_date->format('Y-m-d H:i:s'),
                //             ];
                //         }
                //     } else {
                //         // If no individual discount, try to fetch discount for group/all products
                //         $groupDiscount = Promotion::where('type', 'discount')
                //             ->whereDate('start_date', '<=', now())
                //             ->whereDate('end_date', '>=', now())
                //             ->whereHas('discountRules', function ($query) {
                //                 $query->where('apply_to', '!=', 'individual');
                //             })
                //             ->whereHas('discountRules.products', function ($query) use ($product) {
                //                 $query->where('product_id', $product['product_id']);
                //             })
                //             ->with(['discountRules' => function ($query) {
                //                 $query->where('apply_to', '!=', 'individual')
                //                     ->select('id', 'promotion_id', 'percentage', 'apply_to');
                //             }])
                //             ->first();

                //         if ($groupDiscount) {
                //             $discountRule = $groupDiscount->discountRules->first();
                //             if ($discountRule) {
                //                 $exisProduct->discount = (object) [
                //                     'value' => intval($discountRule->percentage),
                //                     'apply_to' => $discountRule->apply_to,
                //                     'discount_type' => 'percent',
                //                     'product_price' => null,
                //                     'discount_amount' => null,
                //                     'final_price' => null,
                //                     'start_date' => $groupDiscount->start_date->format('Y-m-d H:i:s'),
                //                     'end_date' => $groupDiscount->end_date->format('Y-m-d H:i:s'),
                //                 ];
                //             }
                //         }
                //     }

                //     // Fetch active coupons for the product
                //     // $coupons = Promotion::where('type', 'coupon')
                //     //     ->whereDate('start_date', '<=', now())
                //     //     ->whereDate('end_date', '>=', now())
                //     //     ->whereHas('couponRules.products', function ($query) use ($product) {
                //     //         $query->where('product_id', $product['product_id']);
                //     //     })
                //     //     ->with(['couponRules' => function ($query) use ($product) {
                //     //         $query->whereNotNull('coupon_code')
                //     //             ->select('id', 'promotion_id', 'coupon_code', 'percentage')
                //     //             ->with(['products' => function ($subQuery) use ($product) {
                //     //                 $subQuery->where('product_id', $product['product_id'])
                //     //                         ->select('id', 'coupon_rule_id', 'product_id');
                //     //             }]);
                //     //     }])
                //     //     ->get();

                //     // $couponData = [];
                //     // foreach ($coupons as $promotion) {
                //     //     foreach ($promotion->couponRules as $couponRule) {
                //     //         if ($couponRule->coupon_code && $couponRule->products->isNotEmpty()) {
                //     //             $couponData[strtolower($couponRule->coupon_code)] = [
                //     //                 'code' => strtolower($couponRule->coupon_code),
                //     //                 'value' => intval($couponRule->percentage),
                //     //                 'start_date' => $promotion->start_date->format('Y-m-d H:i:s'),
                //     //                 'end_date' => $promotion->end_date->format('Y-m-d H:i:s'),
                //     //             ];
                //     //         }
                //     //     }
                //     // }

                //     // $exisProduct->coupon = $couponData;

                //      // $customerCouponData = [];

                //     // if ($coupons->isEmpty()) {
                //     // $customer_coupons = DiscountCustomer::select('code', 'value', 'start_date', 'end_date')->where('customer_id', $customer_id)->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_customers.discount_id', 'left')->get();
                //     // foreach ($customer_coupons as $customer_coupon) {
                //     //     $customerCouponData[strtolower($customer_coupon->code)] = [
                //     //         'code' => strtolower($customer_coupon->code),
                //     //         'value' => $customer_coupon->value,
                //     //         'start_date' => $customer_coupon->start_date,
                //     //         'end_date' => $customer_coupon->end_date,
                //     //     ];
                //     // }
                //     // $exisProduct->customer_coupon = $customerCouponData;

                //     // $customerCoupons = Promotion::select('coupon_code AS code', 'percentage', 'amount', 'start_date', 'end_date', 'apply_to AS target', 'coupon_type')
                //     //     ->leftJoin('coupon_rules', 'promotions.id', 'coupon_rules.promotion_id')
                //     //     ->leftJoin('coupon_customers', 'coupon_rules.id', 'coupon_customers.coupon_rule_id')
                //     //     ->where('type', 'coupon')
                //     //     ->where('apply_to', 'customer')
                //     //     ->where('customer_id', $customer_id)
                //     //     ->whereDate('start_date', '<=', now())
                //     //     ->whereDate('end_date', '>=', now())
                //     //     ->get()
                //     //     ->mapWithKeys(function ($coupon) {
                //     //         return [
                //     //             strtolower($coupon->code) => [
                //     //                 'code' => strtolower($coupon->code),
                //     //                 'value' => !is_null($coupon->percentage) && $coupon->coupon_type == 'percent' ? intval($coupon->percentage) : intval($coupon->amount),
                //     //                 'start_date' => \Carbon\Carbon::parse($coupon->start_date)->format('Y-m-d H:i:s'),
                //     //                 'end_date' => \Carbon\Carbon::parse($coupon->end_date)->format('Y-m-d H:i:s'),
                //     //                 'type' => $coupon->target,
                //     //                 'coupon_type' => $coupon->coupon_type
                //     //             ],
                //     //         ];
                //     //     })
                //     //     ->toArray();

                //     // $exisProduct->customer_coupon = empty($customerCoupons) ? [] : $customerCoupons;
                //     // }

                //     if(!is_null($exisProduct->discount)) {
                //        if($exisProduct->discount->discount_type == 'percent') {
                //             $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                //             $total_amount = $price * $quantity;
                //             $discount_percent = $exisProduct->discount->value;
                //             $discount_amount = ($total_amount / 100) * $discount_percent;
                //             $net_amount = $total_amount - $discount_amount;
                //             $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                //             $gross_amount = $net_amount + $tax_amount;
                //             $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                        
                //             $orderProduct = [
                //                 'invoice_id' => $invoice->id,
                //                 'reference_type' => 'Botble\Ecommerce\Models\Product',
                //                 'reference_id' => $exisProduct->id,
                //                 'name' => $exisProduct->name,
                //                 // 'description' => $exisProduct->description,
                //                 'image' => $exisProduct->image,
                //                 'qty' => $quantity,
                //                 'price' => $price,
                //                 'sub_total' => $total_amount,
                //                 'discount_percent' => $discount_percent,
                //                 'discount_amount' => $discount_amount,
                //                 'net_amount' => $net_amount,
                //                 'tax_amount' => $tax_amount,
                //                 'gross_amount' => $gross_amount,
                //                 'amount' => $gross_amount,
                //                 'options' => json_encode($options),
                //             ];   
                //         } elseif($exisProduct->discount->discount_type == 'amount') {
                //             $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                //             $total_amount = $price * $quantity;
                //             $sale_price = $price - $exisProduct->discount->value;
                //             $discount_percent = 0;
                //             $discount_amount = $total_amount - ($sale_price * $quantity);
                //             $net_amount = $total_amount - $discount_amount;
                //             $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                //             $gross_amount = $net_amount + $tax_amount;
                //             $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                        
                //             $orderProduct = [
                //                 'invoice_id' => $invoice->id,
                //                 'reference_type' => 'Botble\Ecommerce\Models\Product',
                //                 'reference_id' => $exisProduct->id,
                //                 'name' => $exisProduct->name,
                //                 // 'description' => $exisProduct->description,
                //                 'image' => $exisProduct->image,
                //                 'qty' => $quantity,
                //                 'price' => $price,
                //                 'sub_total' => $total_amount,
                //                 'discount_percent' => $discount_percent,
                //                 'discount_amount' => $discount_amount,
                //                 'net_amount' => $net_amount,
                //                 'tax_amount' => $tax_amount,
                //                 'gross_amount' => $gross_amount,
                //                 'amount' => $gross_amount,
                //                 'options' => json_encode($options),
                //             ];
                //         }
                //     }
                //     // elseif(!empty($product['coupon']) && !is_null($exisProduct->coupon) && !empty($exisProduct->coupon) && isset($exisProduct->coupon) && isset($exisProduct->coupon[strtolower($request->input('couponCode'))]) && $exisProduct->coupon[strtolower($request->input('couponCode'))]['code'] == strtolower($request->input('couponCode'))) {
                //     //     $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                //     //     $total_amount = $price * $quantity;
                //     //     $discount_percent = $exisProduct->coupon[strtolower($request->input('couponCode'))]['value'];
                //     //     $discount_amount = ($total_amount / 100) * $discount_percent;
                //     //     $net_amount = $total_amount - $discount_amount;
                //     //     $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                //     //     $gross_amount = $net_amount + $tax_amount;
                //     //     $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                //     //     $orderProduct = [
                //     //         'invoice_id' => $invoice->id,
                //     //         'reference_type' => 'Botble\Ecommerce\Models\Product',
                //     //         'reference_id' => $exisProduct->id,
                //     //         'name' => $exisProduct->name,
                //     //         // 'description' => $exisProduct->description,
                //     //         'image' => $exisProduct->image,
                //     //         'qty' => $quantity,
                //     //         'price' => $price,
                //     //         'sub_total' => $total_amount,
                //     //         'discount_percent' => $discount_percent,
                //     //         'discount_amount' => $discount_amount,
                //     //         'net_amount' => $net_amount,
                //     //         'tax_amount' => $tax_amount,
                //     //         'gross_amount' => $gross_amount,
                //     //         'amount' => $gross_amount,
                //     //         'options' => json_encode($options),
                //     //     ];
                //     // }
                //     // elseif(isset($product['is_customer_coupon']) && !isset($product['is_gift']) && is_null($exisProduct->sale_price) && !is_null($exisProduct->customer_coupon) && !empty($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon) && isset($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]) && $exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['code'] == strtolower($request->input('couponCode'))) {
                //     elseif(isset($product['is_coupon']) && !isset($product['is_gift']) && is_null($exisProduct->sale_price)) {
                //         // if($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['coupon_type'] == 'percent') {
                //             // echo 'Customer Coupon';
                //             // echo '\n ';
                //             $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                //             $total_amount = $price * $quantity;
                //             $discount_percent = $product['value'];
                //             $discount_amount = ($total_amount / 100) * $discount_percent;
                //             $net_amount = $total_amount - $discount_amount;
                //             $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                //             $gross_amount = $net_amount + $tax_amount;
                //             $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                        
                //             $orderProduct = [
                //                 'invoice_id' => $invoice->id,
                //                 'reference_type' => 'Botble\Ecommerce\Models\Product',
                //                 'reference_id' => $exisProduct->id,
                //                 'name' => $exisProduct->name,
                //                 // 'description' => $exisProduct->description,
                //                 'image' => $exisProduct->image,
                //                 'qty' => $quantity,
                //                 'price' => $price,
                //                 'sub_total' => $total_amount,
                //                 'discount_percent' => $discount_percent,
                //                 'discount_amount' => $discount_amount,
                //                 'net_amount' => $net_amount,
                //                 'tax_amount' => $tax_amount,
                //                 'gross_amount' => $gross_amount,
                //                 'amount' => $gross_amount,
                //                 'options' => json_encode($options),
                //             ];
                //         // } elseif($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['coupon_type'] == 'amount') {
                //         //      // echo 'Customer Coupon Amount';
                //         //     // echo '\n ';
                //         //     $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                //         //     $total_amount = $price * $quantity;
                //         //     $sale_price = $price - ($exisProduct->customer_coupon[strtolower($request->input('couponCode'))]['value'] / (1 + ($request->input('vatTax') / 100)));
                //         //     $discount_percent = 0;
                //         //     $discount_amount = $total_amount - ($sale_price * $quantity);
                //         //     $net_amount = $total_amount - $discount_amount;
                //         //     $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                //         //     $gross_amount = $net_amount + $tax_amount;
                //         //     $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                        
                //         //     $orderProduct = [
                //         //         'invoice_id' => $invoice->id,
                //         //         'reference_type' => 'Botble\Ecommerce\Models\Product',
                //         //         'reference_id' => $exisProduct->id,
                //         //         'name' => $exisProduct->name,
                //         //         // 'description' => $exisProduct->description,
                //         //         'image' => $exisProduct->image,
                //         //         'qty' => $quantity,
                //         //         'price' => $price,
                //         //         'sub_total' => $total_amount,
                //         //         'discount_percent' => $discount_percent,
                //         //         'discount_amount' => $discount_amount,
                //         //         'net_amount' => $net_amount,
                //         //         'tax_amount' => $tax_amount,
                //         //         'gross_amount' => $gross_amount,
                //         //         'amount' => $gross_amount,
                //         //         'options' => json_encode($options),
                //         //     ];
                //         // }
                //     }
                //     // elseif(!is_null($exisProduct->sale_price)) {
                //     //     $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                //     //     $total_amount = $price * $quantity;
                //     //     $sale_price = $exisProduct->sale_price / (1 + ($request->input('vatTax') / 100));
                //     //     $discount_percent = 0;
                //     //     $discount_amount = $total_amount - ($sale_price * $quantity);
                //     //     $net_amount = $total_amount - $discount_amount;
                //     //     $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                //     //     $gross_amount = $net_amount + $tax_amount;
                //     //     $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                //     //     $orderProduct = [
                //     //          'invoice_id' => $invoice->id,
                //     //         'reference_type' => 'Botble\Ecommerce\Models\Product',
                //     //         'reference_id' => $exisProduct->id,
                //     //         'name' => $exisProduct->name,
                //     //         'description' => $exisProduct->description,
                //     //         'image' => $exisProduct->image,
                //     //         'qty' => $quantity,
                //     //         'price' => $price,
                //     //         'sub_total' => $total_amount,
                //     //         'discount_percent' => $discount_percent,
                //     //         'discount_amount' => $discount_amount,
                //     //         'net_amount' => $net_amount,
                //     //         'tax_amount' => $tax_amount,
                //     //         'gross_amount' => $gross_amount,
                //     //         'amount' => $gross_amount,
                //     //         'options' => json_encode($options),
                //     //     ];
                //     // }
                //     elseif(isset($product['is_gift']) && $product['is_gift'] == true) {
                //         $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                //         $total_amount = 0.00;
                //         $discount_percent = 100;
                //         $discount_amount = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                //         $net_amount = 0.00;
                //         $tax_amount = 0.00;
                //         $gross_amount = 0.00;
                //         $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                //         $orderProduct = [
                //             'invoice_id' => $invoice->id,
                //             'reference_type' => 'Botble\Ecommerce\Models\Product',
                //             'reference_id' => $exisProduct->id,
                //             'name' => $exisProduct->name,
                //             // 'description' => $exisProduct->description,
                //             'image' => $exisProduct->image,
                //             'qty' => $quantity,
                //             'price' => $price,
                //             'sub_total' => $total_amount,
                //             'discount_percent' => $discount_percent,
                //             'discount_amount' => $discount_amount,
                //             'net_amount' => $net_amount,
                //             'tax_amount' => $tax_amount,
                //             'gross_amount' => $gross_amount,
                //             'amount' => $gross_amount,
                //             'options' => json_encode($options)
                //         ];
                //     }
                //     else {
                //         $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                //         $total_amount = $price * $quantity;
                //         $discount_percent = 0;
                //         $discount_amount = 0.00;
                //         $net_amount = $total_amount - $discount_amount;
                //         $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                //         $gross_amount = $net_amount + $tax_amount;
                //         $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                //         $orderProduct = [
                //             'invoice_id' => $invoice->id,
                //             'reference_type' => 'Botble\Ecommerce\Models\Product',
                //             'reference_id' => $exisProduct->id,
                //             'name' => $exisProduct->name,
                //             // 'description' => $exisProduct->description,
                //             'image' => $exisProduct->image,
                //             'qty' => $quantity,
                //             'price' => $price,
                //             'sub_total' => $total_amount,
                //             'discount_percent' => $discount_percent,
                //             'discount_amount' => $discount_amount,
                //             'net_amount' => $net_amount,
                //             'tax_amount' => $tax_amount,
                //             'gross_amount' => $gross_amount,
                //             'amount' => $gross_amount,
                //             'options' => json_encode($options),
                //         ];
                //     }

                //     InvoiceItem::query()->create($orderProduct);
                // }

                if (!empty($decode) && !empty($decode->data) && is_array($decode->data)) {
                                      
                    // Get the first coupon object from the 'data' array
                    $couponObject = $decode->data[0];

                    // Convert the coupon object to an associative array
                    $couponData = (array) $couponObject;

                    // Add the order's ID to the data
                    // Assumes 'order_number' column stores the $order->id.
                    $couponData['order_id'] = $order->id;

                    // **IMPORTANT**: Your schema for 'column1' is NOT NULL
                    // but the JSON does not provide it. We must set a default.
                    if (!isset($couponData['column1'])) {
                        $couponData['column1'] = ''; // Use an empty string as default
                    }

                    // Create the new record in the 'active_coupon' table
                    ActiveCoupon::create($couponData);
                } else {
                }

                if($request->input('payment_method') == 'paytabs') {
                    $resp = $this->payTabsPayment($request, $data, $order);
                    // echo "<pre>";print_r($resp);die();
                    if($resp['redirect_url']) {
                        return response()->json([
                            'message'          => 'Redirecting to Paytabs...',
                            'order_id'         => $order->code,
                            'payment_method'   => $request->input('payment_method'),
                            'total'            => $order->amount,
                            'sub_total'        => $order->sub_total,
                            'shipping_amount'  => $order->shipping_amount,
                            // 'products'         => $prod,
                            'redirect_url'     => $resp['redirect_url']
                        ]);
                    }
                }

                if($request->input('payment_method') == 'tamara') {
                    $resp = $this->tamaraPayment($request, $data, $order, $prod);

                    if($resp['checkout_url']) {
                        return response()->json([
                            'message'          => 'Redirecting to Tamara...',
                            'order_id'         => $order->code,
                            'payment_method'   => $request->input('payment_method'),
                            'total'            => $order->amount,
                            'sub_total'        => $order->sub_total,
                            'shipping_amount'  => $order->shipping_amount,
                            // 'products'         => $prod,
                            'redirect_url'     => $resp['checkout_url']
                        ]);
                    }
                }

                if ($request->input('payment_method') == 'tabby') {
                    $useShippingAddress = $request->input('shippingAddress.first_name');
                    $addressPrefix = $useShippingAddress ? 'shippingAddress' : 'billingAddress';
                    $shippingData = [
                        "city"    => $request->input("$addressPrefix.emirates"),
                        "address" => $request->input("$addressPrefix.area") . ' ' . $request->input("$addressPrefix.building"),
                        "zip"     => "00000"
                    ];
                    $customer = Customer::find($customer_id);
                    if ($customer) {
                        $orderHistory = Order::where('user_id', $customer_id)->latest()->take(10)->get();
                        $shippingData['buyer_history'] = [
                            'registered_since' => Carbon::parse($customer->created_at)->utc()->toIso8601String(),
                            'loyalty_level'    => $orderHistory->count(),
                            'order_history'    => $orderHistory,
                        ];
                    } else {
                        $shippingData['buyer_history'] = [
                            'registered_since' => Carbon::now()->utc()->toIso8601String(),
                            'loyalty_level'    => 0,
                            'order_history'    => [],
                        ];
                    }
                    $resp = $this->tabbyPayment($request, $shippingData, $order, $prod);
                    if (isset($resp['status']) && ($resp['status'] == 'created' || $resp['status'] == 'CREATED')) {
                        $redirectUrl = $resp['configuration']['available_products']['installments'][0]['web_url'] 
                                    ?? $resp['configuration']['available_products']['installments'][0]['url'] 
                                    ?? null;

                        if ($redirectUrl) {
                            return response()->json([
                                'message'          => 'Redirecting to Tabby...',
                                'order_id'         => $order->code,
                                'payment_method'   => 'tabby',
                                'total'            => $order->amount,
                                'redirect_url'     => $redirectUrl,
                            ]);
                        }
                    }
                    // $order->status = OrderStatusEnum::CANCELED; //ask yazil bhai if changing status to canceled will clear up the stock too?
                    // $order->save();
                    $locale = $request->input('locale', 'en');
                    $rejectionReason = $resp['configuration']['products']['installments']['rejection_reason'] 
                                    ?? $resp['rejection_reason_code'] 
                                    ?? 'not_available';
                    $errorMessage = "";
                    switch ($rejectionReason) {
                        case 'order_amount_too_high':
                        case 'not_enough_limit':
                            $errorMessage = ($locale == 'ar') 
                                ? "قيمة الطلب تفوق الحد الأقصى المسموح به حاليًا مع تابي. يُرجى تخفيض قيمة السلة أو استخدام وسيلة دفع أخرى."
                                : "This purchase is above your current spending limit with Tabby, try a smaller cart or use another payment method.";
                            break;
                        case 'order_amount_too_low':
                            $errorMessage = ($locale == 'ar') 
                                ? "قيمة الطلب أقل من الحد الأدنى المطلوب لاستخدام خدمة تابي. يُرجى زيادة قيمة الطلب أو استخدام وسيلة دفع أخرى."
                                : "The purchase amount is below the minimum amount required to use Tabby, try adding more items or use another payment method.";
                            break;
                        default:
                            $errorMessage = ($locale == 'ar') 
                                ? "نأسف، تابي غير قادرة على الموافقة على هذه العملية. الرجاء استخدام طريقة دفع أخرى."
                                : "Sorry, Tabby is unable to approve this purchase. Please use an alternative payment method for your order.";
                            break;
                    }

                    $createPaymentForOrderService->execute(
                        $order,
                        'tabby',
                        'failed',
                        $order->user_id,
                        null,
                        "Tabby payment failed: " . $rejectionReason,
                    );

                    return response()->json([
                        'message' => $errorMessage,
                        'error'   => $resp['message'] ?? $errorMessage
                    ], 422);

                }

                // $request['payment_status'] = 'completed';
                Log::info('OrderController: Calling CreatePaymentForOrderService->execute().', ['order_id' => $order->id ?? null, 'payment_method' => $request->input('payment_method')]);
                $createPaymentForOrderService->execute(
                    $order,
                    $request->input('payment_method'),
                    'completed',
                    $customer_id
                );

                $filteredProducts = array_map(function ($item) {

                    $product = [
                        'id' => $item['id'],
                        'product_id' => $item['id'],
                        'name' => $item['name'],
                        'price' => $item['price'],
                        'qty' => $item['qty'],
                        'discount' => $item['discount'],
                        'coupon' => $item['coupon'],
                    ];

                    // Add only if exists
                    if (isset($item['is_gift'])) {
                        $product['is_gift'] = $item['is_gift'];
                    }

                    if (isset($item['is_coupon'])) {
                        $product['is_coupon'] = $item['is_coupon'];
                    }

                    return $product;

                }, $prod);

                Log::info('OrderController: storeOrder() finished successfully.', ['order_id' => $order->id ?? null]);
                return response()->json([
                    'message'          => 'Order created successfully',
                    'order_id'         => $order->code,
                    'id'                => $order->id,
                    'customer_name' => $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                    'payment_method'   => $request->input('payment_method'),
                    'total'            => $order->amount,
                    'sub_total'        => $order->sub_total,
                    'shipping_amount'  => $order->shipping_amount,
                    'products'         => $filteredProducts
                ]);
            }
        });
    }

    public function tabbyPayment(Request $request, array $shippingData, Order $order, $prods) {
        $phoneNumber = ltrim($request->input('billingAddress.mobile'), '0');
        $buyerPhone = '+971' . $phoneNumber;
        $referenceId = $order->code;
        // $buyerInfo = [
        //     "phone" => $buyerPhone,
        //     "email" => $request->input('billingAddress.email'),
        //     "name"  => $request->input('billingAddress.first_name') . ' ' . $request->input('billingAddress.last_name')
        // ];

        // $shippingAddress = [
        //     "city"    => $shippingData['city'],
        //     "address" => $shippingData['street1'],
        //     "zip"     => "00000"
        // ];
        $requestParams = [
            "payment" => [
                "amount" => number_format((float)$order->amount, 2, '.', ''),
                "currency" => "AED",
                "buyer" => [
                    "phone" => $buyerPhone,
                    "email" => $request->input('billingAddress.email'),
                    "name" => $request->input('billingAddress.first_name') . ' ' . $request->input('billingAddress.last_name')
                ],
                "shipping_address" => [
                    "city" => $shippingData['city'],
                    "address" => $shippingData['address'],
                    "zip" => "00000"
                ],
                "order" => [
                    "tax_amount" => "0.00",
                    "shipping_amount" => number_format((float)$order->shipping_amount, 2, '.', ''),
                    "discount_amount" => number_format((float)$order->discount_amount, 2, '.', ''),
                    "reference_id" => (string)$referenceId, 
                    "items" => []
                ],
                "buyer_history" => [
                    "registered_since" => $shippingData['buyer_history']['registered_since'],
                    "loyalty_level" => $shippingData['buyer_history']['loyalty_level'],
                ],
                "order_history" => [],
            ],
            "lang" => $request->input('locale', 'en'),
            "merchant_code" => env('TABBY_MERCHANT_CODE'),
            "merchant_urls" => [
                "success" => route('payment.tabby.redirect', ['order_number' => base64_encode($order->code), 'payment_status' => 'success']),
                "cancel"  => route('payment.tabby.redirect', ['order_number' => base64_encode($order->code), 'payment_status' => 'cancel']),
                "failure" => route('payment.tabby.redirect', ['order_number' => base64_encode($order->code), 'payment_status' => 'failure']),
            ]
        ];
        foreach ($prods as $item) {
            $requestParams['payment']['order']['items'][] = [
                "title" => $item['name'],
                "quantity" => $item['qty'],
                "unit_price" => number_format((float)$item['price'], 2, '.', ''),
                "reference_id" => (string)$item['id'],
                "category" => isset($item['category_name']) ? $item['category_name'] : null  // Adjust as needed
            ];
        }
        if (!empty($shippingData['buyer_history']['order_history'])) {
            foreach ($shippingData['buyer_history']['order_history'] as $histOrder) {
                $tabbyStatus = match(strtolower($histOrder->status)) {
                    'pending', 'draft' => 'new',
                    'processing', 'on_hold', 'confirmed' => 'processing',
                    'shipped', 'completed', 'delivered' => 'complete',
                    'cancelled', 'canceled' => 'canceled',
                    'returned', 'refunded' => 'refunded',
                    default => 'unknown',
                };

                $requestParams['payment']['order_history'][] = [
                    "purchased_at" => Carbon::parse($histOrder->created_at)->utc()->toIso8601String(),
                    "amount" => number_format((float)$histOrder->amount, 2, '.', ''),
                    "status" => $tabbyStatus,
                    "buyer" => $requestParams['payment']['buyer'], // Assuming same buyer
                    "shipping_address" => $requestParams['payment']['shipping_address'],
                ];
            }
        }
        Log::info('Tabby Payload: ', $requestParams);
        $PROFILE_ID = env('TABBY_PROFILE_ID');
        $PUBLIC_KEY = env('TABBY_PUBLIC_KEY');
        $SECRET_KEY = env('TABBY_SECRET_KEY');
        $BASE_URL = env('TABBY_BASE_URL');

        $data['profile_id'] = $PROFILE_ID;

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $BASE_URL.'checkout',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($requestParams),
            CURLOPT_HTTPHEADER => array(
                'authorization: Bearer ' . $SECRET_KEY,
                'Content-Type:application/json'
            ),
        ));
        $responseString = curl_exec($curl);
        if (curl_errno($curl)) {
            $curlError = curl_error($curl);
            curl_close($curl);
            // Log the actual cURL error
            Log::error('Tabby cURL Error:', ['error' => $curlError]);
            // Return an error structure so the 'else' block in initiatePayment works
            return ['status' => 'curl_error', 'message' => $curlError];
        }
        curl_close($curl);
        $responseArray = json_decode($responseString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Tabby JSON Decode Error:', ['response_string' => $responseString]);
            return ['status' => 'json_error', 'message' => 'Failed to decode JSON response from Tabby.'];
        }
        Log::info('Tabby Checkout Response:', $responseArray);
        
        return $responseArray;
    }

    public function tabbyPaymentRedirect(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {
        $payment_id = $request->input('payment_id');
        $order_id = $request->query('order_number');
        $status = $request->query('payment_status');
        $order = Order::where('code', base64_decode($request->query('order_number')))->orderBy('id', 'desc')->first();

        if (!$order) {
            $failUrl = env('FRONTEND_URL', 'https://ae.ahmedalmaghribi.com') . '/order-failure?reason=order_not_found';
            return redirect($failUrl);
        }

        $locale = $order->lang ?? 'en';

        $alreadyPaid = Payment::where('order_id', $order->id)->where('charge_id', $payment_id)
        ->where('status', 'completed')
        ->exists();
        if ($alreadyPaid) {
            Log::info("Tabby: Order {$order->code} already has a completed payment. Skipping service.");
            $redirectUrl = env('FRONTEND_URL', 'https://ae.ahmedalmaghribi.com') . '/'.$order->lang.'/shop-order-payment-complete?q=' . base64_encode($order->code);
            return redirect($redirectUrl);
        }

        if ($status === 'cancel') {
            // $order->status = OrderStatusEnum::CANCELED;
            // $order->save();
            // You might need to increment stock back here since storeOrder decremented it

            $message = ($locale == 'ar') 
                ? "لقد ألغيت الدفعة. فضلاً حاول مجددًا أو اختر طريقة دفع أخرى." 
                : "You aborted the payment. Please retry or choose another payment method.";
            
            $cancelUrl = env('FRONTEND_URL', 'https://ae.ahmedalmaghribi.com') . '/shop-checkout?error=' . urlencode($message);
            $createPaymentForOrderService->execute(
                $order,
                'tabby',
                $status,
                $order->user_id,
                $request->input('payment_id'),
                "User canceled the payment process.",
            );
            return redirect($cancelUrl);
        }
        if ($status === 'failure') {
            // $order->status = OrderStatusEnum::CANCELED;
            // $order->save();

            $message = ($locale == 'ar') 
                ? "نأسف، تابي غير قادرة على الموافقة على هذه العملية. الرجاء استخدام طريقة دفع أخرى." 
                : "Sorry, Tabby is unable to approve this purchase. Please use an alternative payment method for your order.";
            
            $cancelUrl = env('FRONTEND_URL', 'https://ae.ahmedalmaghribi.com') . '/shop-checkout?error=' . urlencode($message);
            $createPaymentForOrderService->execute(
                $order,
                'tabby',
                $status,
                $order->user_id,
                $request->input('payment_id'),
                "Tabby payment failed.",
            );
            return redirect($cancelUrl);
        }

        $SECRET_KEY = env('TABBY_SECRET_KEY');
        $BASE_URL = env('TABBY_BASE_URL');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $BASE_URL . 'payments/' . $payment_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification (useful for testing)
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['authorization: Bearer ' . $SECRET_KEY]);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['status']) && ($response['status'] == 'AUTHORIZED' || $response['status'] == 'authorized')) {

            $c = curl_init();
            curl_setopt_array($c, array(
                CURLOPT_URL => $BASE_URL . 'payments/' . $response["id"] . '/captures',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode(["amount" => $response["amount"]]),
                CURLOPT_HTTPHEADER => [
                    'authorization: Bearer ' . $SECRET_KEY,
                    'Content-Type:application/json'
                ],
            ));
    
            $captureResponse = json_decode(curl_exec($c), true);
            curl_close($c);
            // echo "<pre>";print_r($resp);die;
            Log::info('Tabby Capture Response:', $captureResponse);
            
            if (isset($captureResponse['status']) && ($captureResponse['status'] == 'CLOSED' || $captureResponse['status'] == 'closed')) {
                // Payment Successful
                $createPaymentForOrderService->execute(
                    $order,
                    'tabby',
                    $captureResponse['status'],
                    $order->user_id,
                    $captureResponse['id'],
                    (isset($captureResponse['description']) && !empty($captureResponse['description'])) ? $captureResponse['description'] : $captureResponse['status'],
                );

                // Redirect to Success
                $redirectUrl = env('FRONTEND_URL', 'https://ae.ahmedalmaghribi.com') . '/'.$order->lang.'/shop-order-payment-complete?q=' . base64_encode($order->code);
                return redirect($redirectUrl);

            } else {
                // Capture Failed
                // $order->status = OrderStatusEnum::CANCELED;
                // $order->save();
                Log::error('Tabby Capture Failed', ['response' => $captureResponse]);
                
                $failUrl = env('FRONTEND_URL', 'https://ae.ahmedalmaghribi.com') . '/order-failure?reason=capture_failed';
                return redirect($failUrl);
            }
        } else {
            $createPaymentForOrderService->execute(
                $order,
                'tabby',
                $response['status'],
                $order->user_id,
                $request->input('payment_id'),
                (isset($response['description']) && !empty($response['description'])) ? $response['description'] : $response['status'],
            );
        }

        header('Location: http://localhost:3000/'.$order->lang.'/shop-order-payment-complete?q='.base64_encode($order->code));exit();
    }

    public function payTabsPayment(Request $request, $shippingData, $order) {
        $paymentStr = '';
        foreach ($request->input('products') as $product) {
            $quantity = $product['quantity'] ? $product['quantity'] : 1;
            $exisProduct = Product::select('name')->where('ec_products.id', $product['product_id'])->first();
            $paymentStr .= $exisProduct->name. ' ('.$quantity.'), ';
        }

        $encodedOrderNumber = base64_encode($order->code);
        $returnUrl = "https://admin.ahmedalmaghribi.com/public/api/payTabsPaymentRedirect?order_number=" . $encodedOrderNumber;
        $callbackUrl = "https://admin.ahmedalmaghribi.com/public/api/payTabsCallback?order_number=" . $encodedOrderNumber;

        $data = [
            "tran_type"=> "sale",
            "tran_class"=> "ecom",
            "cart_id"=> explode('#', $order->code)[1],
            "cart_currency"=> "AED",
            "cart_amount"=> $order->amount,
            "cart_description"=> $paymentStr,
            "paypage_lang"=> "en",
            "customer_details"=> [
                "name"=> $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                "email"=> $request->input('billingAddress.email'),
                "phone"=> $request->input('billingAddress.mobile'),
                "street1"=> $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                "city"=> $request->input('billingAddress.emirates'),
                "state"=> $request->input('billingAddress.emirates'),
                "country"=> "AE",
                // "zip"=> "12345"
            ],
            "shipping_details"=> [
                "name"=> $shippingData['name'],
                "email"=> $shippingData['email'],
                "phone"=> $shippingData['phone'],
                "street1"=> $shippingData['street1'],
                "city"=> $shippingData['city'],
                "state"=> $shippingData['state'],
                "country"=> "AE",
                // "zip"=> "54321"
            ],
            "return" => $returnUrl,   
            "callback" => $callbackUrl
        ];

        \Log::info('Paytabs API Payload:', ['request' => $data]);

        $PROFILE_ID = env('PAYTABS_PROFILE_ID');
        $SERVER_KEY = env('PAYTABS_SERVER_KEY');

        $BASE_URL = 'https://secure.paytabs.com/payment/request';

        $data['profile_id'] = $PROFILE_ID;
        // echo json_encode($data);die();
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $BASE_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($data, true),
            CURLOPT_HTTPHEADER => array(
                'authorization:' . $SERVER_KEY,
                'Content-Type:application/json'
            ),
            // CURLOPT_SSL_VERIFYPEER => false,  // 👈 Add this
            // CURLOPT_SSL_VERIFYHOST => false,  // 👈 And this
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => base_path('certs/cacert.pem'),
        ));

        // $response = json_decode(curl_exec($curl), true);
        // curl_close($curl);
        // print_r($response);die;
        // return $response;

        $response = curl_exec($curl);
        // curl_close($curl);
        if (curl_errno($curl)) {
            // echo 'order_approved Curl error: ' . curl_error($ch);
            \Log::info('Paytabs API Error:', ['error' => curl_error($curl)]);
        }

        curl_close($curl);

        $resp = json_decode($response, true);

        // echo "<pre>";print_r($resp);exit;

        \Log::info('Paytabs API Response:', ['response' => $resp]);

        return $resp;

        // echo "Raw response:\n";
        // var_dump($responseRaw); // Check if there is anything returned at all
        // $response = json_decode($responseRaw, true);
        // print_r($response); // Still might be null if response is not valid JSON
        // die;

        // $responseRaw = curl_exec($curl);

        // if (curl_errno($curl)) {
        //     echo 'Curl error: ' . curl_error($curl) . "\n";
        // }

        // $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        // echo "HTTP Status Code: $httpCode\n";

        // curl_close($curl);

        // die;
    }

    public function payTabsCallback(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {
        \Log::info('Paytabs Callback Hit: ', ['payload' => $request->all()]);

        // 1. Identify the Order and Transaction Reference
        $orderCodeRaw = $request->query('order_number') ?? $request->input('order_number');
        $tranRef = $request->input('tran_ref');

        if (!$orderCodeRaw || !$tranRef) {
            \Log::warning('PayTabs Callback Rejected: Missing order_number or tran_ref', [
                'order_number' => $orderCodeRaw,
                'tran_ref'     => $tranRef
            ]);
            return response()->json(['message' => 'Invalid callback payload: missing order_number or tran_ref'], 400);
        }

        $orderCode = base64_decode($orderCodeRaw);
        $order = Order::where('code', $orderCode)->orderBy('id', 'desc')->first();

        if (!$order) {
            \Log::error('Paytabs Callback: Order not found', ['code' => $orderCode]);
            return response()->json(['message' => 'Order not found'], 404);
        }

        // 2. Idempotency Check (Prevent duplicate executions/notifications)
        $alreadyProcessed = Payment::where('charge_id', $tranRef)
            ->where('status', 'completed')
            ->exists();

        if ($alreadyProcessed) {
            \Log::info("PayTabs Callback: Transaction {$tranRef} for Order {$order->code} already processed. Skipping.");
            return response()->json(['message' => 'Already processed'], 200);
        }

        // 3. ZERO-TRUST STEP: Direct Server-to-Server Inquiry to PayTabs Query API
        $PROFILE_ID = env('PAYTABS_PROFILE_ID');
        $SERVER_KEY = env('PAYTABS_SERVER_KEY');
        $QUERY_URL  = 'https://secure.paytabs.com/payment/query';

        $queryPayload = [
            'profile_id' => (int) $PROFILE_ID,
            'tran_ref'   => $tranRef,
        ];

        $curlOptions = [
            CURLOPT_URL            => $QUERY_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($queryPayload),
            CURLOPT_HTTPHEADER     => [
                'authorization:' . $SERVER_KEY,
                'Content-Type:application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if (file_exists(base_path('certs/cacert.pem'))) {
            $curlOptions[CURLOPT_CAINFO] = base_path('certs/cacert.pem');
        }

        $curl = curl_init();
        curl_setopt_array($curl, $curlOptions);
        $rawResponse = curl_exec($curl);
        $curlError   = curl_error($curl);
        $httpCode    = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($curlError) {
            \Log::error('PayTabs Verification cURL Error: ' . $curlError);
            return response()->json(['message' => 'Failed to reach PayTabs verification gateway'], 500);
        }

        $verifiedData = json_decode($rawResponse, true);
        \Log::info("PayTabs Verification Response for Order {$order->code}:", [
            'http_code' => $httpCode,
            'response'  => $verifiedData
        ]);

        if (empty($verifiedData) || empty($verifiedData['payment_result'])) {
            \Log::error('PayTabs Verification Failed: Invalid response from PayTabs Query API', [
                'raw' => $rawResponse
            ]);
            return response()->json(['message' => 'Payment verification inquiry failed'], 400);
        }

        // 4. Cross-verify PayTabs Official Response with Database Records
        $verifiedStatus  = $verifiedData['payment_result']['response_status'] ?? null;
        $verifiedMessage = $verifiedData['payment_result']['response_message'] ?? 'Verified via PayTabs Query API';
        $verifiedAmount  = (float) ($verifiedData['cart_amount'] ?? 0);
        $verifiedCartId  = (string) ($verifiedData['cart_id'] ?? '');

        // Match Cart ID (Order Code)
        $cleanOrderCode = str_replace('#', '', $order->code);
        $expectedCartId = explode('#', $order->code)[1] ?? $order->code;

        if ($verifiedCartId !== $cleanOrderCode && $verifiedCartId !== $order->code && $verifiedCartId !== $expectedCartId) {
            \Log::error('PayTabs Verification Mismatch: Cart ID mismatch', [
                'expected' => $order->code,
                'received' => $verifiedCartId
            ]);
            return response()->json(['message' => 'Verification failed: Cart ID mismatch'], 400);
        }

        // Match Amount (Prevent Underpayment attacks)
        if (abs($verifiedAmount - (float) $order->amount) > 0.01) {
            \Log::error('PayTabs Verification Mismatch: Amount mismatch', [
                'expected' => (float) $order->amount,
                'received' => $verifiedAmount
            ]);
            return response()->json(['message' => 'Verification failed: Amount mismatch'], 400);
        }

        // 5. Execute Order Payment Processing with Official Verified Status
        try {
            $createPaymentForOrderService->execute(
                $order,
                'paytabs',
                $verifiedStatus,
                $order->user_id,
                $tranRef,
                $verifiedMessage
            );
            \Log::info("Paytabs Callback Success: Order {$order->code} processed with verified status: {$verifiedStatus}");
        } catch (\Exception $e) {
            \Log::error("Paytabs Callback Execution Error: " . $e->getMessage());
            return response()->json(['message' => 'Error updating order'], 500);
        }

        // 6. Return 200 OK to PayTabs
        return response()->json(['message' => 'Callback verified and processed successfully']);
    }

    public function payTabsPaymentRedirect(Request $request) {
        \Log::info('Paytabs Return URL Hit');

        $orderCode = base64_decode($request->query('order_number'));
                
        header('Location: https://ae.ahmedalmaghribi.com/en/shop-order-payment-complete?q='.base64_encode($orderCode));
        exit();
    }

    // public function payTabsPaymentRedirect(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {
    //     \Log::info('Paytabs API Redirect Response:', ['response' => $request->all()]);
    //     // echo "<pre>";print_r($request->all());die;
    //     // $customer = Customer::where('email', $request->input('customerEmail'))->first();
    //     // $order = Order::where('user_id', $customer->id)->orderBy('id', 'desc')->first();
    //     $order = Order::where('code', base64_decode($request->query('order_number')))->orderBy('id', 'desc')->first();
    //     // echo "<pre>";print_r($order);
    //     $createPaymentForOrderService->execute(
    //         $order,
    //         'paytabs',
    //         $request['respStatus'],
    //         // $customer->id,
    //         $order->user_id,
    //         $request->input('tranRef'),
    //         $request['respMessage'],
    //     );

    //     header('Location: https://ae.ahmedalmaghribi.com/'.$order->lang.'/shop-order-payment-complete?q='.base64_encode($order->code));exit();
    // }

    public function trackOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_number'      => 'required',
            'billing_email'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $order = Order::select('ec_orders.id', 'ec_orders.code', 'ec_orders.status', 'ec_orders.deliveryStatus', 'ec_orders.amount', 'ec_orders.sub_total', 'ec_orders.shipping_amount', 'payments.payment_channel', 'ec_orders.created_at', 'ec_orders.service_amount', 'ec_orders.vat', 'ec_orders.tax_amount', 'payments.status AS payment_status', 'ec_orders.cod_charge', 'ec_order_addresses.awb')->join('ec_order_addresses', 'ec_order_addresses.order_id', 'ec_orders.id')->join('payments', 'payments.order_id', 'ec_orders.id')->where('ec_orders.code', $request->input('order_number'))->where('ec_order_addresses.email', $request->input('billing_email'))->first();

        if(!$order) {
            return response()->json(['message' => 'Order not found']);
        }

        $prod = OrderProduct::select('id', 'id as product_id', 'product_name', 'qty', 'price', 'order_id', 'is_gift', 'discount_percent', 'discount_amount', 'gross_amount', 'vat')->where('ec_order_product.order_id', $order->id)->get();

        $smsaTrackingDetails = null;
        if (!empty($order->awb)) {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://ecomapis.smsaexpress.com/api/track/single/' . $order->awb,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array(
                    'apikey: 3af56f2bd2304769814715a9ed9645fd',
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);
            
            if (!curl_errno($curl)) {
                $smsaTrackingDetails = json_decode($response);
            }
            
            curl_close($curl);
        }

        return response()->json([
            'message'          => 'Tracking Details Fetched successfully',
            'order_id'         => $order->code,
            'payment_method'   => $order->payment_channel,
            'total'            => $order->amount,
            'sub_total'        => $order->sub_total,
            'shipping_amount'  => $order->shipping_amount,
            'status'           => $order->status,
            'delivery_status'  => $order->deliveryStatus,
            'created_at'       => $order->created_at,
            'service_amount'   => $order->service_amount,
            'vat_amount'       => $order->vat,
            'tax_amount'       => $order->tax_amount,
            'payment_status'   => $order->payment_status,
            'products'         => $prod,
            'cod_charge'   => $order->cod_charge,
            'awb'              => $order->awb,
            'shipping_details' => $smsaTrackingDetails
        ]);
    }

    public function orderDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_number'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $order = Order::select('ec_orders.id', 'ec_orders.code', 'ec_orders.status', 'ec_orders.amount', 'ec_orders.sub_total', 'ec_orders.shipping_amount', 'payments.payment_channel', 'ec_orders.created_at', 'ec_orders.service_amount', 'ec_orders.vat', 'ec_orders.tax_amount', 'payments.status AS payment_status', 'ec_orders.cod_charge', 'ec_order_addresses.name')->join('ec_order_addresses', 'ec_order_addresses.order_id', 'ec_orders.id', 'left')->join('payments', 'payments.order_id', 'ec_orders.id', 'left')->where('ec_orders.code', $request->input('order_number'))->first();

        if(!$order) {
            return response()->json(['message' => 'Order not found']);
        }

        $prod = OrderProduct::select('id', 'id as product_id', 'product_name', 'qty', 'price', 'order_id', 'is_gift', 'discount_percent', 'discount_amount', 'gross_amount', 'vat')->where('ec_order_product.order_id', $order->id)->get();

        return response()->json([
            'message'          => 'Details Fetched successfully',
            'order_id'         => $order->code,
            'payment_method'   => $order->payment_channel,
            'total'            => $order->amount,
            'sub_total'        => $order->sub_total,
            'shipping_amount'  => $order->shipping_amount,
            'status'           => $order->status,
            'created_at'       => $order->created_at,
            'service_amount'   => $order->service_amount,
            'vat_amount'       => $order->vat,
            'tax_amount'       => $order->tax_amount,
            'payment_status'   => $order->payment_status,
            'id'                =>   $order->id,
            'customer_name'=> $order->name,
            'products'         => $prod,
            'cod_charge'   => $order->cod_charge
        ]);
    }

    public function validateCoupon(Request $request) {
         $validator = Validator::make($request->all(), [
            'couponCode'      => 'required',
            'mobile_number' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $coupon = Promotion::select('promotions.id', 'type', 'start_date', 'end_date', 'coupon_code AS code', 'percentage', 'amount', 'apply_to', 'apply_to AS type', 'coupon_type')->where('type', 'coupon')->where('coupon_code', $request->input('couponCode'))->where('start_date', '<=', now())->where('end_date', '>=', now())->join('coupon_rules', 'promotions.id', 'coupon_rules.promotion_id', 'left')->first();

        if(!$coupon) {
            return response()->json(['message' => 'Invalid Coupon Code']);
        }

        $cust_mobile_verification = Customer::where('phone', $request->input('mobile_number'))->first();

        if(!$cust_mobile_verification) {
             $mobile_verification = MobileVerification::where('phone', $request->input('mobile_number'))->first();

            if(!$mobile_verification) {
                return response()->json(['message' => 'Verify Mobile Number First']);
            }
        }

        $customer = OrderAddress::join('payments', 'payments.order_id', '=', 'ec_order_addresses.order_id')->where('status', 'completed')->where('phone', $request->input('mobile_number'))->orderBy('ec_order_addresses.order_id', 'desc')->first();

        // $customer = OrderAddress::select('order_id')->where('phone', $request->input('mobile_number'))->orderBy('order_id', 'desc')->first();

        // $payment = Payment::where('status', 'completed')->where('customer_id', $customer->order_id)->get();

        // echo "<pre>";print_r($customer);die;

        if($customer) {
            if(strtolower($request->input('couponCode')) == 'welcome10') {
                return response()->json(['message' => 'You Have Already Used this Coupon Code']);
            }
            $customer_discount = DB::table('ec_customer_used_coupons')->where('customer_id', $customer->customer_id)->where('discount_id', $coupon->id)->first();
            if($customer_discount) {
                return response()->json(['message' => 'You Have Already Used this Coupon Code']);
            }
        }

        $coupon->value = !is_null($coupon->percentage) && $coupon->coupon_type == 'percent' ? intval($coupon->percentage) : intval($coupon->amount);

        return response()->json([
            'message'          => 'Details Fetched successfully',
            'coupon'            => $coupon
        ]);
    }

    public function customerDetails(Request $request)
    {
        $customer = Auth::guard('api')->user();
        if (!$customer) {
            return response()->json(['message' => 'Unauthorizedsss'], 401);
        }

        $validator = Validator::make($request->all(), [
            'customer_id'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $customer = Customer::select('id', 'name', 'email', 'phone')->where('id', $request->input('customer_id'))->first();

        if(!$customer) {
            return response()->json(['message' => 'Customer Not Found']);
        }

        return response()->json([
            'message' => 'Details Fetched successfully',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_mobile' => $customer->phone
        ]);
    }

    public function customerUpdate(Request $request)
    {
        if($request->flag == 'fpassword') {
            $validator = Validator::make($request->all(), [
            'customer_id'      => 'required',
            'customer_password' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors());
            }

            $customer = Customer::find($request->input('customer_id'));

            if (!$customer) {
                return response()->json(['message' => 'Customer Not Found']);
            }

            $customer->password = Hash::make($request->input('customer_password'));
            $customer->save();

            return response()->json([
                'message' => 'Password Updated Successfully',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_mobile' => $customer->phone
            ]);
        } else {
            $customer = Auth::guard('api')->user();
            if (!$customer) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
            $validator = Validator::make($request->all(), [
                'customer_id'      => 'required',
                'customer_name' => 'required',
                 'customer_email' => 'required|email|unique:ec_customers,email,' . $request->input('customer_id'),
                'customer_mobile' => 'required|unique:ec_customers,phone,' . $request->input('customer_id'),
                // 'customer_password' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors());
            }       

            $customer = Customer::find($request->input('customer_id'));

            if (!$customer) {
                return response()->json(['message' => 'Customer Not Found']);
            }

            $customer->name = $request->input('customer_name');
            $customer->email = $request->input('customer_email');
            $customer->phone = $request->input('customer_mobile');
            if(isset($request->customer_password) && !empty($request->customer_password)) {
                $customer->password = Hash::make($request->input('customer_password'));
            }
            $customer->save();

            $addresses = Address::where('customer_id', $request->input('customer_id'))->get();

            if(!$addresses->isEmpty()) {
                foreach ($addresses as $key => $address) {
                    $address->name = $request->input('customer_name');
                    $address->email = $request->input('customer_email');
                    $address->phone = $request->input('customer_mobile');
                    $address->save();
                }   
            }

            return response()->json([
                'message' => 'Customer Updated Successfully',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_mobile' => $customer->phone
            ]);
        }
    }

    public function customerAddressDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $address = Address::where('customer_id', $request->input('customer_id'))->get();

        if($address->isEmpty()) {
            return response()->json(['message' => 'Customer Address Not Found']);
        }

        // if ($address->count() == 1) {
        //     $original = $address->first()->replicate(); // clone the model
        //     $original->id = -1; // change ID
        //     $address->push($original); // add to collection
        // }

        return response()->json([
            'message' => 'Details Fetched Successfully',
            'addresses' => $address
        ]);
    }

    public function customerAddressUpdate(Request $request)
    {
        $customer = Auth::guard('api')->user();
        if (!$customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        // if($request->input('address_id') == -1) {
        //     $validator = Validator::make($request->all(), [
        //         'address_id'      => 'required',
        //         'state' => 'required',
        //         'city' => 'required',
        //         'address' => 'required',
        //         'customer_id' => 'required',
        //         'name' => 'required',
        //         'email' => 'required|email',
        //         'mobile' => 'required',
        //     ]);
        // address_id of 0 or -1 means "create new address"
        if((int)$request->input('address_id') <= 0) {
            $validator = Validator::make($request->all(), [
                'state'       => 'required',
                'city'        => 'required',
                'address'     => 'required',
                'customer_id' => 'required',
                'name'        => 'required',
                'email'       => 'required|email',
                'mobile'      => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors());
            }
            
            $address = Address::create([
                'name'      => $request->input('name'),
                'email'     => $request->input('email'),
                'phone'     => $request->input('mobile'),
                'country'     => $request->input('country', 'AE'),
                'area'        => $request->input('area'),
                'state' => $request->input('state'),
                'city' => $request->input('city'),
                'address' => $request->input('address'),
                'customer_id' => $request->input('customer_id'),
                'is_default' => $request->input('is_default',0),
            ]);

            return response()->json([
                'message' => 'Customer Address Updated Successfully',
                'id'        => $address->id, 
                'addresses' => $address
            ]);
        }

        $validator = Validator::make($request->all(), [
            'address_id'      => 'required',
            'state' => 'required',
            'city' => 'required',
            'address' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $address = Address::find($request->input('address_id'));

        if (!$address) {
            return response()->json(['message' => 'Customer Address Not Found']);
        }

        $address->state = $request->input('state');
        $address->city = $request->input('city');
        $address->address = $request->input('address');
        $address->is_default = $request->input('is_default');
        $address->save();

         if ((int)$request->input('is_default') === 1) {
            Address::where('customer_id', $address->customer_id)
                ->where('id', '!=', $address->id)
                ->update(['is_default' => 0]);
        }

      

        return response()->json([
            'message' => 'Customer Address Updated Successfully',
            'addresses' => $address
        ]);
    }
     public function customerAddressDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address_id'  => 'required|integer',
            'customer_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $address = Address::where('id', $request->input('address_id'))
            ->where('customer_id', $request->input('customer_id'))
            ->first();

        if (!$address) {
            return response()->json(['message' => 'Address not found or does not belong to this customer'], 404);
        }

        $wasDefault = $address->is_default === 1;
        $address->delete();

        return response()->json([
            'message'    => 'Address deleted successfully',
            'was_default' => $wasDefault,
        ]);
    }

    public function customerOrders(Request $request)
    {
        // Customer/user ID (required for total filtering)
        $customerId = $request->input('customer_id');

        if (!$customerId) {
            return response()->json(['message' => 'Customer Id is Required']);
        }

        // Main columns
        $columns = [
            'ec_orders.id',
            'ec_orders.code',
            'ec_orders.created_at',
            'ec_orders.status',
            'ec_orders.amount',
            'ec_orders.tax_amount',
            'ec_orders.sub_total',
            'ec_orders.coupon_code',
            'payments.payment_channel'
        ];

        // Total: All records for the given customer
        $total = Order::where('ec_orders.user_id', $customerId)->count();

        // Filtered Query
        $filteredQuery = Order::select('ec_orders.id')
            ->leftJoin('payments', 'ec_orders.payment_id', '=', 'payments.id')
            ->where('ec_orders.user_id', $customerId);

        $dataQuery = Order::select(
                'ec_orders.id',
                'ec_orders.code',
                'ec_orders.created_at',
                'ec_orders.status',
                'ec_orders.amount',
                'ec_orders.tax_amount',
                'ec_orders.sub_total',
                'ec_orders.coupon_code',
                'payments.payment_channel'
            )
            ->leftJoin('payments', 'ec_orders.payment_id', '=', 'payments.id')
            ->where('ec_orders.user_id', $customerId);
        
        if ($request->input('with_products')) {
            $dataQuery->with(['products' => function ($q) {
                $q->select('id', 'order_id', 'product_image');
            }]);
        }

        // Search filters
        if ($request->filled('code')) {
            $filteredQuery->where('ec_orders.code', 'like', '%' . $request->code . '%');
            $dataQuery->where('ec_orders.code', 'like', '%' . $request->code . '%');
        }

        if ($request->filled('status')) {
            $filteredQuery->where('ec_orders.status', 'like', '%' . $request->status . '%');
            $dataQuery->where('ec_orders.status', 'like', '%' . $request->status . '%');
        }

        if ($request->filled('created_at')) {
            $filteredQuery->whereDate('ec_orders.created_at', $request->created_at);
            $dataQuery->whereDate('ec_orders.created_at', $request->created_at);
        }

        if ($request->filled('payment_channel')) {
            $filteredQuery->where('payments.payment_channel', 'like', '%' . $request->payment_channel . '%');
            $dataQuery->where('payments.payment_channel', 'like', '%' . $request->payment_channel . '%');
        }

        // Sorting
        $orderBy = $request->input('orderBy', 'ec_orders.id');
        $orderDir = $request->input('orderDir', 'desc');
        if (in_array($orderBy, $columns)) {
            $dataQuery->orderBy($orderBy, $orderDir);
        }

        // Pagination
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('pageSize', 10);

        $filteredTotal = $filteredQuery->distinct('ec_orders.id')->count('ec_orders.id');

        $orders = $dataQuery
            ->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get();

        // Add link column
        $orders->transform(function ($order) {
            $order->link = '/order-tracking';
            return $order;
        });

        return response()->json([
            'data' => $orders,
            'total' => $total,
            'filtered' => $filteredTotal
        ]);
    }

    public function customerOrderDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $order_products = OrderProduct::select('id', 'product_name', 'product_image', 'price', 'qty', 'total_amount', 'discount_percent', 'discount_amount', 'net_amount', 'tax_amount', 'gross_amount', 'is_gift')->where('order_id', $request->input('order_id'))->get();

        if($order_products->isEmpty()) {
            return response()->json(['message' => 'Order Products Not Found']);
        }

        $order_address = OrderAddress::select('id', 'name', 'phone', 'email', 'state', 'city', 'address')->where('order_id', $request->input('order_id'))->get();

        return response()->json([
            'message' => 'Details Fetched successfully',
            'order_products' => $order_products,
            'order_address' => $order_address,
        ]);
    }

    public function customerCouponDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // General coupons
        $generalCoupons = collect(Promotion::where('type', 'coupon')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            // ->with([
            //     'couponRules.products' => function ($query) {
            //         // $query->select('id', 'coupon_rule_id', 'product_id'); // optional: limit fields
            //     },
            // ])
            ->get()
            ->flatMap(function ($promotion) {
                return collect($promotion->couponRules)
                    ->filter(function ($rule) {
                        return $rule->apply_to !== 'customer' &&
                            $rule->coupon_code !== null;
                    })
                    ->map(function ($rule) use ($promotion) {
                        return [
                            'code' => $rule->coupon_code,
                            'value' => !is_null($rule->percentage) &&  $rule->coupon_type == 'percent' ? intval($rule->percentage) : intval($rule->amount),
                            'start_date' => Carbon::parse($promotion->start_date)->format('Y-m-d H:i:s'),
                            'end_date' => Carbon::parse($promotion->end_date)->format('Y-m-d H:i:s'),
                            'type' => $rule->apply_to, // or $promotion->type if needed
                            'coupon_type' => $rule->coupon_type,
                        ];
                    });
            }));

        // Customer-specific coupons
        $customerCoupons = collect();
        $customerId = $request->input('customer_id');

        if ($customerId && $customerId != '-1') {
            $customerCoupons = Promotion::where('type', 'coupon')
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->whereHas('couponRules', function ($query) use ($customerId) {
                    $query->where('apply_to', 'customer')
                        ->whereHas('customers', function ($q) use ($customerId) {
                            $q->where('customer_id', $customerId);
                        });
                })
                ->with([
                    'couponRules.customers' => function ($query) use ($customerId) {
                        $query->where('customer_id', $customerId);
                    }
                ])
                ->get()
                ->flatMap(function ($promotion) {
                    return $promotion->couponRules
                        ->filter(function ($rule) {
                            return $rule->apply_to === 'customer' && $rule->coupon_code;
                        })
                        ->map(function ($rule) use ($promotion) {
                            return [
                                'code' => $rule->coupon_code,
                                'value' => !is_null($rule->percentage) &&  $rule->coupon_type == 'percent' ? intval($rule->percentage) : intval($rule->amount),
                                'start_date' => Carbon::parse($promotion->start_date)->format('Y-m-d H:i:s'),
                                'end_date' => Carbon::parse($promotion->end_date)->format('Y-m-d H:i:s'),
                                'type' => $rule->apply_to,
                                'coupon_type' => $rule->coupon_type,
                            ];
                        });
                });
        }

        // Merge and return
        $mergedCoupons = $generalCoupons->merge($customerCoupons);

        return response()->json([
            'message' => 'Details Fetched Successfully',
            'coupons' => $mergedCoupons
        ]);
    }

    public function customerPasswordCheck(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id'      => 'required',
            'customer_password' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }
        
        $customer = Customer::find($request->input('customer_id'));

        if (!$customer) {
            return response()->json(['message' => 'Customer Not Found']);
        }

        $customer_password = Hash::check($request->input('customer_password'), $customer->password);

        if (!Hash::check($request->input('customer_password'), $customer->password)) {
            return response()->json(['message' => 'Incorrect Password']);
        }

        return response()->json([
            'message' => 'Customer Found Successfully',
        ]);
    }

    public function tamaraPayment(Request $request, $shippingData, $order, $prods) {
        $tax = Tax::select('percentage')->where('status', 'published')->first();

        $curl = curl_init();

        $payload = [
            "total_amount" => [
                "amount" => (float) $order->amount,
                "currency" => "AED"
            ],
            "shipping_amount" => [
                "amount" => (float) $order->shipping_amount + (float) $order->shipping_amount_vat,
                "currency" => "AED"
            ],
            "tax_amount" => [
                "amount" => $order->tax_amount * (1 + ($tax->percentage / 100)),
                "currency" => "AED"
            ],
            "order_reference_id" => explode('#', $order->code)[1],
            "order_number" => $order->code,
            "items" => [],
            "consumer" => [
                "email" => $request->input("billingAddress.email"),
                "first_name" => $request->input("billingAddress.first_name"),
                "last_name" => $request->input("billingAddress.last_name"),
                "phone_number" => $request->input('billingAddress.mobile')
            ],
            "country_code" => "AE",
            "description" => "AMG Order",
            "merchant_url" => [
                "cancel" => env('CUSTOM_URL')."tamara-payment-redirect/#/cancel",
                "failure" => env('CUSTOM_URL')."tamara-payment-redirect/#/fail",
                "success" => env('CUSTOM_URL')."tamara-payment-redirect/#/success"
            ],
            "payment_type" => "PAY_BY_INSTALMENTS",
            "instalments" => 3,
            "billing_address" => [
                "city" => $request->input("billingAddress.emirates"),
                "country_code" => "AE",
                "first_name" => $request->input("billingAddress.first_name"),
                "last_name" => $request->input("billingAddress.last_name"),
                "line1" => $request->input("billingAddress.area") . " " . $request->input("billingAddress.building"),
                "phone_number" => $request->input('billingAddress.mobile')
            ],
            "shipping_address" => [
                "city" => $shippingData["city"],
                "country_code" => "AE",
                "first_name" => $shippingData["first_name"],
                "last_name" => $shippingData["last_name"],
                "line1" => $shippingData["street1"],
                "phone_number" => $shippingData["phone"]
            ],
            "locale" => "en_US"
        ];

        \Log::info('Order Checkout Payload:', ['request' => $payload]);

        foreach ($prods as $item) {
            $vatPercent = $tax->percentage; // e.g., 5 or 15
            $totalAmount = (float) $item['price']; // already includes VAT
            
            // Unit price excluding VAT
            $unitPrice = $totalAmount / (1 + ($vatPercent / 100));

            // Tax = total - unit price
            $taxAmount = $totalAmount - $unitPrice;

            $payload['items'][] = [
                "name" => $item['name'],
                "type" => "Physical",
                "reference_id" => (string)$item['id'],
                "quantity" => $item['qty'],
                "sku" => $item['sku'],
                "unit_price" => [
                    "amount" => round($unitPrice, 2),
                    "currency" => "AED"
                ],
                "total_amount" => [
                    "amount" => round($totalAmount, 2),
                    "currency" => "AED"
                ],
                "tax_amount" => [
                    "amount" => round($taxAmount, 2),
                    "currency" => "AED"
                ],
            ];
        }

        // echo "<pre>";print_r($payload);die;

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => env('TAMARA_API_URL').'checkout',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . env('TAMARA_TOKEN')
            ],
        ]);

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            // echo 'Order checkout Error: ' . curl_error($ch);
            \Log::info('Order Checkout Error:', ['error' => curl_error($curl)]);exit;
        }

        curl_close($curl);
        // echo $response;die;
        $resp = json_decode($response, true);
        \Log::info('Order Checkout Response:', ['response' => $resp]);
        return $resp;
    }

    public function tamaraPaymentResponse(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {
        // echo "<pre>";print_r($request->all());die;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('TAMARA_API_URL')."orders/".$request->orderId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // curl_setopt($ch, CURLOPT_POST, true); // This is equivalent to --request POST

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . env('TAMARA_TOKEN')
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Execute the request
        $response = curl_exec($ch);

        // Check for errors
        if (curl_errno($ch)) {
            // echo 'order_approved Curl error: ' . curl_error($ch);
            \Log::info('Order Get Error:', ['error' => curl_error($ch)]);exit;
        }

        // Close cURL session
        curl_close($ch);

        $resp = json_decode($response, true);

        // echo "<pre>";print_r($resp);exit;
        \Log::info('Order Get Response:', ['response' => $resp]);

        if(!$resp['order_number'] && !isset($resp['order_number']) && empty($resp['order_number'])) {
            return response()->json(['message' => 'Transaction not found']);
        }

        $order = Order::select('ec_orders.id', 'ec_orders.code', 'ec_orders.status', 'ec_orders.amount', 'ec_orders.sub_total', 'ec_orders.shipping_amount', 'ec_orders.created_at', 'ec_orders.service_amount', 'ec_orders.vat', 'ec_orders.tax_amount', 'ec_orders.cod_charge', 'ec_order_addresses.name')->join('ec_order_addresses', 'ec_order_addresses.order_id', 'ec_orders.id', 'left')->where('ec_orders.code', $resp['order_number'])->first();

        if(!$order) {
            return response()->json(['message' => 'Order not found']);
        }

        $prod = OrderProduct::where('ec_order_product.order_id', $order->id)->get();

        return response()->json([
            'message'          => 'Details Fetched successfully',
            'order_id'         => $order->code,
            // 'payment_method'   => $order->payment_channel,
            'total'            => $order->amount,
            'sub_total'        => $order->sub_total,
            'shipping_amount'  => $order->shipping_amount,
            'status'           => $order->status,
            'created_at'       => $order->created_at,
            'service_amount'   => $order->service_amount,
            'vat_amount'       => $order->vat,
            'tax_amount'       => $order->tax_amount,
            // 'payment_status'   => $order->payment_status,
            'id'                =>   $order->id,
            'customer_name'=> $order->name,
            'products'         => $prod,
            'cod_charge'   => $order->cod_charge
        ]);

        // header('Location: http://localhost:3000/'.$order->lang.'/shop-order-payment-complete?q='.base64_encode($order->code));exit();
    }

    public function tamaraPaymentWebhook(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {
        $alreadyProcessed = Payment::where('charge_id', $request->order_id)
            ->where('status', 'completed')
            ->exists();

        if ($alreadyProcessed) {
            \Log::info('Tamara Webhook: Order already processed. Skipping.', ['tamara_order_id' => $request->order_id]);
            return response()->json(['message' => 'Already processed'], 200); 
        }
        
        if($request->event_type == 'order_approved') {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, env('TAMARA_API_URL')."orders/".$request->order_id."/authorise");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true); // This is equivalent to --request POST

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . env('TAMARA_TOKEN')
            ];

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // Execute the request
            $response = curl_exec($ch);

            // Check for errors
            if (curl_errno($ch)) {
                // echo 'order_approved Curl error: ' . curl_error($ch);
                \Log::info('Order Get Error:', ['error' => curl_error($ch)]);exit();
            }

            // Close cURL session
            curl_close($ch);

            $resp = json_decode($response, true);

            \Log::info('Order Approved Response:', ['response' => $resp]);
        } elseif($request->event_type == 'order_authorised') {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, env('TAMARA_API_URL')."orders/".$request->order_id);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // curl_setopt($ch, CURLOPT_POST, true); // This is equivalent to --request POST

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . env('TAMARA_TOKEN')
            ];

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // Execute the request
            $response = curl_exec($ch);

            // Check for errors
            if (curl_errno($ch)) {
                // echo 'order_approved Curl error: ' . curl_error($ch);
                \Log::info('Order Get Error:', ['error' => curl_error($ch)]);exit();
            }

            // Close cURL session
            curl_close($ch);

            $resp = json_decode($response, true);

            // echo "<pre>";print_r($resp);exit;

            \Log::info('Order Get Response:', ['response' => $resp]);

            if (isset($resp['status']) && ($resp['status'] != 'fully_captured' && $resp['status'] != 'partially_captured')) {

                $url = env('TAMARA_API_URL')."payments/capture";

                $data = [
                    "order_id" => $request->order_id,
                    "total_amount" => $resp['total_amount'],
                    "items" => $resp['items'],
                    "shipping_amount" => $resp['shipping_amount'],
                    "tax_amount" => $resp['tax_amount'],
                    "shipping_info" => [
                        "shipped_at" => now(),
                        "shipping_company" => "SMSA"
                    ]
                ];

                // Initialize cURL session
                $ch = curl_init($url);

                // Set cURL options
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . env('TAMARA_TOKEN')
                ]);

                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

                // Execute cURL request
                $capture_response = curl_exec($ch);

                // Error handling
                if (curl_errno($ch)) {
                    // echo 'order_authorised Curl error: ' . curl_error($ch);
                    \Log::info('Order Captured Error:', ['error' => curl_error($ch)]);exit();
                }

                curl_close($ch);

                $capture_resp = json_decode($capture_response, true);

                // echo "<pre>";print_r($capture_resp);exit;

                \Log::info('Order Captured Response:', ['response' => $capture_resp]);

                $order = Order::where('code', $resp['order_number'])->orderBy('id', 'desc')->first();
                // echo "<pre>";print_r($order);
                $createPaymentForOrderService->execute(
                    $order,
                    'tamara',
                    $capture_resp['status'],
                    // $customer->id,
                    $order->user_id,
                    $capture_resp['order_id'],
                    $capture_resp['status'],
                );

                if (isset($capture_resp['status']) && $capture_resp['status'] != 'fully_captured') {
                    // return response()->json([
                    //     'message' => 'Order Payment Captured Failed',
                    // ]);

                    $url = env('TAMARA_API_URL')."orders/".$request->order_id."/cancel";
                    
                    // Initialize cURL session
                    $ch = curl_init($url);

                    // Set cURL options
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Authorization: Bearer ' . env('TAMARA_TOKEN')
                    ]);

                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

                    // Execute cURL request
                    $cancel_response = curl_exec($ch);

                    // Error handling
                    if (curl_errno($ch)) {
                        // echo 'order_authorised Curl error: ' . curl_error($ch);
                        \Log::info('Order Canceled Error:', ['error' => curl_error($ch)]);exit();
                    }

                    curl_close($ch);

                    $cancel_resp = json_decode($cancel_response, true);

                    // echo "<pre>";print_r($cancel_resp);exit;

                    \Log::info('Order Canceled Response:', ['response' => $cancel_resp]);

                    $order = Order::where('code', $resp['order_number'])->orderBy('id', 'desc')->first();
                    // echo "<pre>";print_r($order);
                    $createPaymentForOrderService->execute(
                        $order,
                        'tamara',
                        $cancel_resp['status'],
                        // $customer->id,
                        $order->user_id,
                        $cancel_resp['order_id'],
                        $cancel_resp['status'],
                    );

                    return response()->json([
                        'message' => 'Order Payment Canceled Successfully',
                    ]);
                }

                return response()->json([
                    'message' => 'Order Payment Captured Successfully',
                ]);
            }
            $order = Order::where('code', $resp['order_number'])->orderBy('id', 'desc')->first();
                // echo "<pre>";print_r($order);
                $createPaymentForOrderService->execute(
                    $order,
                    'tamara',
                    $resp['status'],
                    // $customer->id,
                    $order->user_id,
                    $resp['order_id'],
                    $resp['status'],
            );

            return response()->json([
                'message' => 'Order Payment Auto Captured Successfully',
            ]);
        } elseif($request->event_type == 'order_canceled') {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, env('TAMARA_API_URL')."orders/".$request->order_id);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // curl_setopt($ch, CURLOPT_POST, true); // This is equivalent to --request POST

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . env('TAMARA_TOKEN')
            ];

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // Execute the request
            $response = curl_exec($ch);

            // Check for errors
            if (curl_errno($ch)) {
                // echo 'order_approved Curl error: ' . curl_error($ch);
                \Log::info('Order Get Error:', ['error' => curl_error($ch)]);exit();
            }

            // Close cURL session
            curl_close($ch);

            $resp = json_decode($response, true);

            // echo "<pre>";print_r($resp);exit;

            \Log::info('Order Get Response:', ['response' => $resp]);

            if(!isset($resp['status']) && $resp['status'] != 'new') {
                return response()->json([
                    'message' => 'Order Payment Canceled Failed',
                ]);
            }

            $order = Order::where('code', $resp['order_number'])->orderBy('id', 'desc')->first();
            // echo "<pre>";print_r($order);
            $createPaymentForOrderService->execute(
                $order,
                'tamara',
                $resp['status'],
                // $customer->id,
                $order->user_id,
                $resp['order_id'],
                $resp['status'],
            );

            return response()->json([
                'message' => 'Order Payment Canceled Successfully',
            ]);
        } elseif($request->event_type == 'order_declined') {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, env('TAMARA_API_URL')."orders/".$request->order_id);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // curl_setopt($ch, CURLOPT_POST, true); // This is equivalent to --request POST

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . env('TAMARA_TOKEN')
            ];

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // Execute the request
            $response = curl_exec($ch);

            // Check for errors
            if (curl_errno($ch)) {
                // echo 'order_approved Curl error: ' . curl_error($ch);
                \Log::info('Order Get Error:', ['error' => curl_error($ch)]);exit();
            }

            // Close cURL session
            curl_close($ch);

            $resp = json_decode($response, true);

            // echo "<pre>";print_r($resp);exit;

            \Log::info('Order Get Response:', ['response' => $resp]);

            if(!isset($resp['status']) && $resp['status'] != 'declined') {
                return response()->json([
                    'message' => 'Order Payment Declined Failed',
                ]);
            }

            $order = Order::where('code', $resp['order_number'])->orderBy('id', 'desc')->first();
            // echo "<pre>";print_r($order);
            $createPaymentForOrderService->execute(
                $order,
                'tamara',
                $resp['status'],
                // $customer->id,
                $order->user_id,
                $resp['order_id'],
                $request->data['declined_reason'],
            );

            return response()->json([
                'message' => 'Order Payment Declined Successfully',
            ]);
        } elseif($request->event_type == 'order_refunded') {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, env('TAMARA_API_URL')."orders/".$request->order_id);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // curl_setopt($ch, CURLOPT_POST, true); // This is equivalent to --request POST

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . env('TAMARA_TOKEN')
            ];

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // Execute the request
            $response = curl_exec($ch);

            // Check for errors
            if (curl_errno($ch)) {
                // echo 'order_approved Curl error: ' . curl_error($ch);
                \Log::info('Order Get Error:', ['error' => curl_error($ch)]);exit();
            }

            // Close cURL session
            curl_close($ch);

            $resp = json_decode($response, true);

            // echo "<pre>";print_r($resp);exit;

            \Log::info('Order Get Response:', ['response' => $resp]);

            if(!isset($resp['status']) || $resp['status'] != 'fully_captured') {
                return response()->json([
                    'message' => 'Order Payment Refund Failed',
                ]);
            }

            $url = env('TAMARA_API_URL')."payments/simplified-refund/".$request->order_id;

            $data = [
                "total_amount" => $resp['total_amount'],
                "comment" => "Refund for the order".$resp['order_number']
            ];

            // Initialize cURL session
            $ch = curl_init($url);

            // Set cURL options
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . env('TAMARA_TOKEN')
            ]);

            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

            // Execute cURL request
            $refund_response = curl_exec($ch);

            // Error handling
            if (curl_errno($ch)) {
                // echo 'order_authorised Curl error: ' . curl_error($ch);
                \Log::info('Order Refunded Error:', ['error' => curl_error($ch)]);exit();
            }

            curl_close($ch);

            $refund_resp = json_decode($refund_response, true);

            // echo "<pre>";print_r($refund_resp);exit;

            \Log::info('Order Refunded Response:', ['response' => $refund_resp]);

            return response()->json([
                'message' => 'Order Payment Refund Successfully',
            ]);
        }
    }
}
