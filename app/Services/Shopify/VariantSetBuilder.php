<?php

namespace App\Services\Shopify;

use App\Models\RetailEdgeProduct;
use Illuminate\Support\Collection;

/**
 * Builds the complete variant set for a product family (parent + children) from the
 * RetailEdge VT (variant type) model, ready for a Shopify productSet mutation.
 *
 * Key behaviours:
 *  - The parent row is included as a variant (it is a real sellable item; the
 *    children() relation excludes it, so we prepend it here).
 *  - id3 encodes the family's variant axes (VT1..VT4). VT1 (size/length) reads
 *    ring_size for Rings and bracelet_length for every other category.
 */
class VariantSetBuilder
{
    /** Axis code => Shopify option name, in option position order. */
    private const AXES = ['vt1' => 'Size', 'vt2' => 'Color', 'vt3' => 'Material', 'vt4' => 'Style'];

    public function build(RetailEdgeProduct $parent): VariantSet
    {
        $rows = $this->familyRows($parent);
        $axes = $this->familyAxes($rows);

        $variants = [];
        $blocked = [];
        $seenTuples = [];

        foreach ($rows as $row) {
            $optionValues = $this->optionValues($row, $axes);

            // A row that can't supply a value for every family axis can't be told apart.
            if ($this->hasEmptyAxis($optionValues)) {
                $blocked[] = ['sku' => $row->sku, 'reason' => 'empty_axis'];

                continue;
            }

            // Two rows with the same option tuple collapse to one Shopify variant.
            $tuple = $this->tupleKey($optionValues);
            if (isset($seenTuples[$tuple])) {
                $blocked[] = ['sku' => $row->sku, 'reason' => 'duplicate'];

                continue;
            }
            $seenTuples[$tuple] = true;

            $variants[] = $this->toVariant($row, $optionValues);
        }

        return new VariantSet(
            productOptions: $this->productOptions($axes, $variants),
            variants: $variants,
            blocked: $blocked,
        );
    }

    /** Parent first, then its children (the relation already excludes the parent). */
    private function familyRows(RetailEdgeProduct $parent): Collection
    {
        $children = $parent->children ?? new Collection;

        return (new Collection([$parent]))->merge($children);
    }

    /** Union of every row's declared axes, in canonical VT1<VT2<VT3<VT4 order. */
    private function familyAxes(Collection $rows): array
    {
        $found = [];
        foreach ($rows as $row) {
            foreach ($this->parseAxes($row->id3) as $vt) {
                $found[$vt] = true;
            }
        }

        return array_values(array_filter(array_keys(self::AXES), fn ($vt) => isset($found[$vt])));
    }

    /** @return array<int, string> axis codes present in id3, e.g. ['vt1','vt2'] */
    private function parseAxes(?string $id3): array
    {
        $parts = array_filter(array_map('trim', array_map('strtolower', explode('-', (string) $id3))));

        return array_values(array_filter($parts, fn ($p) => isset(self::AXES[$p])));
    }

    /**
     * Resolve this row's value for every family axis. A null `name` marks an axis
     * the row cannot fill.
     *
     * @param  array<int, string>  $axes
     * @return array<int, array{optionName: string, name: ?string}>
     */
    private function optionValues(RetailEdgeProduct $row, array $axes): array
    {
        $optionValues = [];
        foreach ($axes as $vt) {
            $optionValues[] = ['optionName' => self::AXES[$vt], 'name' => $this->axisValue($row, $vt)];
        }

        return $optionValues;
    }

    /** @param array<int, array{optionName: string, name: ?string}> $optionValues */
    private function hasEmptyAxis(array $optionValues): bool
    {
        foreach ($optionValues as $ov) {
            if ($ov['name'] === null) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array{optionName: string, name: ?string}> $optionValues */
    private function tupleKey(array $optionValues): string
    {
        return implode("\x1f", array_map(fn ($ov) => (string) $ov['name'], $optionValues));
    }

    /** @param array<int, array{optionName: string, name: string}> $optionValues */
    private function toVariant(RetailEdgeProduct $row, array $optionValues): array
    {
        [$price, $compareAt] = $this->prices($row);

        return [
            'sku' => $row->sku,
            'optionValues' => $optionValues,
            'price' => $price,
            'compareAtPrice' => $compareAt,
            'barcode' => $row->barcode,
        ];
    }

    /** Resolve a row's value for one axis; '', null and 'N/A' all become null. */
    private function axisValue(RetailEdgeProduct $row, string $vt): ?string
    {
        $value = match ($vt) {
            'vt1' => $row->s_cat === 'Rings' ? $row->ring_size : $row->bracelet_length,
            'vt2' => $row->metal_colour,
            'vt3' => $row->s_metal_type,
            'vt4' => $row->pendant_style,
            default => null,
        };

        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '' || strtoupper((string) $value) === 'N/A') {
            return null;
        }

        return (string) $value;
    }

    /** @return array{0: string, 1: ?string} [price, compareAtPrice] */
    private function prices(RetailEdgeProduct $row): array
    {
        $prices = array_filter(
            array_map('floatval', [$row->retail_price1, $row->retail_price2]),
            fn ($p) => $p > 0
        );

        if (empty($prices)) {
            return ['0', null];
        }

        $min = min($prices);
        $max = max($prices);

        return [(string) $min, $min === $max ? null : (string) $max];
    }

    /**
     * Build productOptions from the accepted variant tuples: one option per axis,
     * values in first-seen order.
     *
     * @param  array<int, string>  $axes
     * @param  array<int, array>  $variants
     */
    private function productOptions(array $axes, array $variants): array
    {
        $options = [];
        foreach ($axes as $position => $vt) {
            $name = self::AXES[$vt];
            $values = [];
            foreach ($variants as $variant) {
                foreach ($variant['optionValues'] as $ov) {
                    if ($ov['optionName'] === $name && $ov['name'] !== null && ! in_array($ov['name'], $values, true)) {
                        $values[] = $ov['name'];
                    }
                }
            }
            $options[] = ['name' => $name, 'position' => $position + 1, 'values' => $values];
        }

        return $options;
    }
}
