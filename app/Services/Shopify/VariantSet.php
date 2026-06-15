<?php

namespace App\Services\Shopify;

/**
 * The normalized variant structure for a single product family, ready to feed into
 * a Shopify productSet mutation. Pure data — no behaviour, no API.
 *
 * @param  array<int, array{name: string, position: int, values: array<int, string>}>  $productOptions
 * @param  array<int, array{sku: string, optionValues: array<int, array{optionName: string, name: string}>, price: string, compareAtPrice: ?string, barcode: ?string}>  $variants
 * @param  array<int, array{sku: string, reason: string}>  $blocked
 */
class VariantSet
{
    public function __construct(
        public readonly array $productOptions,
        public readonly array $variants,
        public readonly array $blocked,
    ) {}
}
