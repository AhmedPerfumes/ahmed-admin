<?php

namespace App\Services\OrderPricing\Strategies;

use App\Services\OrderPricing\PricingContext;
use App\Services\OrderPricing\PricingResult;
use App\Services\OrderPricing\PricingStrategyInterface;

class AmountDiscountPricing implements PricingStrategyInterface
{
    public function supports(PricingContext $ctx): bool
    {
        return !is_null($ctx->dbProduct->discount) 
            && ($ctx->dbProduct->is_gift ?? null) != 1 
            && ($ctx->dbProduct->is_coupon ?? null) != 1
            && $ctx->dbProduct->discount->discount_type == 'amount';
    }

    public function calculate(PricingContext $ctx): PricingResult
    {
        $price = $ctx->dbProduct->price / (1 + ($ctx->vatRate / 100));
        $totalAmount = $price * $ctx->quantity;
        
        $salePrice = $ctx->dbProduct->discount->final_price / (1 + ($ctx->vatRate / 100));
        
        $discountPercent = 0;
        $discountAmount = $totalAmount - ($salePrice * $ctx->quantity);
        
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
            campaign: $ctx->dbProduct->discount->name,
            isGift: false,
            productCategory: $ctx->requestProduct['category_name'] ?? '',
            productSubcategory: $ctx->requestProduct['subcategory_name'] ?? '',
        );
    }
}
