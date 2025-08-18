<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use App\Models\BogoRule;
use App\Models\BuyXGetYRule;
use App\Models\BuyXGetYProduct;
use App\Models\BuyXGetYCategory;
use App\Models\DiscountRule;
use App\Models\DiscountIndividualRule;
use App\Models\DiscountProduct;
use App\Models\DiscountCategory;
use App\Models\CouponRule;
use App\Models\CouponProduct;
use App\Models\CouponCustomer;
use App\Models\CouponCategory;
use App\Models\FocRule;
use App\Models\FocProduct;
use App\Models\FocCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule; // Added import for Rule class
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Models\Customer;
use Carbon\Carbon;

class PromotionController extends Controller
{
    public function create()
    {
        $products = Product::select('id', 'name', 'price')->get()->toArray();
        $customers = Customer::select('id', 'name')->get()->toArray();
        // Get IDs of products associated with active coupon promotions
        $today = Carbon::today();
        // $discountedProductIds = DB::table('coupon_rules')
        //     ->join('promotions', 'coupon_rules.promotion_id', '=', 'promotions.id')
        //     ->where('promotions.start_date', '<=', $today)
        //     ->where('promotions.end_date', '>=', $today)
        //     ->join('coupon_products', 'coupon_rules.id', '=', 'coupon_products.coupon_rule_id')
        //     ->pluck('coupon_products.product_id')
        //     ->unique()
        //     ->values()
        //     ->toArray();
        $discountedProductIds = DB::table('promotions')
            ->where('promotions.start_date', '<=', $today)
            ->where('promotions.end_date', '>=', $today)
            ->whereIn('promotions.type', ['coupon', 'discount', 'bogo', 'buy_x_get_y'])
            ->leftJoin('coupon_rules', function ($join) {
                $join->on('promotions.id', '=', 'coupon_rules.promotion_id')
                    ->where('promotions.type', '=', 'coupon');
            })
            ->leftJoin('coupon_products', 'coupon_rules.id', '=', 'coupon_products.coupon_rule_id')
            ->leftJoin('discount_rules', function ($join) {
                $join->on('promotions.id', '=', 'discount_rules.promotion_id')
                    ->where('promotions.type', '=', 'discount');
            })
            ->leftJoin('discount_products', 'discount_rules.id', '=', 'discount_products.discount_rule_id')
            // ->leftJoin('bogo_rules', function ($join) {
            //     $join->on('promotions.id', '=', 'bogo_rules.promotion_id')
            //         ->where('promotions.type', '=', 'bogo');
            // })
            ->leftJoin('buy_x_get_y_rules', function ($join) {
                $join->on('promotions.id', '=', 'buy_x_get_y_rules.promotion_id')
                    ->where('promotions.type', '=', 'buy_x_get_y');
            })
            ->leftJoin('buy_x_get_y_products', 'buy_x_get_y_rules.id', '=', 'buy_x_get_y_products.rule_id')
            // ->selectRaw('COALESCE(coupon_products.product_id, discount_products.product_id, bogo_rules.buy_product_id, bogo_rules.free_product_id, buy_x_get_y_products.product_id) as product_id')
            ->selectRaw('COALESCE(coupon_products.product_id, discount_products.product_id, buy_x_get_y_products.product_id) as product_id')
            ->havingRaw('product_id IS NOT NULL')
            ->pluck('product_id')
            ->unique()
            ->values()
            ->toArray();
        return view('promotions.create', compact('products', 'customers', 'discountedProductIds'));
    }

    public function store(Request $request)
    {
        // echo "<pre>";print_r($request->all());die;
        $today = Carbon::today();
        $discountedProductIds = DB::table('promotions')
            ->where('promotions.start_date', '<=', $today)
            ->where('promotions.end_date', '>=', $today)
            ->where('promotions.type', 'coupon')
            ->join('coupon_rules', 'promotions.id', '=', 'coupon_rules.promotion_id')
            ->join('coupon_products', 'coupon_rules.id', '=', 'coupon_products.coupon_rule_id')
            ->select('coupon_products.product_id')
            ->union(
                DB::table('promotions')
                    ->where('promotions.start_date', '<=', $today)
                    ->where('promotions.end_date', '>=', $today)
                    ->where('promotions.type', 'discount')
                    ->join('discount_rules', 'promotions.id', '=', 'discount_rules.promotion_id')
                    ->join('discount_products', 'discount_rules.id', '=', 'discount_products.discount_rule_id')
                    ->select('discount_products.product_id')
            )
            ->pluck('product_id')
            ->unique()
            ->values()
            ->toArray();

        $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['bogo', 'buy_x_get_y', 'discount', 'coupon', 'foc'])],
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        try {
            DB::beginTransaction();

            // Create promotion
            $promotion = Promotion::create([
                'name' => $request->name,
                'type' => $request->type,
                'description' => $request->description,
                'start_date' => Carbon::parse($request->start_date)->startOfDay(),
                'end_date' => Carbon::parse($request->end_date)->endOfDay(),
            ]);

            switch ($request->type) {
                // case 'bogo':
                //     $this->storeBogoRules($promotion, $request);
                //     break;
                case 'buy_x_get_y':
                    $this->storeBuyXGetYRules($promotion, $request);
                    break;
                case 'discount':
                    $this->storeDiscountRules($promotion, $request, $discountedProductIds);
                    break;
                case 'coupon':
                    $this->storeCouponRules($promotion, $request, $discountedProductIds);
                    break;
                case 'foc':
                    $this->storeFocRules($promotion, $request);
                    break;
            }

            DB::commit();
            return redirect()->route('promotions.create')->with('success', 'Promotion created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Failed to create promotion: ' . $e->getMessage()]);
        }
    }

    // private function storeBogoRules(Promotion $promotion, Request $request)
    // {
    //     $productIds = $request->input('conditions.bogo.product_ids', []);
    //     $freeProductIds = $request->input('rewards.bogo.free_product_ids', []);

    //     foreach ($productIds as $index => $buyProductId) {
    //         if (isset($freeProductIds[$index])) {
    //             BogoRule::create([
    //                 'promotion_id' => $promotion->id,
    //                 'buy_product_id' => $buyProductId,
    //                 'free_product_id' => $freeProductIds[$index],
    //             ]);
    //         }
    //     }
    // }

    private function storeBuyXGetYRules(Promotion $promotion, Request $request)
    {
        $rule = BuyXGetYRule::create([
            'promotion_id' => $promotion->id,
            'buy_quantity' => $request->input('conditions.buy_x_get_y.buy_quantity'),
            'get_quantity' => $request->input('rewards.buy_x_get_y.get_quantity'),
        ]);

        // Store buy products
        foreach ($request->input('conditions.buy_x_get_y.product_ids', []) as $productId) {
            BuyXGetYProduct::create([
                'rule_id' => $rule->id,
                'product_id' => $productId,
                'type' => 'buy',
            ]);
        }

        // Store free products
        foreach ($request->input('rewards.buy_x_get_y.free_product_ids', []) as $productId) {
            BuyXGetYProduct::create([
                'rule_id' => $rule->id,
                'product_id' => $productId,
                'type' => 'free',
            ]);
        }

        // Store buy categories
        // foreach ($request->input('conditions.buy_x_get_y.category_ids', []) as $categoryId) {
        //     BuyXGetYCategory::create([
        //         'rule_id' => $rule->id,
        //         'category_id' => $categoryId,
        //         'type' => 'buy',
        //     ]);
        // }

        // Store free categories
        // foreach ($request->input('rewards.buy_x_get_y.free_category_ids', []) as $categoryId) {
        //     BuyXGetYCategory::create([
        //         'rule_id' => $rule->id,
        //         'category_id' => $categoryId,
        //         'type' => 'free',
        //     ]);
        // }
    }

    private function storeDiscountRules(Promotion $promotion, Request $request, $discountedProductIds)
    {
        $applyTo = $request->input('conditions.discount.apply_to');
        $rule = DiscountRule::create([
            'promotion_id' => $promotion->id,
            'apply_to' => $applyTo,
            'percentage' => $applyTo === 'all' ? $request->input('rewards.discount.percentage') : 
                          ($applyTo === 'group' ? $request->input('rewards.discount.group_percentage') : null),
        ]);

        if ($applyTo === 'individual') {
            $productIds = $request->input('conditions.discount.product_ids', []);
            $discountTypes = $request->input('rewards.discount.discount_type', []);
            $values = $request->input('rewards.discount.value', []);
            $productPrices = $request->input('rewards.discount.product_price', []);
            $discountAmounts = $request->input('rewards.discount.discount_amount', []);
            $finalPrices = $request->input('rewards.discount.final_price', []);

            foreach ($productIds as $index => $productId) {
                if (isset($discountTypes[$index], $values[$index])) {
                    DiscountIndividualRule::create([
                        'discount_rule_id' => $rule->id,
                        'product_id' => $productId,
                        'discount_type' => $discountTypes[$index],
                        'value' => $values[$index],
                        'product_price' => $productPrices[$index],
                        'discount_amount' => $discountAmounts[$index],
                        'final_price' => $finalPrices[$index],
                    ]);
                }
            }
        } elseif ($applyTo === 'group') {
            foreach ($request->input('conditions.discount.group_product_ids', []) as $productId) {
                DiscountProduct::create([
                    'discount_rule_id' => $rule->id,
                    'product_id' => $productId,
                ]);
            }
        } elseif ($applyTo === 'all') {
            $allProductIds = Product::query()->pluck('id')->all();
            $eligibleProductIds = array_diff($allProductIds, $discountedProductIds);
            foreach ($eligibleProductIds as $productId) {
                DiscountProduct::create([
                    'discount_rule_id' => $rule->id,
                    'product_id' => $productId,
                ]);
            }
        }

        // foreach ($request->input('conditions.discount.category_ids', []) as $categoryId) {
        //     DiscountCategory::create([
        //         'discount_rule_id' => $rule->id,
        //         'category_id' => $categoryId,
        //     ]);
        // }
    }

    private function storeCouponRules(Promotion $promotion, Request $request, $discountedProductIds)
    {
        // echo "<pre>";print_r($request->all());die;
        $applyTo = $request->input('conditions.coupon.apply_to');
        $percentage = null;

        if ($applyTo === 'all') {
            $percentage = $request->input('rewards.coupon.percentage');
        } elseif ($applyTo === 'group') {
            $percentage = $request->input('rewards.coupon.group_percentage');
        } elseif ($applyTo === 'customer') {
            $percentage = $request->input('rewards.coupon.customer_percentage');
        }

        $rule = CouponRule::create([
            'promotion_id' => $promotion->id,
            'coupon_code' => $request->input('coupon_code'),
            'apply_to' => $applyTo,
            'percentage' => $percentage,
        ]);

        if ($request->input('conditions.coupon.apply_to') === 'group') {
            foreach ($request->input('conditions.coupon.group_product_ids', []) as $productId) {
                CouponProduct::create([
                    'coupon_rule_id' => $rule->id,
                    'product_id' => $productId,
                ]);
            }
        }

        // foreach ($request->input('conditions.coupon.category_ids', []) as $categoryId) {
        //     CouponCategory::create([
        //         'coupon_rule_id' => $rule->id,
        //         'category_id' => $categoryId,
        //     ]);
        // }

        elseif ($request->input('conditions.coupon.apply_to') === 'customer') {
            // foreach ($request->input('conditions.coupon.customer_ids', []) as $customerId) {
            //     CouponCustomer::create([
            //         'coupon_rule_id' => $rule->id,
            //         'customer_id' => $customerId,
            //     ]);
            // }
            $customerIds = $request->input('conditions.coupon.customer_ids', []);
            $rule->customers()->sync($customerIds);
        }
        // $customerIds = $request->input('conditions.coupon.customer_ids', []);
        // $rule->customers()->sync($customerIds);
        elseif ($request->input('conditions.coupon.apply_to') === 'all') {
            $allProductIds = Product::query()->pluck('id')->all();
            $eligibleProductIds = array_diff($allProductIds, $discountedProductIds);
            foreach ($eligibleProductIds as $productId) {
                CouponProduct::create([
                    'coupon_rule_id' => $rule->id,
                    'product_id' => $productId,
                ]);
            }
        }
    }

    private function storeFocRules(Promotion $promotion, Request $request)
    {
        $rule = FocRule::create([
            'promotion_id' => $promotion->id,
            'min_threshold' => $request->input('conditions.foc.min_threshold'),
            'max_threshold' => $request->input('conditions.foc.max_threshold'),
        ]);

        foreach ($request->input('rewards.foc.free_product_ids', []) as $productId) {
            FocProduct::create([
                'foc_rule_id' => $rule->id,
                'product_id' => $productId,
            ]);
        }

        // foreach ($request->input('rewards.foc.free_category_ids', []) as $categoryId) {
        //     FocCategory::create([
        //         'foc_rule_id' => $rule->id,
        //         'category_id' => $categoryId,
        //     ]);
        // }
    }
}