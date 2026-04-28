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
