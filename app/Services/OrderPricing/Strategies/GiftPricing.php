<?php

namespace App\Services\OrderPricing\Strategies;

use App\Services\OrderPricing\PricingContext;
use App\Services\OrderPricing\PricingResult;
use App\Services\OrderPricing\PricingStrategyInterface;

class GiftPricing implements PricingStrategyInterface
{
    public function supports(PricingContext $ctx): bool
    {
        return isset($ctx->requestProduct['is_gift']) && $ctx->requestProduct['is_gift'] == true;
    }

    public function calculate(PricingContext $ctx): PricingResult
    {
        $price = $ctx->dbProduct->price / (1 + ($ctx->vatRate / 100));
        $totalAmount = 0.00;
        
        $discountPercent = 100;
        $discountAmount = $ctx->dbProduct->price / (1 + ($ctx->vatRate / 100));
        
        $netAmount = 0.00;
        $taxAmount = 0.00;
        $grossAmount = 0.00;

        return new PricingResult(
            price: $price,
            totalAmount: $totalAmount,
            discountPercent: $discountPercent,
            discountAmount: $discountAmount,
            netAmount: $netAmount,
            taxAmount: $taxAmount,
            grossAmount: $grossAmount,
            campaign: $ctx->requestProduct['campaign'] ?? null,
            isGift: true,
            productCategory: '',
            productSubcategory: '',
        );
    }
}
