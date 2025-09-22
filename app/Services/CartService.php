<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Promotion;
use Botble\Ecommerce\Models\Tax;
use Botble\Ecommerce\Models\OrderProduct;
use Botble\Slug\Models\Slug;

class CartService
{
    public static function getProductWithDetails($productId, $quantity)
    {
        $tax = Tax::select('percentage')->where('status', 'published')->first();

        $product = DB::table('ec_product_category_product')
            ->select(
                DB::raw('CAST(ec_products.price AS DECIMAL(8,2)) as price'),
                'ec_product_category_product.product_id',
                'ec_products.name as product_name',
                'ec_product_categories.name as category_name',
                'ec_products.image',
                'ec_products.images',
                'ec_product_collections.name as collection_name',
                'ec_products.description',
                'ec_products.quantity as product_qty',
                'ec_products.sale_price'
            )
            ->join('ec_product_categories', 'ec_product_category_product.category_id', '=', 'ec_product_categories.id', 'left')
            ->join('ec_products', 'ec_product_category_product.product_id', '=', 'ec_products.id', 'left')
            ->join('ec_product_collection_products', 'ec_product_collection_products.product_id', '=', 'ec_products.id', 'left')
            ->join('ec_product_collections', 'ec_product_collection_products.product_collection_id', '=', 'ec_product_collections.id', 'left')
            ->where('ec_product_categories.status', 'published')
            ->whereNull('ec_product_collections.name')
            ->where('ec_product_categories.parent_id', 0)
            ->where('ec_products.id', $productId)
            ->first();

        if (!$product) return null;

        // Labels
        $product->labels = DB::table('ec_product_label_products')
            ->select('ec_product_labels.name as label_name', 'ec_product_labels.color as label_color')
            ->join('ec_product_labels', 'ec_product_label_products.product_label_id', '=', 'ec_product_labels.id', 'left')
            ->where('product_id', $product->product_id)
            ->get();

        // Tags
        $product->tags = DB::table('ec_product_tag_product')
            ->join('ec_product_tags', 'ec_product_tag_product.tag_id', '=', 'ec_product_tags.id', 'left')
            ->where('product_id', $product->product_id)
            ->pluck('ec_product_tags.name')
            ->toArray();

        // Subcategory
        $product->subcategory = DB::table('ec_product_categories')
            ->select('name as subcategory_name')
            ->join('ec_product_category_product', 'ec_product_category_product.category_id', '=', 'ec_product_categories.id', 'left')
            ->where('product_id', $product->product_id)
            ->where('ec_product_categories.parent_id', '!=', 0)
            ->first();

        // Sales
        $total_sales = OrderProduct::select(DB::raw('SUM(qty) as total_sales'))
            ->join('ec_orders', 'ec_order_product.order_id', '=', 'ec_orders.id')
            ->where('product_id', $product->product_id)
            ->groupBy('product_id')
            ->first();
        $product->sales = $total_sales ? intval($total_sales->total_sales) : 0;

        // Permalink
        $product->permalink = Slug::select('key')
            ->where('reference_id', $product->product_id)
            ->where('reference_type', 'Botble\Ecommerce\Models\Product')
            ->first();

        // ✅ Sync price & qty
        $price = number_format($product->price / (1 + ($tax->percentage / 100)), 2, '.', '');
        $newQty = min($quantity, $product->product_qty);
        $product->price = $price;
        $product->quantity = $newQty;

        // Discounts & coupons
        $product->discount = self::getDiscount($product->product_id);
        $product->coupon   = self::getCoupons($product->product_id);

        return $product;
    }

    private static function getDiscount($productId)
    {
        $discountFromDb = Promotion::where('type', 'discount')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->whereHas('discountRules.individualRules', function ($query) use ($productId) {
                $query->where('product_id', $productId);
            })
            ->with(['discountRules.individualRules' => function ($query) use ($productId) {
                $query->where('product_id', $productId)
                    ->select('discount_rule_id', 'product_id', 'value', 'discount_type', 'product_price', 'discount_amount', 'final_price');
            }])
            ->first();

        if ($discountFromDb && $discountFromDb->discountRules->first()) {
            $rule = $discountFromDb->discountRules->first()->individualRules->first();
            return (object) [
                'discount_type' => $rule->discount_type,
                'value'         => $rule->value,
                'discount_amount' => $rule->discount_amount,
                'final_price'   => $rule->final_price,
                'start_date'    => $discountFromDb->start_date,
                'end_date'      => $discountFromDb->end_date,
            ];
        }

        return null;
    }

    private static function getCoupons($productId)
    {
        $coupons = Promotion::where('type', 'coupon')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->whereHas('couponRules.products', function ($query) use ($productId) {
                $query->where('product_id', $productId);
            })
            ->with('couponRules')
            ->get();

        $result = [];
        foreach ($coupons as $promotion) {
            foreach ($promotion->couponRules as $rule) {
                if ($rule->coupon_code) {
                    $result[strtolower($rule->coupon_code)] = [
                        'code'       => strtolower($rule->coupon_code),
                        'value'      => $rule->percentage,
                        'start_date' => $promotion->start_date,
                        'end_date'   => $promotion->end_date,
                    ];
                }
            }
        }
        return $result;
    }
}
