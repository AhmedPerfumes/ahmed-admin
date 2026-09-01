<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Botble\SimpleSlider\Models\SimpleSlider;
use Botble\SimpleSlider\Models\SimpleSliderItem;
use Illuminate\Http\Request;

class SliderApiController extends Controller
{
    /**
     * Get a specific slider and its items by Slider ID.
     */
    public function getSliderById($id)
    {
        $slider = SimpleSlider::where('id', $id)->where('status', 'published')->first();

        if (!$slider) {
            return response()->json([
                'status' => false,
                'message' => 'Slider not found or not published',
                'data' => null
            ], 404);
        }

        $items = SimpleSliderItem::select('id', 'simple_slider_id', 'title', 'title_ar', 'image', 'mobile_image', 'link', 'description', 'description_ar', 'sub_title', 'sub_title_ar', 'season', 'season_ar', 'type', 'color', 'order')
            ->where('simple_slider_id', $id)
            ->orderBy('order', 'asc')
            ->get();

        $desktopSliders = $items->where('type', 'desktop')->values();
        $mobileSliders = $items->where('type', 'mobile')->values();

        $data = [
            'id' => $slider->id,
            'name' => $slider->name,
            'key' => $slider->key,
            'description' => $slider->description,
            'desktop_sliders' => $desktopSliders,
            'mobile_sliders' => $mobileSliders,
            'items' => $items,
        ];

        return response()->json([
            'status' => true,
            'data' => $data
        ])->header('Cache-Control', 'no-cache, private');
    }

    /**
     * Get a slider and its items by its key (e.g., checkout-promo).
     */
    public function getSliderByKey($key)
    {
        $slider = SimpleSlider::where('key', $key)->where('status', 'published')->first();

        if (!$slider) {
            return response()->json([
                'status' => false,
                'message' => 'Slider not found or not published',
                'data' => null
            ], 404);
        }

        $items = SimpleSliderItem::select('id', 'simple_slider_id', 'title', 'title_ar', 'image', 'mobile_image', 'link', 'description', 'description_ar', 'sub_title', 'sub_title_ar', 'season', 'season_ar', 'type', 'color', 'order')
            ->where('simple_slider_id', $slider->id)
            ->orderBy('order', 'asc')
            ->get();

        $data = [
            'id' => $slider->id,
            'name' => $slider->name,
            'key' => $slider->key,
            'description' => $slider->description,
            'desktop_sliders' => $items->where('type', 'desktop')->values(),
            'mobile_sliders' => $items->where('type', 'mobile')->values(),
            'items' => $items,
        ];

        return response()->json([
            'status' => true,
            'data' => $data
        ])->header('Cache-Control', 'no-cache, private');
    }

    /**
     * Get multiple sliders by an array or comma-separated list of IDs.
     */
    public function getSlidersByIds(Request $request)
    {
        $ids = $request->input('ids', []);
        if (is_string($ids)) {
            $ids = array_filter(array_map('trim', explode(',', $ids)));
        }

        if (empty($ids)) {
            return response()->json([
                'status' => false,
                'message' => 'No slider IDs provided',
                'data' => []
            ], 400);
        }

        $sliders = SimpleSlider::whereIn('id', $ids)->where('status', 'published')->get();
        $items = SimpleSliderItem::select('id', 'simple_slider_id', 'title', 'title_ar', 'image', 'mobile_image', 'link', 'description', 'description_ar', 'sub_title', 'sub_title_ar', 'season', 'season_ar', 'type', 'color', 'order')
            ->whereIn('simple_slider_id', $ids)
            ->orderBy('order', 'asc')
            ->get();

        $result = [];
        foreach ($sliders as $slider) {
            $sliderItems = $items->where('simple_slider_id', $slider->id);
            $result[$slider->id] = [
                'id' => $slider->id,
                'name' => $slider->name,
                'key' => $slider->key,
                'description' => $slider->description,
                'desktop_sliders' => $sliderItems->where('type', 'desktop')->values(),
                'mobile_sliders' => $sliderItems->where('type', 'mobile')->values(),
                'items' => $sliderItems->values(),
            ];
        }

        return response()->json([
            'status' => true,
            'data' => $result
        ])->header('Cache-Control', 'no-cache, private');
    }
}
