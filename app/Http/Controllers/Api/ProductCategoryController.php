<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Botble\Ecommerce\Models\ProductCategory;
use Botble\Ecommerce\Models\ShippingRule;
use Botble\Ecommerce\Models\Tax;
use Botble\Ecommerce\Models\Currency;
use Botble\SimpleSlider\Models\SimpleSliderItem;
use Botble\Page\Models\Page;
use Botble\Media\Models\MediaFile;
use Illuminate\Support\Facades\DB;
use Botble\Ecommerce\Models\ProductAttribute;

class ProductCategoryController extends Controller
{
    public function getProductCategories(Request $request)
    {
        // $customer = Auth::guard('api')->user();

        // if (!$customer) {
        //     return response()->json(['message' => 'Unauthorized'], 401);
        // }
        // $base_url = 'https://phpstack-667016-4904984.cloudwaysapps.com/public/storage/';
        $base_url = 'http://localhost/ahmed-admin/public/storage/';
        $productCategories = ProductCategory::select('id', 'name', 'image', 'icon_image', 'menu_image2')->where('status', 'published')->where('parent_id', 0)->get();

        foreach ($productCategories as $category) {
            // $category->image = $base_url.$category->image;
            // $category->icon_image = $base_url.$category->icon_image;
            // $category->menu_image2 = $base_url.$category->menu_image2;
            $category->productSubCategories = ProductCategory::select('id', 'name', 'image')->where('parent_id', $category->id)->where('status', 'published')->get();
        }

        // foreach ($productCategories as $category) {
        //     foreach ($category->productSubCategories as $val) {
        //         $val->image = $base_url.$val->image;
        //     }
        // }
        // $tax = Tax::select('percentage')->where('status', 'published')->first();
        // $shipping_service_charges = ShippingRule::select('price')->get();

        // return response()->json(['productCategories' => $productCategories, 'tax' => $tax, 'shipping_service_charges' => $shipping_service_charges]);
        $response = response()->json($productCategories)->header('Cache-Control', 'public, max-age=86400, s-maxage=172800') // Cache 1 Day in the browser, 2 Days at Cloudflare
        ->setEtag(md5(json_encode($productCategories)));

        if ($response->isNotModified(request())) {
            return $response;
        }

        return $response;
    }

    public function getProductCategoriesTemp(Request $request)
    {
        // $customer = Auth::guard('api')->user();

        // if (!$customer) {
        //     return response()->json(['message' => 'Unauthorized'], 401);
        // }
        // $base_url = 'https://phpstack-667016-4904984.cloudwaysapps.com/public/storage/';
        $base_url = 'http://localhost/ahmed-admin/public/storage/';
        $productCategories = ProductCategory::select('id', 'name', 'image', 'icon_image', 'menu_image2')->where('status', 'published')->where('parent_id', 0)->get();

        foreach ($productCategories as $category) {
            // $category->image = $base_url.$category->image;
            // $category->icon_image = $base_url.$category->icon_image;
            // $category->menu_image2 = $base_url.$category->menu_image2;
            $category->productSubCategories = ProductCategory::select('id', 'name', 'image')->where('parent_id', $category->id)->where('status', 'published')->get();
        }

        // foreach ($productCategories as $category) {
        //     foreach ($category->productSubCategories as $val) {
        //         $val->image = $base_url.$val->image;
        //     }
        // }
        $tax = Tax::select('percentage')->where('status', 'published')->first();
        $shipping_service_charges = ShippingRule::select('price')->get();
        $currency = Currency::select('symbol')->where('is_default', 1)->first();
        $home_sliders = SimpleSliderItem::select('title', 'image', 'link', 'order', 'sub_title', 'season', 'type', 'color')->where('type', 'desktop')->orderBy('order', 'asc')->get();
        $home_mobile_sliders = SimpleSliderItem::select('title', 'image', 'link', 'order', 'sub_title', 'season', 'type', 'color')->where('type', 'mobile')->orderBy('order', 'asc')->get();
        // $dynamic_sections= DB::table('dynamic_sections')->select('heading', 'description','link','image','video1','video2')->get();
        $pop_up = Page::select('name','content','description','image','mobile_image','link')->where('template', 'full-width')->where('status', 'published')->get();
        $top_header=ProductAttribute::select('title','color')->get();
        $sale_section = Page::select('name','content','description','image','mobile_image','link')->where('template', 'homepage')->where('status', 'published')->get();
        $shop_pop_up = Page::select('name','content','description','image','mobile_image','link')->where('template', 'coming-soon')->where('status', 'published')->get();
        
        $response = response()->json(['productCategories' => $productCategories, 'tax' => $tax, 'shipping_service_charges' => $shipping_service_charges, 'currency' => $currency, 'home_sliders' => $home_sliders, 'home_mobile_sliders' => $home_mobile_sliders, 'top_header' => $top_header, 'pop_up' => $pop_up, 'sale_section' => $sale_section, 'shop_pop_up' => $shop_pop_up])->header('Cache-Control', 'public, max-age=86400, s-maxage=172800') // Cache 1 Day in the browser, 2 Days at Cloudflare
        ->setEtag(md5(json_encode(['productCategories' => $productCategories, 'tax' => $tax, 'shipping_service_charges' => $shipping_service_charges, 'currency' => $currency, 'home_sliders' => $home_sliders, 'home_mobile_sliders' => $home_mobile_sliders,  'top_header' => $top_header, 'pop_up' => $pop_up, 'sale_section' => $sale_section, 'shop_pop_up' => $shop_pop_up])));

        if ($response->isNotModified(request())) {
            return $response;
        }

        return $response;

        // return response()->json($productCategories);
    }

    public function getProductCategorySEO(Request $request)
    {
        $category = $request['category'];
        $subCategory = $request['subCategory'];
        // $product = $request['product'];

        if (!isset($category) || empty($category)) {
            return response()->json([
                'message'       => 'Kindly Provide Category',
            ]);
        }

        if(!isset($subCategory) || empty($subCategory)) {
            $cat =  DB::table('ec_product_categories')
                // ->join ('ec_product_category_product', 'ec_product_category_product.product_id', '=', 'ec_products.id', 'left')
                // ->join('ec_product_collection_products', 'ec_product_collection_products.product_id', '=', 'ec_products.id', 'left')
                // ->join('ec_product_collections', 'ec_product_collection_products.product_collection_id', '=', 'ec_product_collections.id', 'left')
                // ->join('ec_product_label_products', 'ec_product_label_products.product_id', '=', 'ec_products.id', 'left')
                // ->join('ec_product_labels', 'ec_product_label_products.product_label_id', '=', 'ec_product_labels.id', 'left')
                // ->join ('ec_product_categories', 'ec_product_categories.id', '=', 'ec_product_category_product.category_id', 'left')
                ->join ('meta_boxes', 'meta_boxes.reference_id', '=', 'ec_product_categories.id', 'left')
                // ->select(DB::raw("REGEXP_REPLACE(REPLACE(REPLACE(ec_products.name, ' &amp; ', '&'), '&', ' '),'[^a-zA-Z0-9-]', '')"))
                ->select('meta_value')
                // ->where('ec_products.status', 'published')
                ->where(DB::raw("REGEXP_REPLACE(REPLACE(REPLACE(ec_product_categories.name, '&amp;', '&'), '&', ' '),'[^a-zA-Z0-9]', '')"), '=', implode('', explode(' ', $category)))
                // ->where('ec_product_categories.name', $category)
                ->where('meta_key', 'seo_meta')
                // ->orderBy('ec_products.id', 'desc')
                ->where('reference_type', 'Botble\Ecommerce\Models\ProductCategory')
                ->where('parent_id', 0)
                ->first();
                // print_r($prod);die();
            $response = response()->json($cat)->header('Cache-Control', 'public, max-age=86400, s-maxage=172800') // Cache 1 Day in the browser, 2 Days at Cloudflare
            ->setEtag(md5(json_encode($cat)));

            if ($response->isNotModified(request())) {
                return $response;
            }

            return $response;
        } else {
            $subCat =  DB::table('ec_product_categories')
                // ->join ('ec_product_category_product', 'ec_product_category_product.product_id', '=', 'ec_products.id', 'left')
                // ->join('ec_product_collection_products', 'ec_product_collection_products.product_id', '=', 'ec_products.id', 'left')
                // ->join('ec_product_collections', 'ec_product_collection_products.product_collection_id', '=', 'ec_product_collections.id', 'left')
                // ->join('ec_product_label_products', 'ec_product_label_products.product_id', '=', 'ec_products.id', 'left')
                // ->join('ec_product_labels', 'ec_product_label_products.product_label_id', '=', 'ec_product_labels.id', 'left')
                // ->join ('ec_product_categories', 'ec_product_categories.id', '=', 'ec_product_category_product.category_id', 'left')
                ->join ('meta_boxes', 'meta_boxes.reference_id', '=', 'ec_product_categories.id', 'left')
                // ->select(DB::raw("REGEXP_REPLACE(REPLACE(REPLACE(ec_products.name, ' &amp; ', '&'), '&', ' '),'[^a-zA-Z0-9-]', '')"))
                ->select('meta_value')
                // ->where('ec_products.status', 'published')
                ->where(DB::raw("REGEXP_REPLACE(REPLACE(REPLACE(ec_product_categories.name, '&amp;', '&'), '&', ' '),'[^a-zA-Z0-9]', '')"), '=', implode('', explode(' ', $subCategory)))
                // ->where('ec_product_categories.name', $category)
                ->where('meta_key', 'seo_meta')
                // ->orderBy('ec_products.id', 'desc')
                ->where('reference_type', 'Botble\Ecommerce\Models\ProductCategory')
                ->where('parent_id', '!=', 0)
                ->first();
                // print_r($prod);die();
            $response = response()->json($subCat)->header('Cache-Control', 'public, max-age=86400, s-maxage=172800') // Cache 1 Day in the browser, 2 Days at Cloudflare
            ->setEtag(md5(json_encode($subCat)));

            if ($response->isNotModified(request())) {
                return $response;
            }

            return $response;
        }
    }
}
