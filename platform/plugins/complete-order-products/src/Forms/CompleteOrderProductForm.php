<?php

namespace Ahmed\CompleteOrderProducts\Forms;

use Botble\Base\Facades\Assets;
use Botble\Base\Forms\FormAbstract;
use Botble\Ecommerce\Models\Product;
use Ahmed\CompleteOrderProducts\Models\CompleteOrderProduct;

class CompleteOrderProductForm extends FormAbstract
{
    public function setup(): void
    {
        Assets::addScriptsDirectly('vendor/core/plugins/ecommerce/js/edit-product-collection.js')
            ->addStylesDirectly(['vendor/core/plugins/ecommerce/css/ecommerce.css']);

        $productIds = CompleteOrderProduct::query()
            ->orderBy('order_index', 'asc')
            ->orderBy('id', 'asc')
            ->pluck('product_id')
            ->toArray();

        $selectedProducts = collect();
        if (!empty($productIds)) {
            $products = Product::query()->whereIn('id', $productIds)->get();
            $selectedProducts = $products->sortBy(function ($product) use ($productIds) {
                return array_search($product->id, $productIds);
            });
        }

        $this
            ->setUrl(route('complete-order-product.store'))
            ->setMethod('POST')
            ->addMetaBoxes([
                'complete-order-products-box' => [
                    'title' => 'Select Products for "Complete Your Order"',
                    'content' => view('plugins/complete-order-products::products', [
                        'products' => $selectedProducts,
                    ])->render(),
                    'priority' => 0,
                ],
            ]);
    }
}
