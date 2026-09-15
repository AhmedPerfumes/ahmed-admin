<?php

namespace App\Services\OrderPricing;

use Botble\Ecommerce\Models\Product;

/**
 * DTO that bundles all data each pricing strategy needs.
 * Avoids passing 8+ loose parameters to strategy classes.
 */
class PricingContext
{
    public function __construct(
        public readonly int $orderId,
        public readonly float $vatRate,
        public readonly int $quantity,
        public readonly Product $dbProduct,
        public readonly array $requestProduct,
        public readonly ?string $couponCode = null,
    ) {}
}
