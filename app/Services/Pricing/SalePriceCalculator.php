<?php

namespace App\Services\Pricing;

use App\Models\RetailEdgeProduct;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Computes the effective selling price from retail, special and catalogue prices.
 *
 * Rule: a special or catalogue price only counts while now() is within its
 * start–end window (both dates required, inclusive). When both are active the
 * lower one wins; an active sale price at or above retail is ignored.
 */
class SalePriceCalculator
{
    /** Edge/EWeb sends this in place of a real date when none is set. */
    public const NO_DATE_SENTINEL = '0001-01-01T00:00:00';

    /**
     * Price a raw SOAP item from EWeb (PascalCase fields, UTC date strings,
     * 0001-01-01 sentinel for "no date").
     */
    public function fromEWebItem(object $item): SalePrice
    {
        return $this->calculate(
            retail: (float) ($item->RetailPrice ?? 0),
            special: (float) ($item->SpecialPrice ?? 0),
            specialStart: $this->parseDate($item->SpecialPriceStart ?? null, 'UTC'),
            specialEnd: $this->parseDate($item->SpecialPriceEnd ?? null, 'UTC'),
            catalogue: (float) ($item->CataloguePrice ?? 0),
            catalogueStart: $this->parseDate($item->CataloguePriceStart ?? null, 'UTC'),
            catalogueEnd: $this->parseDate($item->CataloguePriceEnd ?? null, 'UTC'),
        );
    }

    /**
     * Price a retail_edge_products row (dates already stored in the app
     * timezone; sentinels were nulled at ingest).
     */
    public function fromModel(RetailEdgeProduct $product): SalePrice
    {
        $timezone = config('app.timezone');

        return $this->calculate(
            retail: (float) ($product->retail_price1 ?? 0),
            special: (float) ($product->special_price ?? 0),
            specialStart: $this->parseDate($product->special_price_start, $timezone),
            specialEnd: $this->parseDate($product->special_price_end, $timezone),
            catalogue: (float) ($product->catalogue_price ?? 0),
            catalogueStart: $this->parseDate($product->catalogue_price_start, $timezone),
            catalogueEnd: $this->parseDate($product->catalogue_price_end, $timezone),
        );
    }

    public function calculate(
        float $retail,
        float $special,
        ?CarbonInterface $specialStart,
        ?CarbonInterface $specialEnd,
        float $catalogue,
        ?CarbonInterface $catalogueStart,
        ?CarbonInterface $catalogueEnd,
    ): SalePrice {
        $candidates = [];

        if ($special > 0 && $this->windowActive($specialStart, $specialEnd)) {
            $candidates[] = $special;
        }

        if ($catalogue > 0 && $this->windowActive($catalogueStart, $catalogueEnd)) {
            $candidates[] = $catalogue;
        }

        $sale = empty($candidates) ? null : min($candidates);

        // No retail price to compare against: sell at the sale price if one
        // is active, without a strikethrough.
        if ($retail <= 0) {
            return new SalePrice($sale ?? $retail, 0);
        }

        // No active sale, or the "sale" would not undercut retail.
        if ($sale === null || $sale >= $retail) {
            return new SalePrice($retail, 0);
        }

        return new SalePrice($sale, $retail);
    }

    private function windowActive(?CarbonInterface $start, ?CarbonInterface $end): bool
    {
        return $start !== null && $end !== null && now()->between($start, $end);
    }

    /**
     * Parse a date value, returning null for null/empty values, the
     * 0001-01-01 sentinel, or anything unparseable.
     */
    private function parseDate(mixed $value, string $timezone): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (empty($value) || $value === self::NO_DATE_SENTINEL) {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone)->setTimezone(config('app.timezone'));
        } catch (\Exception $e) {
            return null;
        }
    }
}
