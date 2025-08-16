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
use App\Models\CouponCategory;
use App\Models\FocRule;
use App\Models\FocProduct;
use App\Models\FocCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Botble\Ecommerce\Models\Product;
use Carbon\Carbon;

class PromotionController extends Controller
{
    public function create()
    {
        $products = Product::select('id', 'name', 'price')->get()->toArray();
        return view('promotions.create', compact('products'));
    }

    public function store(Request $request)
    {
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
                case 'bogo':
                    $this->storeBogoRules($promotion, $request);
                    break;
                case 'buy_x_get_y':
                    $this->storeBuyXGetYRules($promotion, $request);
                    break;
                case 'discount':
                    $this->storeDiscountRules($promotion, $request);
                    break;
                case 'coupon':
                    $this->storeCouponRules($promotion, $request);
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

    private function storeBogoRules(Promotion $promotion, Request $request)
    {
        $productIds = $request->input('conditions.bogo.product_ids', []);
        $freeProductIds = $request->input('rewards.bogo.free_product_ids', []);

        foreach ($productIds as $index => $buyProductId) {
            if (isset($freeProductIds[$index])) {
                BogoRule::create([
                    'promotion_id' => $promotion->id,
                    'buy_product_id' => $buyProductId,
                    'free_product_id' => $freeProductIds[$index],
                ]);
            }
        }
    }

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
        foreach ($request->input('conditions.buy_x_get_y.category_ids', []) as $categoryId) {
            BuyXGetYCategory::create([
                'rule_id' => $rule->id,
                'category_id' => $categoryId,
                'type' => 'buy',
            ]);
        }

        // Store free categories
        foreach ($request->input('rewards.buy_x_get_y.free_category_ids', []) as $categoryId) {
            BuyXGetYCategory::create([
                'rule_id' => $rule->id,
                'category_id' => $categoryId,
                'type' => 'free',
            ]);
        }
    }

    private function storeDiscountRules(Promotion $promotion, Request $request)
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
            foreach ($allProductIds as $productId) {
                DiscountProduct::create([
                    'discount_rule_id' => $rule->id,
                    'product_id' => $productId,
                ]);
            }
        }

        foreach ($request->input('conditions.discount.category_ids', []) as $categoryId) {
            DiscountCategory::create([
                'discount_rule_id' => $rule->id,
                'category_id' => $categoryId,
            ]);
        }
    }

    private function storeCouponRules(Promotion $promotion, Request $request)
    {
        $rule = CouponRule::create([
            'promotion_id' => $promotion->id,
            'coupon_code' => $request->input('coupon_code'),
            'apply_to' => $request->input('conditions.coupon.apply_to'),
            'percentage' => $request->input('conditions.coupon.apply_to') === 'all' 
                ? $request->input('rewards.coupon.percentage') 
                : $request->input('rewards.coupon.group_percentage'),
        ]);

        if ($request->input('conditions.coupon.apply_to') === 'group') {
            foreach ($request->input('conditions.coupon.group_product_ids', []) as $productId) {
                CouponProduct::create([
                    'coupon_rule_id' => $rule->id,
                    'product_id' => $productId,
                ]);
            }
        }

        foreach ($request->input('conditions.coupon.category_ids', []) as $categoryId) {
            CouponCategory::create([
                'coupon_rule_id' => $rule->id,
                'category_id' => $categoryId,
            ]);
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

        foreach ($request->input('rewards.foc.free_category_ids', []) as $categoryId) {
            FocCategory::create([
                'foc_rule_id' => $rule->id,
                'category_id' => $categoryId,
            ]);
        }
    }
}