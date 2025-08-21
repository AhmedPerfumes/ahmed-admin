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
use Illuminate\Validation\Rule;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Models\Customer;
use App\Tables\PromotionTable;
use Carbon\Carbon;

class PromotionController extends Controller
{
    public function index()
    {
        $promotions = Promotion::orderBy('start_date', 'desc')->get();
        return view('promotions.index', compact('promotions'));
    }

    public function create()
    {
        $products = Product::select('id', 'name', 'price')->get()->toArray();
        $customers = Customer::select('id', 'name')->get()->toArray();
        $today = Carbon::today();
        $discountedProductIds = DB::table('promotions')
            ->where('promotions.end_date', '>=', $today)
            ->where('promotions.type', 'coupon')
            ->join('coupon_rules', 'promotions.id', '=', 'coupon_rules.promotion_id')
            ->join('coupon_products', 'coupon_rules.id', '=', 'coupon_products.coupon_rule_id')
            ->select('coupon_products.product_id')
            ->union(
                DB::table('promotions')
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
        return view('promotions.create', compact('products', 'customers', 'discountedProductIds'));
    }

    public function store(Request $request)
    {
        $today = Carbon::today();
        $discountedProductIds = DB::table('promotions')
           ->where('promotions.end_date', '>=', $today)
            ->where('promotions.type', 'coupon')
            ->join('coupon_rules', 'promotions.id', '=', 'coupon_rules.promotion_id')
            ->join('coupon_products', 'coupon_rules.id', '=', 'coupon_products.coupon_rule_id')
            ->select('coupon_products.product_id')
            ->union(
                DB::table('promotions')
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

        if ($applyTo === 'group') {
            foreach ($request->input('conditions.coupon.group_product_ids', []) as $productId) {
                CouponProduct::create([
                    'coupon_rule_id' => $rule->id,
                    'product_id' => $productId,
                ]);
            }
        } elseif ($applyTo === 'customer') {
            $customerIds = $request->input('conditions.coupon.customer_ids', []);
            $rule->customers()->sync($customerIds);
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

    public function edit(Promotion $promotion)
    {
        $products = Product::select('id', 'name', 'price')->get()->toArray();
        $customers = Customer::select('id', 'name')->get()->toArray();
        $today = Carbon::today();

        // Get IDs of products associated with active coupon or discount promotions (excluding the current promotion)
        $discountedProductIds = DB::table('promotions')
            ->where('promotions.end_date', '>=', $today)
            ->where('promotions.id', '!=', $promotion->id)
            ->whereIn('promotions.type', ['coupon', 'discount'])
            ->join('coupon_rules', function ($join) {
                $join->on('promotions.id', '=', 'coupon_rules.promotion_id')
                     ->where('promotions.type', 'coupon');
            })
            ->join('coupon_products', 'coupon_rules.id', '=', 'coupon_products.coupon_rule_id')
            ->select('coupon_products.product_id')
            ->union(
                DB::table('promotions')
                    ->where('promotions.end_date', '>=', $today)
                    ->where('promotions.id', '!=', $promotion->id)
                    ->where('promotions.type', 'discount')
                    ->join('discount_rules', 'promotions.id', '=', 'discount_rules.promotion_id')
                    ->join('discount_products', 'discount_rules.id', '=', 'discount_products.discount_rule_id')
                    ->select('discount_products.product_id')
            )
            ->pluck('product_id')
            ->unique()
            ->values()
            ->toArray();

        // Load associated rules based on promotion type
        $promotionData = [];
        switch ($promotion->type) {
            case 'bogo':
                $promotionData['bogo_rules'] = BogoRule::where('promotion_id', $promotion->id)->get()->toArray();
                break;
            case 'buy_x_get_y':
                $promotionData['buy_x_get_y_rule'] = BuyXGetYRule::where('promotion_id', $promotion->id)->first();
                $promotionData['buy_products'] = BuyXGetYProduct::where('rule_id', $promotionData['buy_x_get_y_rule']->id)
                    ->where('type', 'buy')
                    ->pluck('product_id')
                    ->toArray();
                $promotionData['free_products'] = BuyXGetYProduct::where('rule_id', $promotionData['buy_x_get_y_rule']->id)
                    ->where('type', 'free')
                    ->pluck('product_id')
                    ->toArray();
                break;
            case 'discount':
                $promotionData['discount_rule'] = DiscountRule::where('promotion_id', $promotion->id)->first();
                if ($promotionData['discount_rule']->apply_to === 'individual') {
                    $promotionData['individual_rules'] = DiscountIndividualRule::where('discount_rule_id', $promotionData['discount_rule']->id)
                        ->get()
                        ->toArray();
                } elseif ($promotionData['discount_rule']->apply_to === 'group') {
                    $promotionData['group_products'] = DiscountProduct::where('discount_rule_id', $promotionData['discount_rule']->id)
                        ->pluck('product_id')
                        ->toArray();
                }
                break;
            case 'coupon':
                $promotionData['coupon_rule'] = CouponRule::where('promotion_id', $promotion->id)->first();
                if ($promotionData['coupon_rule']->apply_to === 'group') {
                    $promotionData['group_products'] = CouponProduct::where('coupon_rule_id', $promotionData['coupon_rule']->id)
                        ->pluck('product_id')
                        ->toArray();
                } elseif ($promotionData['coupon_rule']->apply_to === 'customer') {
                    $promotionData['customers'] = CouponCustomer::where('coupon_rule_id', $promotionData['coupon_rule']->id)
                        ->pluck('customer_id')
                        ->toArray();
                }
                break;
            case 'foc':
                $promotionData['foc_rule'] = FocRule::where('promotion_id', $promotion->id)->first();
                $promotionData['free_products'] = FocProduct::where('foc_rule_id', $promotionData['foc_rule']->id)
                    ->pluck('product_id')
                    ->toArray();
                break;
        }

        // Pass the promotion type to the view to restrict form fields
        return view('promotions.create', compact('promotion', 'products', 'customers', 'discountedProductIds', 'promotionData'));
    }

    public function update(Request $request, Promotion $promotion)
    {
        $today = Carbon::today();
        $discountedProductIds = DB::table('promotions')
            ->where('promotions.end_date', '>=', $today)
            ->where('promotions.id', '!=', $promotion->id)
            ->whereIn('promotions.type', ['coupon', 'discount'])
            ->join('coupon_rules', function ($join) {
                $join->on('promotions.id', '=', 'coupon_rules.promotion_id')
                     ->where('promotions.type', 'coupon');
            })
            ->join('coupon_products', 'coupon_rules.id', '=', 'coupon_products.coupon_rule_id')
            ->select('coupon_products.product_id')
            ->union(
                DB::table('promotions')
                     ->where('promotions.end_date', '>=', $today)
                    ->where('promotions.id', '!=', $promotion->id)
                    ->where('promotions.type', 'discount')
                    ->join('discount_rules', 'promotions.id', '=', 'discount_rules.promotion_id')
                    ->join('discount_products', 'discount_rules.id', '=', 'discount_products.discount_rule_id')
                    ->select('discount_products.product_id')
            )
            ->pluck('product_id')
            ->unique()
            ->values()
            ->toArray();

        // Validate that the promotion type cannot be changed
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in([$promotion->type])], // Restrict type to current promotion type
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        try {
            DB::beginTransaction();

            // Update promotion
            $promotion->update([
                'name' => $request->name,
                'type' => $promotion->type, // Ensure type remains unchanged
                'description' => $request->description,
                'start_date' => Carbon::parse($request->start_date)->startOfDay(),
                'end_date' => Carbon::parse($request->end_date)->endOfDay(),
            ]);

            // Delete existing rules and update only the relevant ones
            switch ($promotion->type) {
                case 'bogo':
                    BogoRule::where('promotion_id', $promotion->id)->delete();
                    $this->storeBogoRules($promotion, $request);
                    break;
                case 'buy_x_get_y':
                    BuyXGetYRule::where('promotion_id', $promotion->id)->delete();
                    $this->storeBuyXGetYRules($promotion, $request);
                    break;
                case 'discount':
                    DiscountRule::where('promotion_id', $promotion->id)->delete();
                    $this->storeDiscountRules($promotion, $request, $discountedProductIds);
                    break;
                case 'coupon':
                    CouponRule::where('promotion_id', $promotion->id)->delete();
                    $this->storeCouponRules($promotion, $request, $discountedProductIds);
                    break;
                case 'foc':
                    FocRule::where('promotion_id', $promotion->id)->delete();
                    $this->storeFocRules($promotion, $request);
                    break;
            }

            DB::commit();
            return redirect()->route('promotions.index')->with('success', 'Promotion updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Failed to update promotion: ' . $e->getMessage()]);
        }
    }
 public function bulkDelete(Request $request)
{
    $ids = $request->input('ids', []);

    if (!empty($ids)) {
        Promotion::whereIn('id', $ids)->delete();
    }

    return redirect()->route('promotions.index')
                     ->with('success', 'Selected promotions deleted successfully.');
}


   public function destroy($id)
{
    $promotion = Promotion::findOrFail($id);
    $promotion->delete();

    return redirect()->route('promotions.index')
                     ->with('success', 'Promotion deleted successfully.');
}
}