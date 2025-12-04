<?php

namespace App\Traits;

use App\Models\RetailEdgeProduct;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use Illuminate\Support\Facades\Log;

/**
 * Trait for cleaning up stale Shopify records
 *
 * Used by Shopify commands that need to handle cases where
 * products/variants no longer exist on Shopify but still have
 * local database records.
 */
trait ShopifyCleanupTrait
{
    /**
     * Clean up a stale variant record that no longer exists on Shopify
     *
     * @param  ShopifyProductVariant  $variant  The variant to clean up
     * @param  string  $context  Context for logging (e.g., command name)
     * @return bool True if cleanup was successful
     */
    protected function cleanupStaleVariant(ShopifyProductVariant $variant, string $context = 'ShopifyCleanup'): bool
    {
        try {
            $sku = $variant->sku ?: '[EMPTY SKU]';
            $productId = $variant->shopify_product_id;

            // Force delete variant record
            $variant->forceDelete();

            // Check if product has other variants, if not delete product too
            $remainingVariants = ShopifyProductVariant::where('shopify_product_id', $productId)->count();
            if ($remainingVariants === 0 && $productId) {
                ShopifyProduct::where('id', $productId)->forceDelete();
            }

            // Reset RetailEdge uploaded flag so product can be recreated
            RetailEdgeProduct::where('sku', $sku)->update(['uploaded_to_shopify' => 0]);

            if (property_exists($this, 'output') && method_exists($this, 'info')) {
                $this->info("Cleaned stale variant: {$sku}");
            }

            Log::info("{$context}: Auto-cleaned stale variant: {$sku}");

            return true;
        } catch (\Throwable $e) {
            Log::error("{$context}: Failed to clean stale variant: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Clean up a stale product record that no longer exists on Shopify
     *
     * @param  ShopifyProduct|object  $product  The product to clean up (can be model or stdClass)
     * @param  string  $context  Context for logging (e.g., command name)
     * @return bool True if cleanup was successful
     */
    protected function cleanupStaleProduct(object $product, string $context = 'ShopifyCleanup'): bool
    {
        try {
            // Handle both ShopifyProduct model and stdClass from raw queries
            if ($product instanceof ShopifyProduct) {
                $shopifyProduct = $product;
                $title = $product->title ?? 'Unknown';
                $sku = $product->sku ?? 'unknown';
            } else {
                // It's a stdClass from a raw query
                $shopifyProduct = ShopifyProduct::find($product->pid ?? $product->id ?? null);
                $title = $product->title ?? 'Unknown';
                $sku = $shopifyProduct?->sku ?? 'unknown';

                if (! $shopifyProduct) {
                    Log::warning("{$context}: Could not find ShopifyProduct for cleanup");

                    return false;
                }
            }

            // Delete variants first
            $shopifyProduct->variants()->forceDelete();

            // Delete the product
            $shopifyProduct->forceDelete();

            // Reset RetailEdge uploaded flag so product can be recreated if needed
            if ($sku !== 'unknown') {
                RetailEdgeProduct::where('sku', $sku)->update(['uploaded_to_shopify' => 0]);
            }

            if (property_exists($this, 'output') && method_exists($this, 'info')) {
                $this->info("Cleaned stale product: {$title}");
            }

            Log::info("{$context}: Auto-cleaned stale product: {$title}");

            return true;
        } catch (\Throwable $e) {
            $title = $product->title ?? 'unknown';
            Log::error("{$context}: Failed to clean stale product {$title}: ".$e->getMessage());

            return false;
        }
    }
}
