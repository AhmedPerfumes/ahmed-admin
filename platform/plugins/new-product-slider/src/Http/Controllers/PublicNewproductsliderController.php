<?php

namespace Ahmed\NewProductSlider\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Ahmed\NewProductSlider\Models\Newproductslider;
use Illuminate\Http\JsonResponse;
use RvMedia;

class PublicNewproductsliderController extends BaseController
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $lang = $request->input('lang', 'en');

        $sliders = Newproductslider::with(['product'])
            ->where('status', 'published')
            ->orderBy('order_index', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();

        $data = $sliders->map(function ($slider) use ($lang) {
            $productData = null;
            if ($slider->product && $slider->product->id) {
                // Fetch category
                $category = \Illuminate\Support\Facades\DB::table('ec_product_categories')
                    ->join('ec_product_category_product', 'ec_product_category_product.category_id', '=', 'ec_product_categories.id')
                    ->where('ec_product_category_product.product_id', $slider->product->id)
                    ->where('ec_product_categories.parent_id', 0)
                    ->select('ec_product_categories.name')
                    ->first();
                $categoryName = $category ? $category->name : '';

                // Fetch subcategory
                $subcategory = \Illuminate\Support\Facades\DB::table('ec_product_categories')
                    ->join('ec_product_category_product', 'ec_product_category_product.category_id', '=', 'ec_product_categories.id')
                    ->where('ec_product_category_product.product_id', $slider->product->id)
                    ->where('ec_product_categories.parent_id', '!=', 0)
                    ->select('ec_product_categories.name')
                    ->first();
                $subcategoryName = $subcategory ? $subcategory->name : '';

                // Fetch active coupons
                $coupons = \App\Models\Promotion::where('type', 'coupon')
                    ->where('start_date', '<=', now())
                    ->where('end_date', '>=', now())
                    ->whereHas('couponRules.products', function ($query) use ($slider) {
                        $query->where('product_id', $slider->product->id);
                    })
                    ->with(['couponRules' => function ($query) use ($slider) {
                        $query->whereNotNull('coupon_code')
                            ->select('id', 'promotion_id', 'coupon_code', 'percentage')
                            ->with(['products' => function ($subQuery) use ($slider) {
                                $subQuery->where('product_id', $slider->product->id)
                                        ->select('id', 'coupon_rule_id', 'product_id');
                            }]);
                    }])
                    ->get();

                $couponDataArray = [];
                foreach ($coupons as $promotion) {
                    foreach ($promotion->couponRules as $couponRule) {
                        if ($couponRule->coupon_code && $couponRule->products->isNotEmpty()) {
                            $couponDataArray[strtolower($couponRule->coupon_code)] = [
                                'code' => strtolower($couponRule->coupon_code),
                                'value' => intval($couponRule->percentage),
                                'start_date' => $promotion->start_date->format('Y-m-d H:i:s'),
                                'end_date' => $promotion->end_date->format('Y-m-d H:i:s'),
                            ];
                        }
                    }
                }

                $productData = [
                    'product_id' => $slider->product->id,
                    'product_name' => $slider->product->name,
                    'price' => $slider->product->price,
                    'sale_price' => $slider->product->sale_price,
                    'image' => $slider->product->image,
                    'quantity' => 1,
                    'product_qty' => $slider->product->quantity,
                    'maximum_order_quantity' => $slider->product->maximum_order_quantity,
                    'collection_name' => '', // You might want to fetch this from a relationship if needed
                    'category_name' => $categoryName,
                    'subcategory_name' => $subcategoryName,
                    'coupon' => $couponDataArray,
                ];
            }

            return [
                'id' => $slider->id,
                'name' => ($lang === 'ar' && $slider->name_ar) ? $slider->name_ar : $slider->name,
                'category' => ($lang === 'ar' && $slider->category_ar) ? $slider->category_ar : $slider->category,
                'desc' => ($lang === 'ar' && $slider->desc_ar) ? $slider->desc_ar : $slider->desc,
                'productImg' => $slider->product_img ? RvMedia::getImageUrl($slider->product_img) : null,
                'noteImg' => $slider->note_img ? RvMedia::getImageUrl($slider->note_img) : null,
                'theme' => [
                    'bg' => $slider->theme_bg,
                    'roman' => $slider->theme_roman,
                    'accent' => $slider->theme_accent,
                    'glow' => $slider->theme_glow,
                ],
                'link' => $slider->link,
                'product' => $productData,
            ];
        });

        return response()->json([
            'error' => false,
            'data' => $data,
            'message' => 'Sliders retrieved successfully',
        ]);
    }
}
