<?php

namespace Ahmed\CompleteOrderProducts\Http\Controllers;

use App\Http\Controllers\Controller;
use Ahmed\CompleteOrderProducts\Models\CompleteOrderProduct;
use App\Models\Promotion;
use Botble\Ecommerce\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PublicCompleteOrderProductController extends Controller
{
    public function index(): JsonResponse
    {
        $items = CompleteOrderProduct::query()
            ->where('status', 'published')
            ->orderBy('order_index', 'asc')
            ->orderBy('id', 'desc')
            ->with(['product'])
            ->get();

        $now = now();
        $products = [];

        foreach ($items as $item) {
            $product = $item->product;
            if (!$product || $product->status != 'published') {
                continue;
            }

            // 1. Get labels
            $labels = DB::table('ec_product_label_products')
                ->select('ec_product_labels.name as label_name', 'ec_product_labels.color as label_color')
                ->join('ec_product_labels', 'ec_product_label_products.product_label_id', '=', 'ec_product_labels.id', 'left')
                ->where('product_id', $product->id)
                ->get();

            // 2. Get tags
            $tags = DB::table('ec_product_tag_product')
                ->join('ec_product_tags', 'ec_product_tag_product.tag_id', '=', 'ec_product_tags.id', 'left')
                ->where('product_id', $product->id)
                ->pluck('ec_product_tags.name')
                ->toArray();

            // 3. Get categories
            $category = DB::table('ec_product_category_product')
                ->select('ec_product_categories.name as category_name')
                ->join('ec_product_categories', 'ec_product_category_product.category_id', '=', 'ec_product_categories.id', 'left')
                ->where('product_id', $product->id)
                ->where('ec_product_categories.parent_id', 0)
                ->first();

            $subcategory = DB::table('ec_product_categories')
                ->select('name as subcategory_name')
                ->join('ec_product_category_product', 'ec_product_category_product.category_id', '=', 'ec_product_categories.id', 'left')
                ->where('product_id', $product->id)
                ->where('ec_product_categories.parent_id', '!=', 0)
                ->first();

            // 4. Resolve Active Promotion Discount
            $discount = null;

            // 4a. Check Individual rule discount
            $individualDiscount = Promotion::where('type', 'discount')
                ->where('start_date', '<=', $now)
                ->where('end_date', '>=', $now)
                ->whereHas('discountRules', function ($query) {
                    $query->where('apply_to', 'individual');
                })
                ->whereHas('discountRules.individualRules', function ($query) use ($product) {
                    $query->where('product_id', $product->id);
                })
                ->with([
                    'discountRules' => function ($query) {
                        $query->where('apply_to', 'individual')
                            ->select('id', 'promotion_id', 'apply_to');
                    },
                    'discountRules.individualRules' => function ($query) use ($product) {
                        $query->where('product_id', $product->id)
                            ->select('discount_rule_id', 'product_id', 'value', 'discount_type', 'product_price', 'discount_amount', 'final_price');
                    }
                ])
                ->first();

            if ($individualDiscount) {
                $dRule = $individualDiscount->discountRules->first();
                $iRule = $dRule ? $dRule->individualRules->first() : null;
                if ($iRule) {
                    $discount = (object)[
                        'value' => (float)$iRule->value,
                        'apply_to' => $dRule->apply_to,
                        'discount_type' => $iRule->discount_type,
                        'product_price' => $iRule->product_price ? (float)$iRule->product_price : (float)$product->price,
                        'discount_amount' => $iRule->discount_amount ? (float)$iRule->discount_amount : null,
                        'final_price' => $iRule->final_price ? (float)$iRule->final_price : null,
                        'start_date' => $individualDiscount->start_date->format('Y-m-d H:i:s'),
                        'end_date' => $individualDiscount->end_date->format('Y-m-d H:i:s'),
                    ];
                }
            }

            // 4b. Check Category / Group rule discount if not individual
            if (!$discount) {
                $groupDiscount = Promotion::where('type', 'discount')
                    ->where('start_date', '<=', $now)
                    ->where('end_date', '>=', $now)
                    ->whereHas('discountRules', function ($query) {
                        $query->where('apply_to', '!=', 'individual');
                    })
                    ->whereHas('discountRules.products', function ($query) use ($product) {
                        $query->where('product_id', $product->id);
                    })
                    ->with([
                        'discountRules' => function ($query) {
                            $query->where('apply_to', '!=', 'individual')
                                ->select('id', 'promotion_id', 'percentage', 'apply_to');
                        }
                    ])
                    ->first();

                if ($groupDiscount) {
                    $dRule = $groupDiscount->discountRules->first();
                    if ($dRule) {
                        $pct = (float)$dRule->percentage;
                        $finalPrice = (float)$product->price - ((float)$product->price * $pct / 100);
                        $discount = (object)[
                            'value' => $pct,
                            'apply_to' => $dRule->apply_to,
                            'discount_type' => 'percent',
                            'product_price' => (float)$product->price,
                            'discount_amount' => ((float)$product->price * $pct / 100),
                            'final_price' => $finalPrice,
                            'start_date' => $groupDiscount->start_date->format('Y-m-d H:i:s'),
                            'end_date' => $groupDiscount->end_date->format('Y-m-d H:i:s'),
                        ];
                    }
                }
            }

            $products[] = [
                'id' => $item->id,
                'product_id' => $product->id,
                'product_name' => $item->custom_title ?: $product->name,
                'product_name_ar' => $item->custom_title_ar ?: ($product->name_ar ?: $product->name),
                'price' => number_format((float)$product->price, 2, '.', ''),
                'sale_price' => $product->sale_price ? number_format((float)$product->sale_price, 2, '.', '') : null,
                'discount' => $discount,
                'image' => $product->image,
                'images' => $product->images,
                'description' => $product->description,
                'product_qty' => $product->quantity,
                'labels' => $labels,
                'tags' => $tags,
                'category_name' => $category ? $category->category_name : 'Fragrances',
                'subcategory' => $subcategory,
                'order_index' => $item->order_index,
            ];
        }

        return response()->json([
            'status' => true,
            'data' => $products,
        ])
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, s-maxage=0')
        ->header('Pragma', 'no-cache')
        ->header('Expires', '0');
    }
}
