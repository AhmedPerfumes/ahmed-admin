<?php

namespace Ahmed\CompleteOrderProducts\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Ahmed\CompleteOrderProducts\Forms\CompleteOrderProductForm;
use Ahmed\CompleteOrderProducts\Models\CompleteOrderProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompleteOrderProductController extends BaseController
{
    public function __construct()
    {
        $this
            ->breadcrumb()
            ->add(trans('plugins/complete-order-products::complete-order-products.name'), route('complete-order-product.index'));
    }

    public function index()
    {
        $this->pageTitle(trans('plugins/complete-order-products::complete-order-products.name'));

        return CompleteOrderProductForm::create()->renderForm();
    }

    public function store(Request $request)
    {
        $productsInput = $request->input('products', '');
        
        $productIds = [];
        if (!empty($productsInput)) {
            if (is_array($productsInput)) {
                $productIds = array_filter(array_map('intval', $productsInput));
            } else {
                $productIds = array_filter(array_map('intval', explode(',', $productsInput)));
            }
        }

        DB::transaction(function () use ($productIds) {
            // Delete removed products
            CompleteOrderProduct::query()
                ->whereNotIn('product_id', $productIds)
                ->delete();

            // Insert or update remaining products with their order index
            foreach ($productIds as $orderIndex => $productId) {
                CompleteOrderProduct::query()->updateOrCreate(
                    ['product_id' => $productId],
                    [
                        'order_index' => $orderIndex,
                        'status' => 'published',
                    ]
                );
            }
        });

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('complete-order-product.index'))
            ->setMessage(trans('core/base::notices.update_success_message'));
    }
}
