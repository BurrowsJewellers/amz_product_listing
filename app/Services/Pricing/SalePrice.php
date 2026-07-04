<?php

namespace App\Services\Pricing;

/**
 * The effective selling price for a product.
 *
 * compareAtPrice is 0 when the product is not on sale, matching the
 * retail_edge_products.compare_at_price column semantics used downstream
 * (UpdatePriceInventoryBatch sends null to Shopify when it is 0).
 */
final class SalePrice
{
    public function __construct(
        public readonly float $price,
        public readonly float $compareAtPrice,
    ) {}

    public function onSale(): bool
    {
        return $this->compareAtPrice > 0;
    }
}
