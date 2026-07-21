<?php

namespace App\Services\OrderPricing\Strategies;

use App\Services\OrderPricing\PricingContext;
use App\Services\OrderPricing\PricingResult;
use App\Services\OrderPricing\PricingStrategyInterface;

class RegularPricing implements PricingStrategyInterface
{
    public function supports(PricingContext $ctx): bool
    {
        // Catch-all strategy, must be last in the chain
        return true;
    }

    public function calculate(PricingContext $ctx): PricingResult
    {
        $price = $ctx->dbProduct->price / (1 + ($ctx->vatRate / 100));
        $totalAmount = $price * $ctx->quantity;
        
        $discountPercent = 0;
        $discountAmount = 0.00;
        
        $netAmount = $totalAmount - $discountAmount;
        $taxAmount = ($netAmount / 100) * $ctx->vatRate;
        $grossAmount = $netAmount + $taxAmount;

        return new PricingResult(
            price: $price,
            totalAmount: $totalAmount,
            discountPercent: $discountPercent,
            discountAmount: $discountAmount,
            netAmount: $netAmount,
            taxAmount: $taxAmount,
            grossAmount: $grossAmount,
            campaign: null,
            isGift: false,
            productCategory: $ctx->requestProduct['category_name'] ?? '',
            productSubcategory: $ctx->requestProduct['subcategory_name'] ?? '',
        );
    }
}
