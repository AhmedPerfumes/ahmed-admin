@extends(BaseHelper::getAdminMasterLayoutTemplate())
@section('content')
<div class="row">
    {{-- Main Cart Details Card --}}
    <div class="col-md-8">
        <x-core::card>
            <x-core::card.header>
                <x-core::card.title>
                    Cart Data
                </x-core::card.title>
            </x-core::card.header>
            <x-core::card.body>
                {{-- 
                  This uses <pre> tag to format the JSON data.
                  The 'json_encode' formats the array nicely.
                --}}
                <pre style="white-space: pre-wrap; word-wrap: break-word; background-color: #000000; padding: 15px; border-radius: 5px;">{{ json_encode($cart->cart_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </x-core::card.body>
        </x-core::card>
    </div>

    {{-- Information Card --}}
    <div class="col-md-4">
        <x-core::card>
            <x-core::card.header>
                <x-core::card.title>
                    Cart Information
                </x-core::card.title>
            </x-core::card.header>
            <x-core::card.body>
                <p><strong>Cart ID:</strong> {{ $cart->id }}</p>
                <p><strong>Customer ID:</strong> {{ $cart->customer_id ?? 'N/A' }}</p>
                <p><strong>Status:</strong> {!! BaseHelper::renderBadge($cart->status) !!}</p>
                <hr>
                <p><strong>Created At:</strong> {{ BaseHelper::formatDateTime($cart->created_at) }}</p>
                <p><strong>Last Updated:</strong> {{ BaseHelper::formatDateTime($cart->updated_at) }}</p>
            </x-core::card.body>
        </x-core::card>
    </div>
</div>
@stop