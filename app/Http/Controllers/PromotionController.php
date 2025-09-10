<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use App\Models\BogoRule;
use App\Models\BuyXGetYRule;
use App\Models\BuyXGetYProduct;
use App\Models\DiscountRule;
use App\Models\DiscountIndividualRule;
use App\Models\DiscountProduct;
use App\Models\CouponRule;
use App\Models\CouponProduct;
use App\Models\FocRule;
use App\Models\FocProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Models\Customer;
use Carbon\Carbon;

class PromotionController extends Controller
{
    public function index()
    {
        $promotions = Promotion::where('isDeleted', false)
            ->with([
                'couponRules.products.product',
                'discountRules.products.product',
                'discountRules.individualRules.product',
                'buyXGetYRules.products.product',
                'focRules.products.product'
            ])
            ->orderBy('start_date', 'desc')
            ->get();

        return view('promotions.index', compact('promotions'));
    }

    public function create()
    {
        $products = Product::select('id', 'name', 'price')->get()->toArray();
        $customers = Customer::select('id', 'name')->get()->toArray();
        $today = Carbon::today();

        $discountedProductIds = DB::table('promotions')
            ->where('promotions.end_date', '>=', $today)
            ->where('promotions.isDeleted', false)
            ->whereIn('promotions.type', ['coupon', 'discount', 'buy_x_get_y'])
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
            ->leftJoin('buy_x_get_y_rules', function ($join) {
                $join->on('promotions.id', '=', 'buy_x_get_y_rules.promotion_id')
                     ->where('promotions.type', '=', 'buy_x_get_y');
            })
            ->leftJoin('buy_x_get_y_products', 'buy_x_get_y_rules.id', '=', 'buy_x_get_y_products.rule_id')
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
        $today = Carbon::today();

        $discountedProductIds = DB::table('promotions')
            ->where('promotions.end_date', '>=', $today)
            ->where('promotions.isDeleted', false)
            ->where('promotions.type', 'coupon')
            ->join('coupon_rules', 'promotions.id', '=', 'coupon_rules.promotion_id')
            ->join('coupon_products', 'coupon_rules.id', '=', 'coupon_products.coupon_rule_id')
            ->select('coupon_products.product_id')
            ->union(
                DB::table('promotions')
                    ->where('promotions.end_date', '>=', $today)
                    ->where('promotions.type', 'discount')
                    ->where('promotions.isDeleted', false)
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

            $promotion = Promotion::create([
                'name' => $request->name,
                'type' => $request->type,
                'description' => $request->description,
                'start_date' => Carbon::parse($request->start_date)->startOfDay(),
                'end_date' => Carbon::parse($request->end_date)->endOfDay(),
            ]);

            switch ($request->type) {
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
            return redirect()->route('promotions.index')->with('success', 'Promotion created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Failed to create promotion: ' . $e->getMessage()]);
        }
    }

    public function edit($id)
{
    // Find the promotion by its ID, including all its related rules and products.
    $promotion = Promotion::with([
        'couponRules.products.product',
        'discountRules.products.product',
        'discountRules.individualRules.product',
        'buyXGetYRules.products.product',
        'focRules.products.product'
    ])->findOrFail($id);

    // Fetch all products and customers from the database.
    $products = Product::select('id', 'name', 'price')->get()->toArray();
    $customers = Customer::select('id', 'name')->get()->toArray();

    // Prepare a structured data array to easily pass to the view.
    $promotionData = $this->preparePromotionData($promotion);

    // Get a list of products currently under a different, active promotion.
    $today = Carbon::today();
    $discountedProductIds = DB::table('promotions')
        ->where('promotions.end_date', '>=', $today)
        ->where('promotions.isDeleted', false)
        ->where('promotions.id', '!=', $id) // Exclude the current promotion
        ->whereIn('promotions.type', ['coupon', 'discount', 'buy_x_get_y'])
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
        ->leftJoin('buy_x_get_y_rules', function ($join) {
            $join->on('promotions.id', '=', 'buy_x_get_y_rules.promotion_id')
                ->where('promotions.type', '=', 'buy_x_get_y');
        })
        ->leftJoin('buy_x_get_y_products', 'buy_x_get_y_rules.id', '=', 'buy_x_get_y_products.rule_id')
        ->selectRaw('COALESCE(coupon_products.product_id, discount_products.product_id, buy_x_get_y_products.product_id) as product_id')
        ->havingRaw('product_id IS NOT NULL')
        ->pluck('product_id')
        ->unique()
        ->values()
        ->toArray();

    return view('promotions.create', compact('promotion', 'products', 'customers', 'promotionData', 'discountedProductIds'));
}

private function preparePromotionData(Promotion $promotion)
{
    $promotionData = [
        'buy_x_get_y_rule' => null,
        'buy_products' => [],
        'free_products' => [],
        'discount_rule' => null,
        'individual_rules' => [],
        'group_products' => [],
        'coupon_rule' => null,
        'customers' => [],
        'foc_rule' => null,
    ];

    switch ($promotion->type) {
        case 'buy_x_get_y':
            if ($promotion->buyXGetYRules->isNotEmpty()) {
                $rule = $promotion->buyXGetYRules->first();
                $promotionData['buy_x_get_y_rule'] = $rule;
                $promotionData['buy_products'] = $rule->products()->where('type', 'buy')->pluck('product_id')->toArray();
                $promotionData['free_products'] = $rule->products()->where('type', 'free')->pluck('product_id')->toArray();
            }
            break;
        case 'discount':
            if ($promotion->discountRules->isNotEmpty()) {
                $rule = $promotion->discountRules->first();
                $promotionData['discount_rule'] = $rule;
                if ($rule->apply_to === 'individual') {
                    $promotionData['individual_rules'] = $rule->individualRules->toArray();
                } elseif ($rule->apply_to === 'group') {
                    $promotionData['group_products'] = $rule->products->pluck('product_id')->toArray();
                }
            }
            break;
        case 'coupon':
            if ($promotion->couponRules->isNotEmpty()) {
                $rule = $promotion->couponRules->first();
                $promotionData['coupon_rule'] = $rule;
                if ($rule->apply_to === 'group') {
                    $promotionData['group_products'] = $rule->products->pluck('product_id')->toArray();
                } elseif ($rule->apply_to === 'customer') {
                    $promotionData['customers'] = $rule->customers->pluck('id')->toArray();
                }
            }
            break;
        case 'foc':
            if ($promotion->focRules->isNotEmpty()) {
                $rule = $promotion->focRules->first();
                $promotionData['foc_rule'] = $rule;
                $promotionData['free_products'] = $rule->products->pluck('product_id')->toArray();
            }
            break;
    }

    return $promotionData;
}

    private function storeBuyXGetYRules(Promotion $promotion, Request $request)
    {
        $rule = BuyXGetYRule::create([
            'promotion_id' => $promotion->id,
            'buy_quantity' => $request->input('conditions.buy_x_get_y.buy_quantity'),
            'get_quantity' => $request->input('rewards.buy_x_get_y.get_quantity'),
        ]);

        foreach ($request->input('conditions.buy_x_get_y.product_ids', []) as $productId) {
            BuyXGetYProduct::create([
                'rule_id' => $rule->id,
                'product_id' => $productId,
                'type' => 'buy',
            ]);
        }

        foreach ($request->input('rewards.buy_x_get_y.free_product_ids', []) as $productId) {
            BuyXGetYProduct::create([
                'rule_id' => $rule->id,
                'product_id' => $productId,
                'type' => 'free',
            ]);
        }
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
    }

    private function storeCouponRules(Promotion $promotion, Request $request, $discountedProductIds)
    {
        $applyTo = $request->input('conditions.coupon.apply_to');
        $couponType = $request->input('rewards.coupon.type');
        
        // Get product_type when apply_to is 'customer'
        $productType = $applyTo === 'customer' 
            ? $request->input('conditions.coupon.product_type')
            : null;

        // Map values to database-safe enum: 'percent' or 'amount'
        $couponTypeDb = $couponType === 'percentage' ? 'percent' : 'amount';
        $percentage = $couponTypeDb === 'percent' ? (
            $applyTo === 'all' ? $request->input('rewards.coupon.percentage') :
            ($applyTo === 'group' ? $request->input('rewards.coupon.group_percentage') : $request->input('rewards.coupon.customer_percentage'))
        ) : null;

        $amount = $couponTypeDb === 'amount' ? (
            $applyTo === 'all' ? $request->input('rewards.coupon.amount') :
            ($applyTo === 'group' ? $request->input('rewards.coupon.group_amount') : $request->input('rewards.coupon.customer_amount'))
        ) : null;

        $rule = CouponRule::create([
            'promotion_id' => $promotion->id,
            'coupon_code' => $request->input('coupon_code'),
            'apply_to' => $applyTo,
            'coupon_type' => $couponTypeDb,
            'percentage' => $percentage,
            'amount' => $amount,
            'product_type' => $productType, // Add this line to save product_type
        ]);

        if ($applyTo === 'customer') {
            // Handle customer-specific logic
            $customerIds = $request->input('conditions.coupon.customer_ids', []);
            $rule->customers()->sync($customerIds);

            // Handle product assignments based on product_type
            if ($productType === 'group') {
                $groupProductIds = $request->input('conditions.coupon.group_product_ids', []);
                foreach ($groupProductIds as $productId) {
                    CouponProduct::create([
                        'coupon_rule_id' => $rule->id,
                        'product_id' => $productId,
                    ]);
                }
            } elseif ($productType === 'all') {
                $allProductIds = Product::query()->pluck('id')->all();
                $eligibleProductIds = array_diff($allProductIds, $discountedProductIds);
                foreach ($eligibleProductIds as $productId) {
                    CouponProduct::create([
                        'coupon_rule_id' => $rule->id,
                        'product_id' => $productId,
                    ]);
                }
            }
        } elseif ($applyTo === 'group') {
            foreach ($request->input('conditions.coupon.group_product_ids', []) as $productId) {
                CouponProduct::create([
                    'coupon_rule_id' => $rule->id,
                    'product_id' => $productId,
                ]);
            }
        } elseif ($applyTo === 'all') {
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
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids', []);
        if (!empty($ids)) {
            Promotion::whereIn('id', $ids)->update(['isDeleted' => true]);
        }

        return redirect()->route('promotions.index')
            ->with('success', 'Selected promotions deleted successfully.');
    }

    public function destroy($id)
    {
        $promotion = Promotion::findOrFail($id);
        $promotion->update(['isDeleted' => true]);

        return redirect()->route('promotions.index')
            ->with('success', 'Promotion deleted successfully.');
    }
}
