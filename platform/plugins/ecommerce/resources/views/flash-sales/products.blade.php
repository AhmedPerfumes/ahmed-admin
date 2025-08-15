<div class="form-group mb-3">
    <label class="form-label">{{ trans('plugins/ecommerce::products.product_filter') }}</label>
    <select class="form-select" id="product-filter">
        <option value="products">{{ trans('plugins/ecommerce::products.products') }}</option>
        <option value="all-products">{{ trans('plugins/ecommerce::products.all_products') }}</option>
        <option value="category">{{ trans('plugins/ecommerce::products.product_categories') }}</option>
    </select>
</div>

<x-plugins-ecommerce::box-search-advanced
    type="product"
    :placeholder="trans('plugins/ecommerce::products.search_products')"
    :shown="$products->isNotEmpty()"
    template="selected_product_list_template"
>
    <input
        name="products"
        type="hidden"
        value="@if ($flashSale->id) {{ implode(',', array_filter($flashSale->products()->allRelatedIds()->toArray())) }} @endif"
    />

    <x-slot:items>
        @foreach ($products as $index => $product)
            <div class="list-group-item" data-product-id="{{ $product->id }}">
                <div class="row align-items-center mb-3">
                    <div class="col-auto">
                        <span
                            class="avatar"
                            style="background-image: url('{{ RvMedia::getImageUrl($product->image, 'thumb', false, RvMedia::getDefaultImage()) }}')"
                        ></span>
                    </div>
                    <div class="col text-truncate">
                        <a href="{{ route('products.edit', $product->id) }}" class="text-body d-block" target="_blank">
                            {{ $product->name }} ({{ format_price($product->sale_price ?: $product->price) }})
                        </a>
                    </div>
                    <div class="col-auto">
                        <a
                            href="javascript:void(0)"
                            class="text-decoration-none list-group-item-actions"
                            data-bb-toggle="product-delete-item"
                            data-bb-target="{{ $product->id }}"
                            title="{{ trans('plugins/ecommerce::products.delete') }}"
                        >
                            <x-core::icon name="ti ti-x" class="text-secondary" />
                        </a>
                    </div>
                </div>

                {{-- Price & Discount --}}
                <div>
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group mb-3">
                                <x-core::form.text-input
                                    :label="trans('plugins/ecommerce::products.price')"
                                    class="input-mask-number product-price"
                                    name="products_extra[{{ $index }}][price]"
                                    :data-thousands-separator="EcommerceHelper::getThousandSeparatorForInputMask()"
                                    :data-decimal-separator="EcommerceHelper::getDecimalSeparatorForInputMask()"
                                    :value="$product->pivot->price"
                                    :required="true"
                                />
                            </div>
                        </div>

                        <div class="col-6">
                            <div class="form-group mb-3">
                                <label class="form-label">{{ trans('plugins/ecommerce::products.discount') }}</label>
                                <select class="form-select discount-type" name="products_extra[{{ $index }}][discount_type]">
                                    <option value="percentage" @if ($product->pivot->discount_type === 'percentage') selected @endif>Percentage Discount</option>
                                    <option value="fixed" @if ($product->pivot->discount_type === 'fixed') selected @endif>Fixed Price Discount</option>
                                </select>

                                {{-- Discount Value Input --}}
                                <input
                                    type="number"
                                    class="form-control mt-2 discount-value input-mask-number"
                                    name="products_extra[{{ $index }}][discount_value]"
                                    :data-thousands-separator="EcommerceHelper::getThousandSeparatorForInputMask()"
                                    data-decimal-separator="EcommerceHelper::getDecimalSeparatorForInputMask()"
                                    value="{{ $product->pivot->discount_value ?? 0 }}"
                                    min="0"
                                    step="0.01"
                                    placeholder="Enter discount value"
                                />

                                {{-- Calculated Discount / Difference --}}
                                <input
                                    type="text"
                                    class="form-control mt-2 calculated-discount"
                                    placeholder="Calculated Value"
                                    readonly
                                />
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        @endforeach
    </x-slot:items>
</x-plugins-ecommerce::box-search-advanced>

@push('footer')
<x-core::custom-template id="selected_product_list_template">
    <div class="list-group-item" data-product-id="__id__">
        <div class="row align-items-center mb-3">
            <div class="col-auto">
                <span class="avatar" style="background-image: url('__image__')"></span>
            </div>
            <div class="col text-truncate">
                <a href="__url__" class="text-body d-block" target="_blank">__name__</a>
                <div class="text-secondary text-truncate">__attributes__</div>
            </div>
            <div class="col-auto">
                <a
                    href="javascript:void(0)"
                    class="text-decoration-none list-group-item-actions"
                    data-bb-toggle="product-delete-item"
                    data-bb-target="__id__"
                    title="{{ trans('plugins/ecommerce::products.delete') }}"
                >
                    <x-core::icon name="ti ti-x" class="text-secondary" />
                </a>
            </div>
        </div>

        {{-- Price & Discount Section --}}
        <div>
            <div class="row">
                <div class="col-6">
                    <div class="form-group mb-3">
                        <x-core::form.text-input
                            :label="trans('plugins/ecommerce::products.price')"
                            class="input-mask-number product-price"
                            name="products_extra[__index__][price]"
                            :data-thousands-separator="EcommerceHelper::getThousandSeparatorForInputMask()"
                            :data-decimal-separator="EcommerceHelper::getDecimalSeparatorForInputMask()"
                            value="__price__"
                            :required="true"
                        />
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group mb-3">
                        <label class="form-label">{{ trans('plugins/ecommerce::products.discount') }}</label>
                        <select class="form-select discount-type" name="products_extra[__index__][discount_type]">
                            <option value="percentage">Percentage Discount</option>
                            <option value="fixed">Fixed Price Discount</option>
                        </select>

                        <input
                            type="number"
                            class="form-control mt-2 discount-value input-mask-number"
                            name="products_extra[__index__][discount_value]"
                            placeholder="Enter discount value"
                        />
                        <input
                            type="text"
                            class="form-control mt-2 calculated-discount"
                            placeholder="Calculated Value"
                            readonly
                        />
                    </div>
                </div>
            </div>
        </div>

    </div>
</x-core::custom-template>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const productFilter = document.getElementById('product-filter');

    function toggleFields() {
        const filterValue = productFilter.value;

        document.querySelectorAll('.discount-type').forEach(select => {
            const discountValueInput = select.closest('.form-group').querySelector('.discount-value');
            const calculatedField = select.closest('.form-group').querySelector('.calculated-discount');

            if (filterValue === 'category' || filterValue === 'products') {
                discountValueInput.style.display = 'block';
                calculatedField.style.display = 'block';
            } else if (filterValue === 'all-products') {
                discountValueInput.style.display = 'none';
                calculatedField.style.display = 'none';
            }
        });
    }

function calculateDiscounts() {
    document.querySelectorAll('.discount-type').forEach(select => {
        const group = select.closest('.row');
        const priceInput = group.parentElement.parentElement.querySelector('.product-price');
        const discountValueInput = select.closest('.form-group').querySelector('.discount-value');
        const calculatedField = select.closest('.form-group').querySelector('.calculated-discount');

        const price = parseFloat(priceInput?.value) || 0;
        const discountValue = parseFloat(discountValueInput?.value) || 0;
        let finalPrice = price;

        if (select.value === 'percentage') {
            // Calculate % of price and subtract
            finalPrice = price - (price * discountValue / 100);
            calculatedField.value = `${finalPrice.toFixed(2)} (Price after ${discountValue}% discount)`;
        } else if (select.value === 'fixed') {
            // Subtract fixed amount
            finalPrice = price - discountValue;
            calculatedField.value = `${finalPrice.toFixed(2)} (Price after fixed discount)`;
        } else {
            calculatedField.value = '';
        }
    });
}


    // Event Listeners
    productFilter.addEventListener('change', () => {
        toggleFields();
        calculateDiscounts();
    });

    document.addEventListener('input', function (e) {
        if (e.target.classList.contains('discount-value') || e.target.classList.contains('product-price')) {
            calculateDiscounts();
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('discount-type')) {
            calculateDiscounts();
        }
    });

    // Init
    toggleFields();
    calculateDiscounts();
});
</script>
@endpush
