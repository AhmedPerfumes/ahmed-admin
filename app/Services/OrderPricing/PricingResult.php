<?php

namespace App\Services\OrderPricing;

use Botble\Ecommerce\Models\Product;

/**
 * DTO for the calculated pricing values returned by every strategy.
 * Contains all the fields needed to build an OrderProduct record.
 */
class PricingResult
{
    public function __construct(
        public readonly float $price,
        public readonly float $totalAmount,
        public readonly float $discountPercent,
        public readonly float $discountAmount,
        public readonly float $netAmount,
        public readonly float $taxAmount,
        public readonly float $grossAmount,
        public readonly ?string $campaign = null,
        public readonly bool $isGift = false,
        public readonly ?string $productCategory = null,
        public readonly ?string $productSubcategory = null,
    ) {}

    /**
     * Build the $options array that gets JSON-encoded into order_products.options.
     */
    public static function buildOptionsArray(Product $dbProduct): array
    {
        return [
            'name' => $dbProduct->name,
            'image' => $dbProduct->image,
            'attributes' => ' ',
            'taxRate' => $dbProduct->percentage,
            'options' => [],
            'extras' => [],
            'sku' => $dbProduct->sku,
            'weight' => $dbProduct->weight,
            'original_price' => $dbProduct->price,
            'product_type' => $dbProduct->product_type,
        ];
    }

    /**
     * Convert this result into the array format expected by OrderProduct::create().
     */
    public function toOrderProductArray(PricingContext $ctx): array
    {
        $options = self::buildOptionsArray($ctx->dbProduct);

        $data = [
            'order_id' => $ctx->orderId,
            'product_id' => $ctx->requestProduct['product_id'],
            'product_name' => $ctx->dbProduct->name,
            'product_image' => $ctx->dbProduct->image,
            'qty' => $ctx->quantity,
            'weight' => $ctx->dbProduct->weight,
            'price' => $this->price,
            'total_amount' => $this->totalAmount,
            'discount_percent' => $this->discountPercent,
            'discount_amount' => $this->discountAmount,
            'net_amount' => $this->netAmount,
            'tax_amount' => $this->taxAmount,
            'gross_amount' => $this->grossAmount,
            'product_options' => [],
            'options' => json_encode($options),
            'product_type' => $ctx->dbProduct->product_type,
            'product_category' => $this->productCategory ?? '',
            'product_subcategory' => $this->productSubcategory ?? '',
            'vat' => $ctx->vatRate,
        ];

        if ($this->campaign !== null) {
            $data['campaign'] = $this->campaign;
        }

        if ($this->isGift) {
            $data['is_gift'] = 1;
        }

        return $data;
    }
}
