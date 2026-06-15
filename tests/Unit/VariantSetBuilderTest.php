<?php

namespace Tests\Unit;

use App\Models\RetailEdgeProduct;
use App\Services\Shopify\VariantSetBuilder;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Unit tests for VariantSetBuilder — pure variant construction from a RetailEdge
 * parent + its children. No DB, no Shopify: models are hydrated in memory and the
 * children relation is set directly, so this never touches the configured database.
 */
class VariantSetBuilderTest extends TestCase
{
    private function product(array $attrs): RetailEdgeProduct
    {
        $p = new RetailEdgeProduct;
        $p->forceFill($attrs);

        return $p;
    }

    private function withChildren(RetailEdgeProduct $parent, array $children): RetailEdgeProduct
    {
        $parent->setRelation('children', new Collection($children));

        return $parent;
    }

    public function test_parent_is_included_as_a_variant_alongside_its_children(): void
    {
        // Ring family: parent is size 50, children are 52 and 54. The parent's own
        // size must appear as a variant (this is the dropped-parent regression).
        $parent = $this->withChildren(
            $this->product(['sku' => '021-09523', 'old_key' => '021-09523', 'id3' => 'VT1', 's_cat' => 'Rings', 'ring_size' => '50']),
            [
                $this->product(['sku' => '021-09524', 'old_key' => '021-09523', 'id3' => 'VT1', 's_cat' => 'Rings', 'ring_size' => '52']),
                $this->product(['sku' => '021-09525', 'old_key' => '021-09523', 'id3' => 'VT1', 's_cat' => 'Rings', 'ring_size' => '54']),
            ]
        );

        $set = (new VariantSetBuilder)->build($parent);

        $skus = array_column($set->variants, 'sku');
        $this->assertContains('021-09523', $skus, 'parent SKU must be listed as a variant');
        $this->assertContains('021-09524', $skus);
        $this->assertContains('021-09525', $skus);
        $this->assertEmpty($set->blocked);
    }

    public function test_chain_family_uses_bracelet_length_for_size_giving_distinct_variants(): void
    {
        // Chains store their length in bracelet_length (not ring_size). With VT1+VT2,
        // all four (Color, Length) tuples are distinct, so none collapse.
        $parent = $this->withChildren(
            $this->product(['sku' => '022-06122', 'old_key' => '022-06122', 'id3' => 'VT1 - VT2', 's_cat' => 'Chains', 'bracelet_length' => '45', 'metal_colour' => 'Yellow Gold']),
            [
                $this->product(['sku' => '022-06123', 'old_key' => '022-06122', 'id3' => 'VT1 - VT2', 's_cat' => 'Chains', 'bracelet_length' => '70cm', 'metal_colour' => 'Yellow Gold']),
                $this->product(['sku' => '022-06125', 'old_key' => '022-06122', 'id3' => 'VT1 - VT2', 's_cat' => 'Chains', 'bracelet_length' => '45', 'metal_colour' => 'Sterling Silver']),
                $this->product(['sku' => '022-06126', 'old_key' => '022-06122', 'id3' => 'VT1 - VT2', 's_cat' => 'Chains', 'bracelet_length' => '70cm', 'metal_colour' => 'Sterling Silver']),
            ]
        );

        $set = (new VariantSetBuilder)->build($parent);

        $this->assertCount(4, $set->variants);
        $this->assertEmpty($set->blocked);
        $this->assertEqualsCanonicalizing(
            ['022-06122', '022-06123', '022-06125', '022-06126'],
            array_column($set->variants, 'sku')
        );
    }

    public function test_row_with_an_empty_axis_value_is_blocked_not_listed(): void
    {
        // A watchband family uses VT1 (size) but one child has no length at all.
        // That child cannot form a complete tuple, so it is flagged, not listed.
        $parent = $this->withChildren(
            $this->product(['sku' => 'WB-1', 'old_key' => 'WB-1', 'id3' => 'VT1', 's_cat' => 'Watchbands', 'bracelet_length' => '18']),
            [
                $this->product(['sku' => 'WB-2', 'old_key' => 'WB-1', 'id3' => 'VT1', 's_cat' => 'Watchbands', 'bracelet_length' => '']),
            ]
        );

        $set = (new VariantSetBuilder)->build($parent);

        $this->assertContains('WB-1', array_column($set->variants, 'sku'));
        $this->assertNotContains('WB-2', array_column($set->variants, 'sku'));
        $this->assertSame('WB-2', $set->blocked[0]['sku']);
        $this->assertSame('empty_axis', $set->blocked[0]['reason']);
    }

    public function test_duplicate_option_tuple_is_blocked_keeping_the_first(): void
    {
        // Two Sterling Silver chains with the SAME length => identical (Color, Length)
        // tuple. Shopify can't hold two identical variants, so the second is blocked.
        $parent = $this->withChildren(
            $this->product(['sku' => 'C-1', 'old_key' => 'C-1', 'id3' => 'VT1 - VT2', 's_cat' => 'Chains', 'bracelet_length' => '45', 'metal_colour' => 'Sterling Silver']),
            [
                $this->product(['sku' => 'C-2', 'old_key' => 'C-1', 'id3' => 'VT1 - VT2', 's_cat' => 'Chains', 'bracelet_length' => '45', 'metal_colour' => 'Sterling Silver']),
            ]
        );

        $set = (new VariantSetBuilder)->build($parent);

        $this->assertContains('C-1', array_column($set->variants, 'sku'));
        $this->assertNotContains('C-2', array_column($set->variants, 'sku'));
        $this->assertSame('C-2', $set->blocked[0]['sku']);
        $this->assertSame('duplicate', $set->blocked[0]['reason']);
    }

    public function test_product_options_list_each_used_axis_with_its_distinct_values(): void
    {
        $parent = $this->withChildren(
            $this->product(['sku' => '021-09535', 'old_key' => '021-09535', 'id3' => 'VT1', 's_cat' => 'Rings', 'ring_size' => '52']),
            [
                $this->product(['sku' => '021-09536', 'old_key' => '021-09535', 'id3' => 'VT1', 's_cat' => 'Rings', 'ring_size' => '54']),
                $this->product(['sku' => '021-09537', 'old_key' => '021-09535', 'id3' => 'VT1', 's_cat' => 'Rings', 'ring_size' => '56']),
            ]
        );

        $set = (new VariantSetBuilder)->build($parent);

        $this->assertCount(1, $set->productOptions);
        $this->assertSame('Size', $set->productOptions[0]['name']);
        $this->assertSame(1, $set->productOptions[0]['position']);
        $this->assertSame(['52', '54', '56'], $set->productOptions[0]['values']);
    }

    public function test_mixed_id3_family_uses_the_union_of_axes_and_blocks_rows_missing_one(): void
    {
        // Parent declares only VT1 (size, no colour); the child declares VT1+VT2.
        // The family axes become the union {Size, Color}; the parent can't fill Color,
        // so it is flagged for review rather than silently mis-listed.
        $parent = $this->withChildren(
            $this->product(['sku' => 'M-1', 'old_key' => 'M-1', 'id3' => 'VT1', 's_cat' => 'Rings', 'ring_size' => '50']),
            [
                $this->product(['sku' => 'M-2', 'old_key' => 'M-1', 'id3' => 'VT1 - VT2', 's_cat' => 'Rings', 'ring_size' => '52', 'metal_colour' => 'Yellow Gold']),
            ]
        );

        $set = (new VariantSetBuilder)->build($parent);

        $this->assertSame(['Size', 'Color'], array_column($set->productOptions, 'name'));
        $this->assertContains('M-2', array_column($set->variants, 'sku'));
        $this->assertSame('M-1', $set->blocked[0]['sku']);
        $this->assertSame('empty_axis', $set->blocked[0]['reason']);
    }
}
