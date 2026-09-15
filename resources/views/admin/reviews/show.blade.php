@extends(BaseHelper::getAdminMasterLayoutTemplate())
@section('content')
<div class="row">
    <div class="col-md-8">
        {{-- Main Review Details Card --}}
        <x-core::card>
            <x-core::card.header>
                <x-core::card.title>
                    Review Details
                </x-core::card.title>
            </x-core::card.header>
            <x-core::card.body>
                {{-- Star Rating --}}
                <div class="mb-3">
                    <strong>Rating:</strong>
                    <div style="color: #ffb700; font-size: 1.2rem;">
                        @for ($i = 1; $i <= 5; $i++)
                            @if ($i <= $review->star)
                                <i class="ti ti-star-filled"></i>
                            @else
                                <i class="ti ti-star"></i>
                            @endif
                        @endfor
                        <span style="color: #6c757d; font-size: 1rem;">({{ number_format($review->star, 1) }} out of
                            5)</span>
                    </div>
                </div>

                {{-- Review Comment --}}
                <div class="mb-3">
                    <strong>Comment:</strong>
                    <p class="text-muted" style="font-size: 1.1rem; white-space: pre-wrap;">{{ $review->comment }}</p>
                </div>

                <hr>

                {{-- Status --}}
                <strong>Status:</strong>
                {{-- THE FIX: Manually create the badge based on the status text --}}
                @if ($review->status == 'published')
                    <span class="badge bg-success">Published</span>
                @elseif ($review->status == 'pending')
                    <span class="badge bg-warning">Pending</span>
                @else
                    <span class="badge bg-secondary">{{ ucfirst($review->status) }}</span>
                @endif

                {{-- Dates --}}
                <div class="mb-3">
                    <strong>Created At:</strong> {{ BaseHelper::formatDateTime($review->created_at) }}
                </div>

            </x-core::card.body>
        </x-core::card>
    </div>

    <div class="col-md-4">
        {{-- Customer Information Card --}}
        <x-core::card>
            <x-core::card.header>
                <x-core::card.title>
                    Author Information
                </x-core::card.title>
            </x-core::card.header>
            <x-core::card.body>
                <p><strong>Name:</strong> {{ $review->customer_name }}</p>
                <p><strong>Email:</strong> <a
                        href="mailto:{{ $review->customer_email }}">{{ $review->customer_email }}</a></p>
            </x-core::card.body>
        </x-core::card>

        {{-- Product Information Card --}}
        @if ($review->product)
            <x-core::card class="mt-3">
                <x-core::card.header>
                    <x-core::card.title>
                        Product Information
                    </x-core::card.title>
                </x-core::card.header>
                <x-core::card.body>
                    <p>
                        <strong>Name:</strong>
                        <a href="{{ route('products.edit', $review->product->id) }}"
                            target="_blank">{{ $review->product->name }}</a>
                    </p>
                    <p><strong>ID:</strong> {{ $review->product_id }}</p>
                </x-core::card.body>
            </x-core::card>
        @endif

        {{-- Action Card for Review Moderation (Publish/Unpublish) --}}
        <x-core::card class="mt-3">
            <x-core::card.header>
                <x-core::card.title>
                    Review Moderation
                </x-core::card.title>
            </x-core::card.header>
            <x-core::card.body>
                @if (session()->has('success_message'))
                    <div class="alert alert-success mb-3">
                        {{ session('success_message') }}
                    </div>
                @endif
                @if (session()->has('error_message'))
                    <div class="alert alert-danger mb-3">
                        {{ session('error_message') }}
                    </div>
                @endif

                @if ($review->status == \Botble\Base\Enums\BaseStatusEnum::PENDING || $review->status == 'pending')
                    <p class="text-muted mb-3">This review is currently <strong>pending approval</strong> and is hidden from the product page.</p>
                    <form action="{{ route('product-reviews.approve', $review->id) }}" method="POST">
                        @csrf
                        <input type="hidden" name="action" value="publish">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="ti ti-check"></i> Approve & Publish Review
                        </button>
                    </form>
                @else
                    <p class="text-success mb-3"><i class="ti ti-circle-check"></i> This review is <strong>published</strong> and visible to customers.</p>
                    <form action="{{ route('product-reviews.approve', $review->id) }}" method="POST">
                        @csrf
                        <input type="hidden" name="action" value="unpublish">
                        <button type="submit" class="btn btn-outline-warning w-100">
                            <i class="ti ti-eye-off"></i> Unpublish Review
                        </button>
                    </form>
                @endif
            </x-core::card.body>
        </x-core::card>

        {{-- Action Card for Coupon Reward Trigger --}}
        <x-core::card class="mt-3">
            <x-core::card.header>
                <x-core::card.title>
                    Customer Reward Coupon
                </x-core::card.title>
            </x-core::card.header>
            <x-core::card.body>
                @if ($review->coupon_code)
                    <div class="alert alert-info mb-3">
                        <strong>Coupon Assigned:</strong> <span class="badge bg-primary">{{ $review->coupon_code }}</span><br>
                        @if ($review->coupon_sent_at)
                            <small class="text-muted">Sent on: {{ BaseHelper::formatDateTime($review->coupon_sent_at) }}</small>
                        @endif
                    </div>
                @else
                    <p class="text-muted small mb-3">
                        You can send a reward coupon to <strong>{{ $review->customer_email }}</strong> via Smart View.
                    </p>
                @endif

                <form action="{{ route('product-reviews.send-coupon', $review->id) }}" method="POST">
                    @csrf

                    <div class="mb-3">
                        <label for="couponId" class="form-label"><strong>Select Coupon:</strong></label>
                        <select name="couponId" id="couponId" class="form-select">
                            <option value="{{ env('REVIEW_COUPON_ID_10') }}" selected>10% Coupon (SURVEY10)</option>
                            <option value="{{ env('REVIEW_COUPON_ID_15') }}">15% Coupon (SURVEY15)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" {{ !$review->customer_phone || !$review->customer_email ? 'disabled' : '' }}>
                        <i class="ti ti-ticket"></i> {{ $review->coupon_code ? 'Re-send Reward Coupon Email' : 'Generate & Send Coupon Email' }}
                    </button>
                    @if (!$review->customer_phone || !$review->customer_email)
                        <small class="text-danger d-block mt-2">Requires customer phone & email to register coupon.</small>
                    @endif
                </form>
            </x-core::card.body>
        </x-core::card>
    </div>
</div>
@stop