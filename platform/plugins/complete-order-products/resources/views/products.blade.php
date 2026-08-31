<div class="mb-3">
    <p class="text-muted small mb-3">
        Search and select multiple products below. These products will appear in the <strong>"Complete your order with"</strong> slider on single product pages. You can remove items anytime by clicking the <strong>&times;</strong> button.
    </p>
</div>

<x-plugins-ecommerce::box-search-advanced
    type="product"
    :placeholder="trans('plugins/ecommerce::products.search_products')"
    :shown="$products->isNotEmpty()"
    :search-target="route('products.get-list-product-for-search')"
>
    <input
        name="products"
        type="hidden"
        value="@if ($products->isNotEmpty()) {{ implode(',', $products->pluck('id')->all()) }} @endif"
    />

    <x-slot:items>
        @include('plugins/ecommerce::products.partials.selected-products-list', [
            'products' => $products ?: collect(),
            'includeVariation' => false,
        ])
    </x-slot:items>
</x-plugins-ecommerce::box-search-advanced>

<x-core::custom-template id="selected_product_list_template">
    <div class="list-group-item" data-product-id="__id__">
        <div class="row align-items-center">
            <div class="col-auto">
                <span
                    class="avatar"
                    style="background-image: url('__image__')"
                ></span>
            </div>
            <div class="col text-truncate">
                <a href="__url__" class="text-body d-block" target="_blank">__name__</a>
                <div class="text-secondary text-truncate">
                    __attributes__
                </div>
            </div>
            <div class="col-auto">
                <a
                    href="javascript:void(0)"
                    data-bb-toggle="product-delete-item"
                    data-bb-target="__id__"
                    class="text-decoration-none list-group-item-actions btn-trigger-remove-selected-product"
                    title="{{ trans('plugins/ecommerce::products.delete') }}"
                >
                    <x-core::icon name="ti ti-x" class="text-secondary" />
                </a>
            </div>
        </div>
    </div>
</x-core::custom-template>
