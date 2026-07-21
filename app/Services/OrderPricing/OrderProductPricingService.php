<?php

namespace App\Services\OrderPricing;

use App\Services\OrderPricing\Strategies\AmountDiscountPricing;
use App\Services\OrderPricing\Strategies\CouponPricing;
use App\Services\OrderPricing\Strategies\GiftPricing;
use App\Services\OrderPricing\Strategies\PercentDiscountPricing;
use App\Services\OrderPricing\Strategies\RegularPricing;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator service that evaluates all pricing strategies
 * and delegates to the first one that supports the context.
 */
class OrderProductPricingService
{
    /** @var PricingStrategyInterface[] */
    private array $strategies;

    public function __construct()
    {
        // Registration order matters. First match wins.
        // Gift takes priority, then discounts, then coupons, fallback to regular.
        $this->strategies = [
            new GiftPricing(),
            new PercentDiscountPricing(),
            new AmountDiscountPricing(),
            new CouponPricing(),
            new RegularPricing(), // Must be last (catch-all)
        ];
    }

    /**
     * Build the data array needed for OrderProduct::create()
     *
     * @param PricingContext $ctx
     * @return array
     * @throws \RuntimeException
     */
    public function buildOrderProduct(PricingContext $ctx): array
    {
        Log::info("Starting pricing calculation for product ID: {$ctx->requestProduct['product_id']} (Order ID: {$ctx->orderId})");

        foreach ($this->strategies as $strategy) {
            $strategyName = class_basename($strategy);
            Log::info("  -> Evaluating strategy: {$strategyName}");

            if ($strategy->supports($ctx)) {
                Log::info("  [MATCH!] {$strategyName} supports this product context. Calculating prices...");
                $result = $strategy->calculate($ctx);
                Log::info("  [DONE] {$strategyName} calculation complete. Final price: {$result->price}, Total: {$result->totalAmount}");
                return $result->toOrderProductArray($ctx);
            }
        }

        Log::error("  [FAILED] No pricing strategy matched the product context.");
        throw new \RuntimeException('No pricing strategy matched the product context.');
    }
}
