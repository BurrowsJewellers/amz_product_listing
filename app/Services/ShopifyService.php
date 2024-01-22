<?php

namespace App\Services;

use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ShopifyService extends ShopifyConnectionService
{

    public function saveProductToDb($productData): bool
    {
        try {
            DB::beginTransaction();

            ShopifyProduct::updateOrCreate(
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

            if (isset($productData['variants']) && count($productData['variants']) > 0) {
                foreach ($productData['variants'] as $variant) {
                    ShopifyProductVariant::updateOrCreate(
                        [
                            'variant_id' => $variant['id'],
                        ],
                        [
                            'product_id' => $variant['product_id'],
                            'title' => $variant['title'],
                            'price' => $variant['price'],
                            'sku' => $variant['sku'],
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
}
