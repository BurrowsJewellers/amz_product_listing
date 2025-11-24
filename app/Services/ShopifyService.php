<?php

namespace App\Services;

use App\Models\RetailEdgeProduct;
use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2025_04\Image;

class ShopifyService extends ShopifyConnectionService
{
    public function saveProductToDb($productData)
    {
        try {
            DB::beginTransaction();
            Log::info('ShopifyService: Starting saveProductToDb transaction for product ID: '.($productData['id'] ?? 'unknown'));
            foreach ($productData['variants'] as $variant) {
                if ($shopifyProductVariant = ShopifyProductVariant::where('variant_id', $variant['id'])->first()) {

                    $shopifyProduct = ShopifyProduct::updateOrCreate(
                        [
                            'product_id' => $productData['id'],
                        ],
                        [
                            'title' => $productData['title'],
                            'vendor' => $productData['vendor'] ?? null,
                            'product_type' => $productData['product_type'] ?? null,
                            'handle' => $productData['handle'],
                            'tags' => is_array($productData['tags']) ? implode(',', $productData['tags']) : ($productData['tags'] ?? ''),
                            'status' => $productData['status'],
                        ]
                    );

                    $shopifyProductVariant->update(
                        [
                            'product_id' => $variant['product_id'] ?? $productData['id'],
                            'title' => $variant['title'] ?? null,
                            'price' => $variant['price'],
                            'sku' => $variant['sku'],
                            'position' => $variant['position'] ?? 1,
                            'inventory_policy' => $variant['inventory_policy'] ?? 'deny',
                            'fulfillment_service' => $variant['fulfillment_service'] ?? 'manual',
                            'inventory_management' => $variant['inventory_management'] ?? 'shopify',
                            'option1' => $variant['option1'] ?? null,
                            'option2' => $variant['option2'] ?? null,
                            'option3' => $variant['option3'] ?? null,
                            'taxable' => $variant['taxable'] ?? true,
                            'barcode' => $variant['barcode'],
                            'grams' => $variant['grams'] ?? 0,
                            'weight' => $variant['weight'] ?? 0,
                            'inventory_item_id' => $variant['inventory_item_id'] ?? null,
                            'inventory_item_gid' => $variant['inventory_item_gid'] ?? null,
                            'inventory_quantity' => $variant['inventory_quantity'] ?? 0,
                            'old_inventory_quantity' => $variant['old_inventory_quantity'] ?? 0,
                            'requires_shipping' => $variant['requires_shipping'] ?? true,
                        ]
                    );
                } else {
                    $shopifyProduct = ShopifyProduct::updateOrCreate(
                        [
                            'product_id' => $productData['id'],
                        ],
                        [
                            'title' => $productData['title'],
                            'vendor' => $productData['vendor'] ?? null,
                            'product_type' => $productData['product_type'] ?? null,
                            'handle' => $productData['handle'],
                            'tags' => is_array($productData['tags']) ? implode(',', $productData['tags']) : ($productData['tags'] ?? ''),
                            'status' => $productData['status'],
                        ]
                    );

                    $shopifyProductVariant = ShopifyProductVariant::create(
                        [
                            'shopify_product_id' => $shopifyProduct->id,
                            'sku' => $variant['sku'],
                            'variant_id' => $variant['id'],
                            'product_id' => $shopifyProduct->product_id,
                            'title' => $variant['title'] ?? null,
                            'price' => $variant['price'],
                            'compare_at_price' => $variant['compare_at_price'] ? $variant['compare_at_price'] : 0,
                            'position' => $variant['position'] ?? 1,
                            'inventory_policy' => $variant['inventory_policy'] ?? 'deny',
                            'fulfillment_service' => $variant['fulfillment_service'] ?? 'manual',
                            'inventory_management' => $variant['inventory_management'] ?? 'shopify',
                            'option1' => $variant['option1'] ?? null,
                            'option2' => $variant['option2'] ?? null,
                            'option3' => $variant['option3'] ?? null,
                            'taxable' => $variant['taxable'] ?? true,
                            'barcode' => $variant['barcode'],
                            'grams' => $variant['grams'] ?? 0,
                            'weight' => $variant['weight'] ?? 0,
                            'inventory_item_id' => $variant['inventory_item_id'] ?? null,
                            'inventory_item_gid' => $variant['inventory_item_gid'] ?? null,
                            'inventory_quantity' => $variant['inventory_quantity'] ?? 0,
                            'old_inventory_quantity' => isset($variant['old_inventory_quantity']) ? $variant['old_inventory_quantity'] : 0,
                            'requires_shipping' => $variant['requires_shipping'] ?? true,
                            'price_requires_update' => 1,
                            'inventory_requires_update' => 1,
                            'images_requires_update' => 1,
                        ]
                    );
                }

                if ($shopifyProductVariant) {
                    // Debug: Check if the SKU exists in retail_edge_products
                    $existingProduct = RetailEdgeProduct::where('sku', $shopifyProductVariant->sku)->first();
                    if ($existingProduct) {
                        Log::info("ShopifyService: Found RetailEdgeProduct for SKU: {$shopifyProductVariant->sku}, current uploaded_to_shopify: {$existingProduct->uploaded_to_shopify}");
                    } else {
                        Log::warning("ShopifyService: No RetailEdgeProduct found for SKU: {$shopifyProductVariant->sku} - checking for case sensitivity issues");
                        // Try case-insensitive search
                        $caseInsensitiveProduct = RetailEdgeProduct::whereRaw('LOWER(sku) = LOWER(?)', [$shopifyProductVariant->sku])->first();
                        if ($caseInsensitiveProduct) {
                            Log::warning("ShopifyService: Found product with different case - DB SKU: {$caseInsensitiveProduct->sku}, Shopify SKU: {$shopifyProductVariant->sku}");
                        }
                    }

                    $updatedCount = RetailEdgeProduct::where('sku', $shopifyProductVariant->sku)->update(['uploaded_to_shopify' => 1]);
                    if ($updatedCount > 0) {
                        Log::info("ShopifyService: Marked {$updatedCount} RetailEdgeProduct(s) as uploaded_to_shopify for SKU: {$shopifyProductVariant->sku}");
                    } else {
                        Log::warning("ShopifyService: No RetailEdgeProduct found to update for SKU: {$shopifyProductVariant->sku}");
                    }
                }
            }

            DB::commit();
            Log::info('ShopifyService: Transaction committed successfully for saveProductToDb');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ShopifyService: Transaction rolled back in saveProductToDb: '.$e->getMessage());
            throw $e;
        }
    }

    public function saveProductToDbNewVersion($productData)
    {
        try {
            DB::beginTransaction();
            foreach ($productData['variants'] as $variant) {
                if ($shopifyProductVariant = ShopifyProductVariant::where('variant_id', $variant['id'])->first()) {
                    $shopifyProductVariant->update(
                        [
                            'product_id' => $variant['product_id'],
                            'title' => $variant['title'],
                            'price' => $variant['price'],
                            'position' => $variant['position'],
                            'inventory_policy' => $variant['inventory_policy'],
                            'fulfillment_service' => $variant['fulfillment_service'],
                            'inventory_management' => $variant['inventory_management'],
                            'option1' => $variant['option1'],
                            'option2' => $variant['option2'],
                            'option3' => $variant['option3'],
                            'taxable' => $variant['taxable'],
                            'barcode' => $variant['barcode'],
                            'grams' => $variant['grams'],
                            'weight' => $variant['weight'],
                            'inventory_item_id' => $variant['inventory_item_id'],
                            'inventory_item_gid' => $variant['inventory_item_gid'] ?? null,
                            'inventory_quantity' => $variant['inventory_quantity'],
                            'old_inventory_quantity' => $variant['old_inventory_quantity'],
                            'requires_shipping' => $variant['requires_shipping'],
                        ]
                    );
                } else {
                    $product = RetailEdgeProduct::where('sku', $variant['sku'])->with('parent')->first();
                    $shopifyProduct = ShopifyProduct::firstOrCreate(
                        [
                            'sku' => $product->parent->sku,
                        ],
                        [
                            'product_id' => $productData['id'],
                            'title' => $productData['title'],
                            'vendor' => $productData['vendor'],
                            'product_type' => $productData['product_type'],
                            'handle' => $productData['handle'],
                            'tags' => $productData['tags'],
                            'status' => $productData['status'],
                        ]
                    );

                    $shopifyProductVariant = ShopifyProductVariant::create(
                        [
                            'shopify_product_id' => $shopifyProduct->id,
                            'sku' => $variant['sku'],
                            'variant_id' => $variant['id'],
                            'product_id' => $shopifyProduct->product_id,
                            'title' => $variant['title'],
                            'price' => $variant['price'],
                            'compare_at_price' => $variant['compare_at_price'] ? $variant['compare_at_price'] : 0,
                            'position' => $variant['position'],
                            'inventory_policy' => $variant['inventory_policy'],
                            'fulfillment_service' => $variant['fulfillment_service'],
                            'inventory_management' => $variant['inventory_management'],
                            'option1' => $variant['option1'],
                            'option2' => $variant['option2'],
                            'option3' => $variant['option3'],
                            'taxable' => $variant['taxable'],
                            'barcode' => $variant['barcode'],
                            'grams' => $variant['grams'],
                            'weight' => $variant['weight'],
                            'inventory_item_id' => $variant['inventory_item_id'],
                            'inventory_item_gid' => $variant['inventory_item_gid'] ?? null,
                            'inventory_quantity' => $variant['inventory_quantity'],
                            'old_inventory_quantity' => isset($variant['old_inventory_quantity']) ? $variant['old_inventory_quantity'] : 0,
                            'requires_shipping' => $variant['requires_shipping'],
                        ]
                    );
                }

                if ($shopifyProductVariant) {
                    // Debug: Check if the SKU exists in retail_edge_products
                    $existingProduct = RetailEdgeProduct::where('sku', $shopifyProductVariant->sku)->first();
                    if ($existingProduct) {
                        Log::info("ShopifyService (NewVersion): Found RetailEdgeProduct for SKU: {$shopifyProductVariant->sku}, current uploaded_to_shopify: {$existingProduct->uploaded_to_shopify}");
                    } else {
                        Log::warning("ShopifyService (NewVersion): No RetailEdgeProduct found for SKU: {$shopifyProductVariant->sku}");
                    }

                    $updatedCount = RetailEdgeProduct::where('sku', $shopifyProductVariant->sku)->update(['uploaded_to_shopify' => 1]);
                    if ($updatedCount > 0) {
                        Log::info("ShopifyService (NewVersion): Marked {$updatedCount} RetailEdgeProduct(s) as uploaded_to_shopify for SKU: {$shopifyProductVariant->sku}");
                    } else {
                        Log::warning("ShopifyService (NewVersion): No RetailEdgeProduct found to update for SKU: {$shopifyProductVariant->sku}");
                    }
                }
            }

            DB::commit();
            Log::info('ShopifyService (NewVersion): Transaction committed successfully for saveProductToDbNewVersion');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ShopifyService (NewVersion): Transaction rolled back in saveProductToDbNewVersion: '.$e->getMessage());
            throw $e;
        }
    }

    public function saveInventoryLevelToDb($inventoryLevelData)
    {
        try {
            return ShopifyInventoryLevel::updateOrCreate(
                [
                    'location_id' => $inventoryLevelData['location_id'],
                    'inventory_item_id' => $inventoryLevelData['inventory_item_id'],

                ],
                [
                    'available' => $inventoryLevelData['available'] ?? 0,
                    'inventory_updated_at' => Carbon::parse($inventoryLevelData['updated_at']),
                ]
            );
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function deleteImagesByProductId(string $productId)
    {
        $session = $this->getSession();
        $images = Image::all(
            $session,
            ['product_id' => $productId]
        );
        foreach ($images as $image) {
            Image::delete(
                $session,
                $image->id,
                ['product_id' => $productId],
            );
        }

        return true;
    }

    public function uploadImages(ShopifyProductVariant $variant, string $imageContent)
    {
        try {
            $session = $this->getSession();

            try {
                $image = new Image($session);
                $image->product_id = $variant->product_id;
                $image->attachment = $imageContent;
                $image->variant_ids = [
                    $variant->variant_id,
                ];

                $image->save(
                    true,
                );
                $variant->update(['images_requires_update' => 0]);

                return 'ok';
            } catch (\Exception $e) {
                Log::debug("There was an error while uploading the images for {$variant->sku}. Error message : {$e->getMessage()}");
                report($e);
                $variant->update(['images_requires_update' => 2]);
            }
        } catch (\Exception $e) {
            throw ($e);
        }
    }
}
