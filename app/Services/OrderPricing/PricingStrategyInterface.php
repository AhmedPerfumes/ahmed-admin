<?php

namespace App\Services\OrderPricing;

/**
 * Contract for all order product pricing strategies.
 * 
 * Each implementation encapsulates one pricing scenario
 * (e.g., percent discount, gift, coupon, regular price).
 */
interface PricingStrategyInterface
{
    /**
     * Does this strategy apply to the given product context?
     */
    public function supports(PricingContext $ctx): bool;

    /**
     * Calculate pricing and return a result DTO.
     */
    public function calculate(PricingContext $ctx): PricingResult;
}
