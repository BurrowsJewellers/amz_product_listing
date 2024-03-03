<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;

class ShopifyService extends ShopifyConnectionService
{

    public function saveProductToDb($productData): bool
    {
        try {
            DB::beginTransaction();

            $shopifyProduct = null;

            if (isset($productData['variants']) && count($productData['variants']) > 0) {
                foreach ($productData['variants'] as $variant) {

                    $shopifyProductVariant = ShopifyProductVariant::where('sku', $variant['sku'])->first();

                    if ($shopifyProductVariant) {
                        if ($shopifyProductVariant->product_id === null || $shopifyProduct === null) {
                            $shopifyProduct = ShopifyProduct::updateOrCreate(
                                [
                                    'id' => $shopifyProductVariant->shopify_product_id,
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
                        }

                        $shopifyProductVariant->update(
                            [
                                'shopify_product_id' => $shopifyProduct->id,
                                // 'sku' => $variant['sku'],
                                'variant_id' => $variant['id'],
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
                                'inventory_quantity' => $variant['inventory_quantity'],
                                'old_inventory_quantity' => $variant['old_inventory_quantity'],
                                'requires_shipping' => $variant['requires_shipping'],
                            ]
                        );
                    } else {
                        $shopifyProduct = ShopifyProduct::updateOrCreate(
                            [
                                'product_id' => $productData['id'],
                            ],
                            [
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
                                'inventory_quantity' => $variant['inventory_quantity'],
                                'old_inventory_quantity' => $variant['old_inventory_quantity'],
                                'requires_shipping' => $variant['requires_shipping'],
                            ]
                        );
                    }
                }
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return false;
        }
    }

    public function saveInventoryLevelToDb($inventoryLevelData): bool
    {
        try {
            if (ShopifyProductVariant::where(['inventory_item_id' => $inventoryLevelData['inventory_item_id']])->first()) {
                DB::beginTransaction();

                ShopifyInventoryLevel::updateOrCreate(
                    [
                        'location_id' => $inventoryLevelData['location_id'],
                        'inventory_item_id' => $inventoryLevelData['inventory_item_id'],

                    ],
                    [
                        'available' => $inventoryLevelData['available'],
                        'inventory_updated_at' => Carbon::parse($inventoryLevelData['updated_at']),
                    ]
                );

                DB::commit();
                return true;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return false;
        }
    }

    public function saveRetailEdgeProductToDb($productData): bool
    {
        return true;
    }
}
