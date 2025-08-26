@extends($layout ?? BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h1 class="h4 mb-0">Create Promotion</h1>
                    </div>
                    <div class="card-body">
                        <form id="promotionForm" action="{{ route('promotions.store') }}" method="POST">
                            @csrf
                            <div class="mb-3">
                                <label for="name" class="form-label">Promotion Name</label>
                                <input type="text" name="name" id="name" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label for="type" class="form-label">Promotion Type</label>
                                <select name="type" id="type" onchange="toggleFields()" class="form-select" required>
                                    <option value="">Select Type</option>
                                    {{-- <option value="bogo">BOGO</option> --}}
                                    <option value="buy_x_get_y">Buy X Get Y</option>
                                    <option value="discount">Discount</option>
                                    <option value="coupon">Coupon</option>
                                    <option value="foc">Free of Charge</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea name="description" id="description" class="form-control"></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" name="start_date" id="start_date" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" name="end_date" id="end_date" class="form-control" required>
                            </div>

                            <!-- BOGO Fields -->
                            {{-- <div id="bogo_fields" style="display: none;">
                                <div class="mb-3">
                                    <label for="bogo_product_ids" class="form-label">Buy Product</label>
                                    <select name="bogo_product_ids_temp" id="bogo_product_ids" class="form-select">
                                        @foreach ($products as $product)
                                            <option value="{{ $product['id'] }}"
                                                @if(in_array($product['id'], $discountedProductIds)) disabled @endif>
                                                {{ $product['name'] . (in_array($product['id'], $discountedProductIds) ? ' (already discounted)' : '') }}
                                            </option>
                                        @endforeach
                                        -- <option value="1">Product 1</option>
                                        <option value="2">Product 2</option>
                                        <option value="3">Product 3</option> --
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="bogo_free_product_ids" class="form-label">Free Product</label>
                                    <select name="bogo_free_product_ids_temp" id="bogo_free_product_ids" class="form-select">
                                        @foreach ($products as $product)
                                            <option value="{{ $product['id'] }}"
                                                @if(in_array($product['id'], $discountedProductIds)) disabled @endif>
                                                {{ $product['name'] . (in_array($product['id'], $discountedProductIds) ? ' (already discounted)' : '') }}
                                            </option>
                                        @endforeach
                                        -- <option value="1">Product 1</option>
                                        <option value="2">Product 2</option>
                                        <option value="3">Product 3</option> --
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <button type="button" id="add_bogo_rule" class="btn btn-success">Add More</button>
                                </div>
                                <div class="mb-3">
                                    <div class="row fw-bold border-bottom py-2">
                                        <div class="col">Buy Product</div>
                                        <div class="col">Free Product</div>
                                        <div class="col-2">Action</div>
                                    </div>
                                    <div id="bogo_rules_table"></div>
                                </div>
                            </div> --}}

                            <!-- Buy X Get Y Fields -->
                            <div id="buy_x_get_y_fields" style="display: none;">
                                <div class="mb-3">
                                    <label for="buy_quantity" class="form-label">Buy Quantity</label>
                                    <input type="number" name="conditions[buy_x_get_y][buy_quantity]" id="buy_quantity" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="get_quantity" class="form-label">Get Quantity</label>
                                    <input type="number" name="rewards[buy_x_get_y][get_quantity]" id="get_quantity" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="buy_x_product_ids" class="form-label">Products (Buy)</label>
                                    <select name="conditions[buy_x_get_y][product_ids][]" id="buy_x_product_ids" multiple class="form-select">
                                        @foreach ($products as $product)
                                            <option value="{{ $product['id'] }}"
                                                @if(in_array($product['id'], $discountedProductIds)) disabled @endif>
                                                {{ $product['name'] . (in_array($product['id'], $discountedProductIds) ? ' (already discounted)' : '') }}
                                            </option>
                                        @endforeach
                                        {{-- <option value="1">Product 1</option>
                                        <option value="2">Product 2</option>
                                        <option value="3">Product 3</option> --}}
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="get_y_product_ids" class="form-label">Free Products</label>
                                    <select name="rewards[buy_x_get_y][free_product_ids][]" id="get_y_product_ids" multiple class="form-select">
                                        @foreach ($products as $product)
                                            <option value="{{ $product['id'] }}"
                                                @if(in_array($product['id'], $discountedProductIds)) disabled @endif>
                                                {{ $product['name'] . (in_array($product['id'], $discountedProductIds) ? ' (already discounted)' : '') }}
                                            </option>
                                        @endforeach
                                        {{-- <option value="1">Product 1</option>
                                        <option value="2">Product 2</option>
                                        <option value="3">Product 3</option> --}}
                                    </select>
                                </div>
                                {{-- <div class="mb-3">
                                    <label for="buy_x_category_ids" class="form-label">Categories (Buy)</label>
                                    <select name="conditions[buy_x_get_y][category_ids][]" id="buy_x_category_ids" multiple class="form-select">
                                        <option value="1">Category 1</option>
                                        <option value="2">Category 2</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="get_y_category_ids" class="form-label">Free Categories</label>
                                    <select name="rewards[buy_x_get_y][free_category_ids][]" id="get_y_category_ids" multiple class="form-select">
                                        <option value="1">Category 1</option>
                                        <option value="2">Category 2</option>
                                    </select>
                                </div> --}}
                            </div>

                            <!-- Discount Fields -->
                            <div id="discount_fields" style="display: none;">
                                <div class="mb-3">
                                    <label for="discount_apply_to" class="form-label">Apply Discount To</label>
                                    <select name="conditions[discount][apply_to]" id="discount_apply_to" class="form-select">
                                        <option value="all">All Products</option>
                                        <option value="individual">Individual Product</option>
                                        <option value="group">Group Discount</option>
                                    </select>
                                </div>
                                <div id="discount_all_products_field" style="display: block;">
                                    <div class="mb-3">
                                        <label for="discount_all" class="form-label">Discount Percent (All Products)</label>
                                        <input type="number" step="0.01" name="rewards[discount][percentage]" id="discount_all" class="form-control">
                                    </div>
                                </div>
                                <div id="discount_individual_fields" style="display: none;">
                                    <div class="mb-3">
                                        <label for="discount_product_ids" class="form-label">Product</label>
                                        <select name="discount_product_ids_temp" id="discount_product_ids" class="form-select">
                                            @foreach ($products as $product)
                                                <option value="{{ $product['id'] }}"
                                                    @if(in_array($product['id'], $discountedProductIds)) disabled @endif>
                                                    {{ $product['name'] . (in_array($product['id'], $discountedProductIds) ? ' (already discounted)' : '') }}
                                                </option>
                                            @endforeach
                                            {{-- <option value="1">Product 1</option>
                                            <option value="2">Product 2</option>
                                            <option value="3">Product 3</option> --}}
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="discount_type_select" class="form-label">Discount Type</label>
                                        <select name="discount_type_temp" id="discount_type_select" class="form-select">
                                            <option value="percent">Percent</option>
                                            <option value="amount">Amount</option>
                                        </select>
                                    </div>
                                    <div id="discount_percent_fields" style="display: block;">
                                        <div class="mb-3">
                                            <label for="discount_percent" class="form-label">Discount Percentage</label>
                                            <input type="number" step="0.01" name="rewards[discount][percent_temp]" id="discount_percent" class="form-control">
                                        </div>
                                    </div>
                                    <div id="discount_amount_fields" style="display: none;">
                                        <div class="mb-3">
                                            <label for="discount_amount" class="form-label">Discount Amount</label>
                                            <input type="number" step="0.01" name="rewards[discount][amount_temp]" id="discount_amount" class="form-control">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="discount_product_price" class="form-label">Product Price</label>
                                        <input type="number" step="0.01" id="discount_product_price" class="form-control" readonly>
                                    </div>
                                    <div class="mb-3">
                                        <label for="discount_result" class="form-label">Discount Amount</label>
                                        <input type="number" step="0.01" id="discount_result" class="form-control" readonly>
                                    </div>
                                    <div class="mb-3">
                                        <label for="discount_final_price" class="form-label">Final Price After Discount</label>
                                        <input type="number" step="0.01" id="discount_final_price" class="form-control" readonly>
                                    </div>
                                    <div class="mb-3">
                                        <button type="button" id="add_discount_rule" class="btn btn-success">Add More</button>
                                    </div>
                                    <div class="mb-3">
                                        <div class="row fw-bold border-bottom py-2">
                                            <div class="col">Product(s)</div>
                                            <div class="col">Discount Type</div>
                                            <div class="col">Discount Value</div>
                                            <div class="col">Discount Amount</div>
                                            <div class="col">Final Price</div>
                                            <div class="col-2">Action</div>
                                        </div>
                                        <div id="discount_rules_table"></div>
                                    </div>
                                </div>
                                <div id="discount_group_fields" style="display: none;">
                                    <div class="mb-3">
                                        <label for="discount_group_product_ids" class="form-label">Products</label>
                                        <select name="conditions[discount][group_product_ids][]" id="discount_group_product_ids" multiple class="form-select">
                                            @foreach ($products as $product)
                                                <option value="{{ $product['id'] }}"
                                                    @if(in_array($product['id'], $discountedProductIds)) disabled @endif>
                                                    {{ $product['name'] . (in_array($product['id'], $discountedProductIds) ? ' (already discounted)' : '') }}
                                                </option>
                                            @endforeach
                                            {{-- <option value="1">Product 1</option>
                                            <option value="2">Product 2</option>
                                            <option value="3">Product 3</option> --}}
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="discount_group_percent" class="form-label">Discount Percent</label>
                                        <input type="number" step="0.01" name="rewards[discount][group_percentage]" id="discount_group_percent" class="form-control">
                                    </div>
                                </div>
                                {{-- <div class="mb-3">
                                    <label for="discount_category_ids" class="form-label">Categories</label>
                                    <select name="conditions[discount][category_ids][]" id="discount_category_ids" multiple class="form-select">
                                        <option value="1">Category 1</option>
                                        <option value="2">Category 2</option>
                                    </select>
                                </div> --}}
                            </div>

                            <!-- Coupon Fields -->
                            <div id="coupon_fields" style="display: none;">
                                <div class="mb-3">
                                    <label for="coupon_code" class="form-label">Coupon Code</label>
                                    <input type="text" name="coupon_code" id="coupon_code" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="coupon_apply_to" class="form-label">Apply Coupon To</label>
                                    <select name="conditions[coupon][apply_to]" id="coupon_apply_to" class="form-select">
                                        <option value="all">All Products</option>
                                        <option value="group">Group Products</option>
                                        <option value="customer">Customer</option>
                                    </select>
                                </div>
                                <div class="mb-3" id="coupon_customer_ids_field" style="display: none;">
                                    <label for="coupon_customer_ids" class="form-label">Customers</label>
                                    <select name="conditions[coupon][customer_ids][]" id="coupon_customer_ids" multiple class="form-select">
                                        @foreach($customers as $customer)
                                            <option value="{{ $customer['id'] }}">{{ $customer['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div id="coupon_all_products_field" style="display: block;">
                                    <div class="mb-3">
                                        <label for="coupon_all" class="form-label">Coupon Percent (All Products)</label>
                                        <input type="number" step="0.01" name="rewards[coupon][percentage]" id="coupon_all" class="form-control">
                                    </div>
                                </div>
                                <div id="coupon_group_fields" style="display: none;">
                                    <div class="mb-3">
                                        <label for="coupon_group_product_ids" class="form-label">Products</label>
                                        <select name="conditions[coupon][group_product_ids][]" id="coupon_group_product_ids" multiple class="form-select">
                                            @foreach ($products as $product)
                                                <option value="{{ $product['id'] }}"
                                                    @if(in_array($product['id'], $discountedProductIds)) disabled @endif>
                                                    {{ $product['name'] . (in_array($product['id'], $discountedProductIds) ? ' (already discounted)' : '') }}
                                                </option>
                                            @endforeach
                                            {{-- <option value="1">Product 1</option>
                                            <option value="2">Product 2</option>
                                            <option value="3">Product 3</option> --}}
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="coupon_group_percent" class="form-label">Coupon Percent</label>
                                        <input type="number" step="0.01" name="rewards[coupon][group_percentage]" id="coupon_group_percent" class="form-control">
                                    </div>
                                </div>
                                <div id="coupon_customer_field" style="display: none;">
                                    <div class="mb-3">
                                        <label for="coupon_customer_percent" class="form-label">Coupon Percent (Customer)</label>
                                        <input type="number" step="0.01" name="rewards[coupon][customer_percentage]" id="coupon_customer_percent" class="form-control">
                                    </div>
                                </div>
                                {{-- <div class="mb-3">
                                    <label for="coupon_category_ids" class="form-label">Categories</label>
                                    <select name="conditions[coupon][category_ids][]" id="coupon_category_ids" multiple class="form-select">
                                        <option value="1">Category 1</option>
                                        <option value="2">Category 2</option>
                                    </select>
                                </div> --}}
                            </div>

                            <!-- FOC Fields -->
                            <div id="foc_fields" style="display: none;">
                                <div class="mb-3">
                                    <label for="foc_min_threshold" class="form-label">Minimum Threshold (Cart Amount)</label>
                                    <input type="number" step="0.01" name="conditions[foc][min_threshold]" id="foc_min_threshold" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="foc_max_threshold" class="form-label">Maximum Threshold (Cart Amount)</label>
                                    <input type="number" step="0.01" name="conditions[foc][max_threshold]" id="foc_max_threshold" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="foc_product_ids" class="form-label">Free Products</label>
                                    <select name="rewards[foc][free_product_ids][]" id="foc_product_ids" multiple class="form-select">
                                        @foreach ($products as $product)
                                            <option value="{{ $product['id'] }}"
                                                @if(in_array($product['id'], $discountedProductIds)) disabled @endif>
                                                {{ $product['name'] . (in_array($product['id'], $discountedProductIds) ? ' (already discounted)' : '') }}
                                            </option>
                                        @endforeach
                                        {{-- <option value="1">Product 1</option>
                                        <option value="2">Product 2</option>
                                        <option value="3">Product 3</option> --}}
                                    </select>
                                </div>
                                {{-- <div class="mb-3">
                                    <label for="foc_category_ids" class="form-label">Free Categories</label>
                                    <select name="rewards[foc][free_category_ids][]" id="foc_category_ids" multiple class="form-select">
                                        <option value="1">Category 1</option>
                                        <option value="2">Category 2</option>
                                    </select>
                                </div> --}}
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary">Save Promotion</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css" rel="stylesheet">
    <script>
        // Mock product price data (replace with actual API call in production)
        // const productPrices = {
        //     '1': 100.00,
        //     '2': 50.00,
        //     '3': 75.00
        // };

        // Dynamically create productPrices object from PHP $products array
        const productPrices = @json($products).reduce((acc, product) => {
            acc[product.id] = parseFloat(product.price);
            return acc;
        }, {});

        document.addEventListener('DOMContentLoaded', function () {
            // Initialize TomSelect for all select elements
            const discountSelect = new TomSelect('#discount_product_ids', {
                maxItems: 1,
                onChange: function(values) {
                    updatePriceAndDiscount('discount', values);
                }
            });
            const groupDiscountSelect = new TomSelect('#discount_group_product_ids', {
                maxItems: 10
            });
            const couponGroupSelect = new TomSelect('#coupon_group_product_ids', {
                maxItems: 10
            });
            const couponCustomerSelect = new TomSelect('#coupon_customer_ids', {
                maxItems: 10
            });
            // const bogoProductSelect = new TomSelect('#bogo_product_ids', { maxItems: 1 });
            // const bogoFreeProductSelect = new TomSelect('#bogo_free_product_ids', { maxItems: 1 });
            new TomSelect('#buy_x_product_ids', {
                maxItems: 10,
                lock: 'locked',
                onItemRemove: function() {
                    return false;
                }
            });
            new TomSelect('#get_y_product_ids', {
                maxItems: 10,
                lock: 'locked',
                onItemRemove: function() {
                    return false;
                }
            });
            // new TomSelect('#buy_x_category_ids', { maxItems: 10 });
            // new TomSelect('#get_y_category_ids', { maxItems: 10 });
            // new TomSelect('#discount_category_ids', { maxItems: 10 });
            // new TomSelect('#coupon_category_ids', { maxItems: 10 });
            new TomSelect('#foc_product_ids', { maxItems: 10 });
            // new TomSelect('#foc_category_ids', { maxItems: 10 });

            toggleFields();
            toggleDiscountFields('discount');
            toggleCouponFields();

            // Add event listeners
            document.getElementById('type').addEventListener('change', function() {
                toggleFields();
                if (this.value === 'discount' && document.getElementById('discount_apply_to').value === 'individual') {
                    discountSelect.setValue('1'); // Auto-select Product 1
                    updatePriceAndDiscount('discount', '1');
                }
            });
            document.getElementById('discount_apply_to').addEventListener('change', function() {
                toggleDiscountFields('discount');
                if (this.value === 'individual') {
                    discountSelect.setValue('1'); // Auto-select Product 1
                    updatePriceAndDiscount('discount', '1');
                }
            });
            document.getElementById('discount_type_select').addEventListener('change', function() {
                toggleDiscountTypeFields();
                calculateDiscount('discount');
            });
            document.getElementById('discount_percent').addEventListener('input', function() {
                calculateDiscount('discount');
            });
            document.getElementById('discount_amount').addEventListener('input', function() {
                calculateDiscount('discount');
            });
            document.getElementById('coupon_apply_to').addEventListener('change', function() {
                toggleCouponFields();
            });

            // Add BOGO rule
            // document.getElementById('add_bogo_rule').addEventListener('click', function() {
            //     const buyProduct = document.getElementById('bogo_product_ids').value;
            //     const freeProduct = document.getElementById('bogo_free_product_ids').value;
            //     if (buyProduct && freeProduct) {
            //         addBogoRule(buyProduct, freeProduct);
            //         bogoProductSelect.clear();
            //         bogoFreeProductSelect.clear();
            //     } else {
            //         alert('Please select both a Buy product and a Free product.');
            //     }
            // });

            // Add Individual Discount rule
            document.getElementById('add_discount_rule').addEventListener('click', function() {
                const productId = document.getElementById('discount_product_ids').value;
                const discountType = document.getElementById('discount_type_select').value;
                const discountValue = discountType === 'percent' ? 
                    document.getElementById('discount_percent').value : 
                    document.getElementById('discount_amount').value;
                const productPrice = document.getElementById('discount_product_price').value;
                const discountAmount = document.getElementById('discount_result').value;
                const finalPrice = document.getElementById('discount_final_price').value;
                if (productId && discountValue && productPrice && discountAmount && finalPrice) {
                    if (discountValue <= 0) {
                        alert('Discount value must be greater than 0.');
                        return;
                    }
                    if (discountAmount < 0) {
                        alert('Discount amount cannot be negative.');
                        return;
                    }
                    if (finalPrice < 0) {
                        alert('Final price cannot be negative.');
                        return;
                    }
                    addDiscountRule(productId, discountType, discountValue, productPrice, discountAmount, finalPrice);
                    discountSelect.clear();
                    document.getElementById('discount_percent').value = '';
                    document.getElementById('discount_amount').value = '';
                    document.getElementById('discount_product_price').value = '';
                    document.getElementById('discount_result').value = '';
                    document.getElementById('discount_final_price').value = '';
                    discountSelect.setValue('1'); // Auto-select Product 1 after adding
                    updatePriceAndDiscount('discount', '1');
                } else {
                    alert('Please select a product and enter all discount values.');
                }
            });

            // Form submission validation
            document.getElementById('promotionForm').addEventListener('submit', function(event) {
                const promotionType = document.getElementById('type').value;
                // Validate BOGO
                if (promotionType === 'bogo') {
                    const bogoRules = document.querySelectorAll('#bogo_rules_table .row');
                    if (bogoRules.length === 0) {
                        event.preventDefault();
                        alert('Please add at least one BOGO rule with a Buy product and a Free product.');
                        return;
                    }
                    for (let row of bogoRules) {
                        const buyProduct = row.querySelector('input[name="conditions[bogo][product_ids][]"]').value;
                        const freeProduct = row.querySelector('input[name="rewards[bogo][free_product_ids][]"]').value;
                        if (!buyProduct || !freeProduct) {
                            event.preventDefault();
                            alert('All BOGO rules must have a valid Buy product and Free product.');
                            return;
                        }
                    }
                }
                // Validate Buy X Get Y
                if (promotionType === 'buy_x_get_y') {
                    const buyQuantity = parseFloat(document.getElementById('buy_quantity').value);
                    const getQuantity = parseFloat(document.getElementById('get_quantity').value);
                    const buyProductIds = document.getElementById('buy_x_product_ids').tomselect.getValue();
                    // const buyCategoryIds = document.getElementById('buy_x_category_ids').tomselect.getValue();
                    // const getProductIds = document.getElementById('get_y_product_ids').tomselect.getValue();
                    // const getCategoryIds = document.getElementById('get_y_category_ids').tomselect.getValue();

                    if (isNaN(buyQuantity) || buyQuantity < 1) {
                        event.preventDefault();
                        alert('Buy Quantity must be a positive number (at least 1).');
                        return;
                    }
                    if (isNaN(getQuantity) || getQuantity < 1) {
                        event.preventDefault();
                        alert('Get Quantity must be a positive number (at least 1).');
                        return;
                    }
                    if (buyProductIds.length === 0) {
                        event.preventDefault();
                        alert('At least one Buy product or must be selected.');
                        return;
                    }
                    // if (getProductIds.length === 0) {
                    //     event.preventDefault();
                    //     alert('At least one Free product must be selected.');
                    //     return;
                    // }
                }
                // Validate Discount
                if (promotionType === 'discount') {
                    const applyTo = document.getElementById('discount_apply_to').value;
                    if (applyTo === 'all') {
                        const discountPercent = parseFloat(document.getElementById('discount_all').value);
                        if (isNaN(discountPercent) || discountPercent <= 0) {
                            event.preventDefault();
                            alert('Discount Percent for All Products must be a positive number.');
                            return;
                        }
                    } else if (applyTo === 'group') {
                        const groupProductIds = document.getElementById('discount_group_product_ids').tomselect.getValue();
                        const groupPercent = parseFloat(document.getElementById('discount_group_percent').value);
                        if (groupProductIds.length === 0) {
                            event.preventDefault();
                            alert('At least one product must be selected for Group Discount.');
                            return;
                        }
                        if (isNaN(groupPercent) || groupPercent <= 0) {
                            event.preventDefault();
                            alert('Discount Percent for Group must be a positive number.');
                            return;
                        }
                    } else if (applyTo === 'individual') {
                        const discountRules = document.querySelectorAll('#discount_rules_table .row');
                        if (discountRules.length === 0) {
                            event.preventDefault();
                            alert('Please add at least one discount rule with valid product, discount type, and values.');
                            return;
                        }
                        for (let row of discountRules) {
                            const productId = row.querySelector('input[name="conditions[discount][product_ids][]"]').value;
                            const discountType = row.querySelector('input[name="rewards[discount][discount_type][]"]').value;
                            const discountValue = parseFloat(row.querySelector('input[name="rewards[discount][value][]"]').value);
                            const productPrice = parseFloat(row.querySelector('input[name="rewards[discount][product_price][]"]').value);
                            const discountAmount = parseFloat(row.querySelector('input[name="rewards[discount][discount_amount][]"]').value);
                            const finalPrice = parseFloat(row.querySelector('input[name="rewards[discount][final_price][]"]').value);

                            if (!productId || !discountType || isNaN(discountValue) || isNaN(productPrice) || isNaN(discountAmount) || isNaN(finalPrice)) {
                                event.preventDefault();
                                alert('All discount rules must have a valid product, discount type, discount value, product price, discount amount, and final price.');
                                return;
                            }
                            if (discountType !== 'percent' && discountType !== 'amount') {
                                event.preventDefault();
                                alert('Discount type must be either "percent" or "amount".');
                                return;
                            }
                            if (discountValue <= 0) {
                                event.preventDefault();
                                alert('Discount value must be greater than 0.');
                                return;
                            }
                            if (discountAmount < 0) {
                                event.preventDefault();
                                alert('Discount amount cannot be negative.');
                                return;
                            }
                            if (finalPrice < 0) {
                                event.preventDefault();
                                alert('Final price cannot be negative.');
                                return;
                            }
                        }
                    }
                }
                // Validate Coupon
                if (promotionType === 'coupon') {
                    const couponCode = document.getElementById('coupon_code').value;
                    const applyTo = document.getElementById('coupon_apply_to').value;
                    if(!couponCode && couponCode == '') {
                        event.preventDefault();
                        alert('Coupon Code is required.');
                        return;
                    }
                    if (applyTo === 'customer') {
                        const customerIds = document.getElementById('coupon_customer_ids').tomselect.getValue();
                        if (customerIds.length === 0) {
                            event.preventDefault();
                            alert('At least one customer must be selected for Customer Coupon.');
                            return;
                        }
                        const customerPercent = parseFloat(document.getElementById('coupon_customer_percent').value);
                        if (isNaN(customerPercent) || customerPercent <= 0) {
                            event.preventDefault();
                            alert('Coupon Percent for Customer must be a positive number.');
                            return;
                        }
                    } else if (applyTo === 'all') {
                        const couponPercent = parseFloat(document.getElementById('coupon_all').value);
                        if (isNaN(couponPercent) || couponPercent <= 0) {
                            event.preventDefault();
                            alert('Coupon Percent for All Products must be a positive number.');
                            return;
                        }
                    } else if (applyTo === 'group') {
                        const groupProductIds = document.getElementById('coupon_group_product_ids').tomselect.getValue();
                        const groupPercent = parseFloat(document.getElementById('coupon_group_percent').value);
                        if (groupProductIds.length === 0) {
                            event.preventDefault();
                            alert('At least one product must be selected for Group Coupon.');
                            return;
                        }
                        if (isNaN(groupPercent) || groupPercent <= 0) {
                            event.preventDefault();
                            alert('Coupon Percent for Group must be a positive number.');
                            return;
                        }
                    }
                }

                // Validate FOC
                if (promotionType === 'foc') {
                    const minThreshold = parseFloat(document.getElementById('foc_min_threshold').value);
                    const maxThreshold = parseFloat(document.getElementById('foc_max_threshold').value);
                    const productIds = document.getElementById('foc_product_ids').tomselect.getValue();
                    // const categoryIds = document.getElementById('foc_category_ids').tomselect.getValue();

                    if (productIds.length === 0) {
                        event.preventDefault();
                        alert('At least one Free product must be selected for FOC promotion.');
                        return;
                    }
                    if (isNaN(minThreshold) || minThreshold < 0) {
                        event.preventDefault();
                        alert('Minimum Threshold for Cart Amount must be a non-negative number.');
                        return;
                    }
                    if (isNaN(maxThreshold) || maxThreshold < 0) {
                        event.preventDefault();
                        alert('Maximum Threshold for Cart Amount must be a non-negative number.');
                        return;
                    }
                    if (!isNaN(minThreshold) && !isNaN(maxThreshold) && maxThreshold < minThreshold) {
                        event.preventDefault();
                        alert('Maximum Threshold must be greater than or equal to Minimum Threshold.');
                        return;
                    }
                }
            });
        });

        function toggleFields() {
            const type = document.getElementById('type').value;
            // document.getElementById('bogo_fields').style.display = type === 'bogo' ? 'block' : 'none';
            document.getElementById('buy_x_get_y_fields').style.display = type === 'buy_x_get_y' ? 'block' : 'none';
            document.getElementById('discount_fields').style.display = type === 'discount' ? 'block' : 'none';
            document.getElementById('coupon_fields').style.display = type === 'coupon' ? 'block' : 'none';
            document.getElementById('foc_fields').style.display = type === 'foc' ? 'block' : 'none';
        }

        function toggleDiscountFields(type) {
            const applyTo = document.getElementById(`${type}_apply_to`).value;
            const individualFields = document.getElementById(`${type}_individual_fields`);
            const groupFields = document.getElementById(`${type}_group_fields`);
            const allProductsField = document.getElementById(`${type}_all_products_field`);
            individualFields.style.display = applyTo === 'individual' ? 'block' : 'none';
            groupFields.style.display = applyTo === 'group' ? 'block' : 'none';
            allProductsField.style.display = applyTo === 'all' ? 'block' : 'none';
            toggleDiscountTypeFields();
        }

        function toggleDiscountTypeFields() {
            const discountType = document.getElementById('discount_type_select').value;
            const percentFields = document.getElementById('discount_percent_fields');
            const amountFields = document.getElementById('discount_amount_fields');
            percentFields.style.display = discountType === 'percent' ? 'block' : 'none';
            amountFields.style.display = discountType === 'amount' ? 'block' : 'none';
        }

        function toggleCouponFields() {
            const applyTo = document.getElementById('coupon_apply_to').value;
            const allProductsField = document.getElementById('coupon_all_products_field');
            const groupFields = document.getElementById('coupon_group_fields');
            const customerField = document.getElementById('coupon_customer_field');
            const customerIdsField = document.getElementById('coupon_customer_ids_field');
            allProductsField.style.display = applyTo === 'all' ? 'block' : 'none';
            customerField.style.display = applyTo === 'customer' ? 'block' : 'none';
            customerIdsField.style.display = applyTo === 'customer' ? 'block' : 'none';
            groupFields.style.display = applyTo === 'group' ? 'block' : 'none';
        }

        function updatePriceAndDiscount(type, productIds) {
            const applyTo = document.getElementById(`${type}_apply_to`).value;
            if (applyTo === 'individual') {
                const priceInput = document.getElementById(`${type}_product_price`);
                let totalPrice = 0;
                if (typeof productIds === 'string') {
                    totalPrice = productPrices[productIds] || 0;
                }
                priceInput.value = totalPrice.toFixed(2);
                calculateDiscount(type);
            }
        }

        function calculateDiscount(type) {
            const applyTo = document.getElementById(`${type}_apply_to`).value;
            if (applyTo === 'individual') {
                const priceInput = document.getElementById(`${type}_product_price`);
                const discountType = document.getElementById('discount_type_select').value;
                const percentInput = document.getElementById('discount_percent');
                const amountInput = document.getElementById('discount_amount');
                const discountResultInput = document.getElementById(`${type}_result`);
                const finalPriceInput = document.getElementById(`${type}_final_price`);
                const price = parseFloat(priceInput.value) || 0;

                if (discountType === 'percent') {
                    const percentage = parseFloat(percentInput.value) || 0;
                    const discountAmount = (price * percentage) / 100;
                    const finalPrice = price - discountAmount;
                    if (finalPrice < 0) {
                        alert('Final price cannot be negative.');
                        discountResultInput.value = '';
                        finalPriceInput.value = '';
                        return;
                    }
                    discountResultInput.value = discountAmount.toFixed(2);
                    finalPriceInput.value = finalPrice.toFixed(2);
                } else if (discountType === 'amount') {
                    const amount = parseFloat(amountInput.value) || 0;
                    const finalPrice = price - amount;
                    if (finalPrice < 0) {
                        alert('Final price cannot be negative.');
                        discountResultInput.value = '';
                        finalPriceInput.value = '';
                        return;
                    }
                    discountResultInput.value = amount.toFixed(2);
                    finalPriceInput.value = finalPrice.toFixed(2);
                }
            }
        }

        // function addBogoRule(buyProduct, freeProduct) {
        //     const table = document.getElementById('bogo_rules_table');
        //     const row = document.createElement('div');
        //     row.className = 'row align-items-center border-bottom py-2';
        //     row.innerHTML = `
        //         <div class="col">${document.querySelector(`#bogo_product_ids option[value="${buyProduct}"]`).text}</div>
        //         <div class="col">${document.querySelector(`#bogo_free_product_ids option[value="${freeProduct}"]`).text}</div>
        //         <div class="col-2">
        //             <button type="button" onclick="this.parentElement.parentElement.remove()" class="btn btn-danger btn-sm">Remove</button>
        //         </div>
        //         <input type="hidden" name="conditions[bogo][product_ids][]" value="${buyProduct}">
        //         <input type="hidden" name="rewards[bogo][free_product_ids][]" value="${freeProduct}">
        //     `;
        //     table.appendChild(row);
        // }

        function addDiscountRule(productId, discountType, discountValue, productPrice, discountAmount, finalPrice) {
            const table = document.getElementById('discount_rules_table');
            const price = parseFloat(productPrice) || 0;
            let displayValue = discountType === 'percent' ? `${discountValue}%` : `${discountValue}`;
            const row = document.createElement('div');
            row.className = 'row align-items-center border-bottom py-2';
            row.innerHTML = `
                <div class="col">${document.querySelector(`#discount_product_ids option[value="${productId}"]`).text}</div>
                <div class="col">${discountType === 'percent' ? 'Percent' : 'Amount'}</div>
                <div class="col">${displayValue}</div>
                <div class="col">${parseFloat(discountAmount).toFixed(2)}</div>
                <div class="col">${parseFloat(finalPrice).toFixed(2)}</div>
                <div class="col-2">
                    <button type="button" onclick="this.parentElement.parentElement.remove()" class="btn btn-danger btn-sm">Remove</button>
                </div>
                <input type="hidden" name="conditions[discount][product_ids][]" value="${productId}">
                <input type="hidden" name="rewards[discount][discount_type][]" value="${discountType}">
                <input type="hidden" name="rewards[discount][value][]" value="${discountValue}">
                <input type="hidden" name="rewards[discount][product_price][]" value="${productPrice}">
                <input type="hidden" name="rewards[discount][discount_amount][]" value="${discountAmount}">
                <input type="hidden" name="rewards[discount][final_price][]" value="${finalPrice}">
            `;
            table.appendChild(row);
        }
    </script>
@endsection

@section('scripts')
@endsection
