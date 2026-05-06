<?php

namespace App\Services;

use App\Models\RetailEdgeProduct;
use App\Models\RetailEdgeProductIsd;

class MetafieldAssignmentService
{
    /**
     * Determine how metafields should be assigned for a given product
     */
    public function determineMetafieldAssignment(RetailEdgeProduct $product): array
    {
        $children = $product->children;
        $childrenCount = $children->count();

        if ($childrenCount <= 1) {
            $isds = $this->getAllProductISDs($product);

            return [
                'type' => 'PRODUCT_ONLY',
                'product_metafields' => $isds['product_metafields'],
                'variant_metafields' => $isds['variant_metafields'],
            ];
        }

        // Multiple variants: analyze for common vs variant-specific
        return $this->analyzeForMultiVariant($product, $children);
    }

    /**
     * Get all ISDs for a product (used when single variant or no variants)
     */
    private function getAllProductISDs(RetailEdgeProduct $product): array
    {
        $productMetafields = [];
        $variantMetafields = [];

        $singleChild = $product->children->count() === 1 ? $product->children->first() : null;
        $targetSku = $singleChild ? $singleChild->sku : $product->sku;

        $isds = RetailEdgeProductIsd::where('sku', $targetSku)->get();

        foreach ($isds as $isd) {
            if (empty($isd->isd_value)) {
                continue;
            }

            $productMetafields[] = [
                'isd_name' => $isd->isd_name,
                'value' => $isd->isd_value,
                'key_suffix' => '_product',
            ];

            if ($singleChild) {
                $variantMetafields[$singleChild->sku][] = [
                    'isd_name' => $isd->isd_name,
                    'value' => $isd->isd_value,
                    'key_suffix' => '_variant',
                ];
            }
        }

        return [
            'product_metafields' => $productMetafields,
            'variant_metafields' => $variantMetafields,
        ];
    }

    /**
     * Analyze metafields for multi-variant products
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $children
     */
    private function analyzeForMultiVariant(RetailEdgeProduct $product, $children): array
    {
        $allISDs = [];
        $commonMetafields = [];
        $variantMetafields = [];

        // Collect all ISDs from all children
        foreach ($children as $child) {
            $childISDs = RetailEdgeProductIsd::where('sku', $child->sku)->get();
            foreach ($childISDs as $isd) {
                if (! empty($isd->isd_value)) {
                    $allISDs[$isd->isd_name][$child->sku] = $isd->isd_value;
                }
            }
        }

        // Analyze each ISD for commonality
        foreach ($allISDs as $isdName => $values) {
            $uniqueValues = array_unique($values);
            $childrenWithThisISD = count($values);

            // Skip if not all children have this ISD (as per requirement)
            if ($childrenWithThisISD < $children->count()) {
                continue;
            }

            if (count($uniqueValues) === 1) {
                // Common value across all variants → Product metafield AND each variant
                $sharedValue = reset($uniqueValues);

                $commonMetafields[] = [
                    'isd_name' => $isdName,
                    'value' => $sharedValue,
                    'key_suffix' => '_product',
                ];

                foreach (array_keys($values) as $childSku) {
                    $variantMetafields[$childSku][] = [
                        'isd_name' => $isdName,
                        'value' => $sharedValue,
                        'key_suffix' => '_variant',
                    ];
                }
            } else {
                // Different values → Variant metafields
                foreach ($values as $sku => $value) {
                    $variantMetafields[$sku][] = [
                        'isd_name' => $isdName,
                        'value' => $value,
                        'key_suffix' => '_variant',
                    ];
                }
            }
        }

        return [
            'type' => 'MIXED',
            'product_metafields' => $commonMetafields,
            'variant_metafields' => $variantMetafields,
        ];
    }
}
