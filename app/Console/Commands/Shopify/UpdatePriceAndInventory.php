<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Shopify\Rest\Admin2025_04\Variant;
use Shopify\Rest\Admin2025_04\InventoryLevel;
use Shopify\Rest\Admin2025_04\Product as ShopifyProductAPI; // Renamed to avoid conflict with Model
use App\Models\ShopifyLocation;
use App\Models\ShopifyProduct; // Eloquent Model
use App\Models\PriceInventoryLog;

class UpdatePriceAndInventory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyUpdatePriceInventory'; // Used for job_name in logs

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Updates product price and inventory on Shopify';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUpdatePriceInventory';

        $job = SyncJobController::getJob($jobType, $marketplace);

        // if (!$job->isRunning()) { // Assuming combined job should check its own status
        try {
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            $session = (new ShopifyService)->getSession();
            $location = ShopifyLocation::first();

            if (!$location) {
                Log::error("$marketplace $jobType failed: No Shopify location found for inventory updates.");
                $job->update(['status' => 0, 'message' => 'No Shopify location found for inventory updates. Price updates might have been attempted.']);
                // Decide if we should proceed with price updates or halt. For now, halting.
                return;
            }

            // --- Price Update Logic ---
            $this->info("Starting Price Updates...");
            $priceUpdateCount = ShopifyProductVariant::whereNotNull('variant_id')->where('price_requires_update', 1)->count();
            $this->info("Products requiring price update: {$priceUpdateCount}");

            while ($priceUpdateCount > 0) {
                $variantForPrice = ShopifyProductVariant::with('retailEdgeProduct')
                    ->whereNotNull('variant_id')
                    ->where('price_requires_update', 1)
                    ->first();

                if ($variantForPrice) {
                    $originalPrice = $variantForPrice->getOriginal('price');
                    $originalCompareAtPrice = $variantForPrice->getOriginal('compare_at_price');
                    try {
                        $shopifyVariantAPI = new Variant($session);
                        $shopifyVariantAPI->id = $variantForPrice->variant_id;
                        $shopifyVariantAPI->price = $variantForPrice->price;

                        if (isset($variantForPrice->compare_at_price) && $variantForPrice->compare_at_price !== $variantForPrice->price) {
                            $shopifyVariantAPI->compare_at_price = $variantForPrice->compare_at_price;
                        } elseif (isset($variantForPrice->compare_at_price) && $variantForPrice->compare_at_price === null) {
                            $shopifyVariantAPI->compare_at_price = null;
                        }

                        $shopifyVariantAPI->save(true); // Update Object

                        PriceInventoryLog::create([
                            'marketplace' => $marketplace,
                            'item_identifier' => $variantForPrice->sku ?? (string)$variantForPrice->variant_id,
                            'change_type' => 'price',
                            'from_value' => $originalPrice,
                            'to_value' => $variantForPrice->price,
                            'status' => 'success',
                            'job_name' => $this->signature,
                            'message' => "Price updated via API. Variant ID: {$variantForPrice->variant_id}",
                        ]);

                        if (isset($shopifyVariantAPI->compare_at_price)) {
                            PriceInventoryLog::create([
                                'marketplace' => $marketplace,
                                'item_identifier' => $variantForPrice->sku ?? (string)$variantForPrice->variant_id,
                                'change_type' => 'compare_at_price',
                                'from_value' => $originalCompareAtPrice,
                                'to_value' => $variantForPrice->compare_at_price,
                                'status' => 'success',
                                'job_name' => $this->signature,
                                'message' => "Compare_at_price updated via API. Variant ID: {$variantForPrice->variant_id}",
                            ]);
                        }

                        $this->info("Price updated for id {$variantForPrice->id}, sku {$variantForPrice->sku}, variant id {$variantForPrice->variant_id}");
                        $variantForPrice->update(['price_requires_update' => 0]);
                    } catch (\Exception $e) {
                        Log::error("Error updating price for SKU {$variantForPrice->sku} (Variant ID: {$variantForPrice->variant_id}). Error: {$e->getMessage()}");
                        PriceInventoryLog::create([
                            'marketplace' => $marketplace,
                            'item_identifier' => $variantForPrice->sku ?? (string)$variantForPrice->variant_id,
                            'change_type' => 'price',
                            'from_value' => $originalPrice,
                            'to_value' => $variantForPrice->price,
                            'status' => 'failed',
                            'job_name' => $this->signature,
                            'message' => "API Error: " . $e->getMessage(),
                        ]);
                        if (isset($variantForPrice->compare_at_price) && $variantForPrice->compare_at_price !== $originalCompareAtPrice) {
                            PriceInventoryLog::create([
                                'marketplace' => $marketplace,
                                'item_identifier' => $variantForPrice->sku ?? (string)$variantForPrice->variant_id,
                                'change_type' => 'compare_at_price',
                                'from_value' => $originalCompareAtPrice,
                                'to_value' => $variantForPrice->compare_at_price,
                                'status' => 'failed',
                                'job_name' => $this->signature,
                                'message' => "API Error (attempting compare_at_price): " . $e->getMessage(),
                            ]);
                        }
                        $variantForPrice->update(['price_requires_update' => 2]); // Mark as failed
                    }
                    usleep(config('shopify.delay', 1500000)); // Use configurable delay
                }
                $priceUpdateCount = ShopifyProductVariant::whereNotNull('variant_id')->where('price_requires_update', 1)->count();
                if ($priceUpdateCount > 0) {
                    $this->info("Remaining products for price update: {$priceUpdateCount}");
                }
            }
            $this->info("Price updates completed.");

            // --- Inventory Update Logic ---
            $this->info("Starting Inventory Updates...");
            // Process regular inventory updates
            $this->processRegularInventoryUpdates($location, $session);
            // Process failed inventory updates
            $this->processFailedInventoryUpdates($location, $session);
            $this->info("Inventory updates completed.");

            $job->update(['status' => 0, 'message' => null]);
            Log::info("$marketplace $jobType finished successfully!");
        } catch (\Exception $e) {
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
            report($e);
            Log::error("$marketplace $jobType failed. Error: {$e->getMessage()}");
            $this->error("Command failed: " . $e->getMessage());
        }
        // } else {
        //     Log::info("$marketplace $jobType is already running.");
        // }
    }

    /**
     * Process regular inventory updates
     */
    private function processRegularInventoryUpdates($location, $session)
    {
        $this->info("Processing regular inventory updates...");
        $inventoryUpdateCount = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->count();
        $this->info("Products requiring inventory update: {$inventoryUpdateCount}");

        while ($inventoryUpdateCount > 0) {
            $variantForInventory = ShopifyProductVariant::with(['retailEdgeProduct', 'product']) // 'product' is ShopifyProduct model
                ->whereNotNull('inventory_item_id')
                ->where('inventory_requires_update', 1)
                ->first();

            if ($variantForInventory) {
                if (!$variantForInventory->retailEdgeProduct) {
                    $skuValue = $variantForInventory->sku ?: '[EMPTY SKU]';
                    Log::warning("Missing RetailEdgeProduct for inventory update on SKU: {$skuValue} (Variant ID: {$variantForInventory->id})");
                    PriceInventoryLog::create([
                        'marketplace' => 'Shopify',
                        'item_identifier' => $skuValue ?? (string)$variantForInventory->inventory_item_id,
                        'change_type' => 'inventory',
                        'from_value' => $variantForInventory->inventory_quantity,
                        'to_value' => null,
                        'status' => 'failed',
                        'job_name' => $this->signature,
                        'message' => "Missing RetailEdgeProduct. Inventory update skipped.",
                    ]);
                    $variantForInventory->update(['inventory_requires_update' => 2]); // Mark as failed
                    $this->info("Marked variant {$skuValue} (ID: {$variantForInventory->id}) for review due to missing RetailEdgeProduct.");
                } else {
                    $originalInventory = $variantForInventory->inventory_quantity;
                    $targetInventory = $variantForInventory->retailEdgeProduct->quantity;
                    try {
                        $inventoryLevelAPI = new InventoryLevel($session);
                        $inventoryLevelAPI->set(
                            [], // Params
                            [
                                'location_id' => $location->location_id,
                                'inventory_item_id' => $variantForInventory->inventory_item_id,
                                'available' => $targetInventory
                            ]
                        );

                        PriceInventoryLog::create([
                            'marketplace' => 'Shopify',
                            'item_identifier' => $variantForInventory->sku ?? (string)$variantForInventory->inventory_item_id,
                            'change_type' => 'inventory',
                            'from_value' => $originalInventory,
                            'to_value' => $targetInventory,
                            'status' => 'success',
                            'job_name' => $this->signature,
                            'message' => "Inventory updated via API. Inventory Item ID: {$variantForInventory->inventory_item_id}",
                        ]);

                        $variantForInventory->update([
                            'inventory_quantity' => $targetInventory,
                            'inventory_requires_update' => 0
                        ]);
                        $skuValue = $variantForInventory->sku ?: '[EMPTY SKU]';
                        $this->info("Inventory updated for SKU {$skuValue}, variant id {$variantForInventory->variant_id}");

                        // Check and update product status if archived and quantity > 0
                        if ($targetInventory > 0 && $variantForInventory->product && $variantForInventory->product->status == 'archived') {
                            try {
                                $shopifyProductAPI = new ShopifyProductAPI($session);
                                $shopifyProductAPI->id = $variantForInventory->product->product_id;
                                $shopifyProductAPI->status = 'active';
                                $shopifyProductAPI->save(true);

                                // Update local ShopifyProduct model status
                                ShopifyProduct::where('id', $variantForInventory->product->id) // Assuming 'id' is primary key of ShopifyProduct model
                                    ->update(['status' => 'active']);

                                $msg = "Product '{$variantForInventory->product->title}' (ID: {$variantForInventory->product->product_id}) status updated from archived to active.";
                                $this->info($msg);
                                Log::info($msg);
                            } catch (\Exception $e) {
                                $msg = "Error updating Shopify product status from archived to active for '{$variantForInventory->product->title}'. Error: {$e->getMessage()}";
                                $this->error($msg);
                                Log::error($msg);
                            }
                        }
                    } catch (\Exception $e) {
                        PriceInventoryLog::create([
                            'marketplace' => 'Shopify',
                            'item_identifier' => $variantForInventory->sku ?? (string)$variantForInventory->inventory_item_id,
                            'change_type' => 'inventory',
                            'from_value' => $originalInventory,
                            'to_value' => $targetInventory,
                            'status' => 'failed',
                            'job_name' => $this->signature,
                            'message' => "API Error: " . $e->getMessage(),
                        ]);
                        $variantForInventory->update(['inventory_requires_update' => 2]); // Mark as failed
                        $skuValue = $variantForInventory->sku ?: '[EMPTY SKU]';
                        Log::error("Error updating inventory for SKU {$skuValue} (Variant ID: {$variantForInventory->id}). Error: {$e->getMessage()}");
                        $this->error("Error updating inventory for SKU {$skuValue}. Error: {$e->getMessage()}");
                    }
                }
                usleep(config('shopify.delay', 1500000)); // Use configurable delay
            }
            $inventoryUpdateCount = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->count();
            if ($inventoryUpdateCount > 0) {
                $this->info("Remaining products for inventory update: {$inventoryUpdateCount}");
            }
        }
        $this->info("Regular inventory updates completed.");
    }

    /**
     * Process previously failed inventory updates
     */
    private function processFailedInventoryUpdates($location, $session)
    {
        $this->info("Processing failed inventory updates...");
        $failedInventoryCount = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 2)->count();

        if ($failedInventoryCount > 0) {
            $this->info("Retrying {$failedInventoryCount} previously failed inventory updates (processing in batches of 10)...");

            // Process in batches to avoid overwhelming the system or API limits
            while ($failedInventoryCount > 0) {
                $failedVariants = ShopifyProductVariant::with(['retailEdgeProduct', 'product'])
                    ->whereNotNull('inventory_item_id')
                    ->where('inventory_requires_update', 2)
                    ->limit(10)
                    ->get();

                if ($failedVariants->isEmpty()) {
                    break; // No more failed items to process
                }

                foreach ($failedVariants as $variantForInventory) {
                    if (!$variantForInventory->retailEdgeProduct) {
                        $skuValue = $variantForInventory->sku ?: '[EMPTY SKU]';
                        Log::warning("Still missing RetailEdgeProduct for failed inventory update on SKU: {$skuValue} (Variant ID: {$variantForInventory->id})");
                        PriceInventoryLog::create([
                            'marketplace' => 'Shopify',
                            'item_identifier' => $skuValue ?? (string)$variantForInventory->inventory_item_id,
                            'change_type' => 'inventory_retry',
                            'from_value' => $variantForInventory->inventory_quantity,
                            'to_value' => null,
                            'status' => 'failed',
                            'job_name' => $this->signature,
                            'message' => "Retry skipped: Still missing RetailEdgeProduct.",
                        ]);
                        continue;
                    }
                    $originalInventoryRetry = $variantForInventory->inventory_quantity;
                    $targetInventoryRetry = $variantForInventory->retailEdgeProduct->quantity;
                    try {
                        $inventoryLevelAPI = new InventoryLevel($session);
                        $inventoryLevelAPI->set(
                            [], // Params
                            [
                                'location_id' => $location->location_id,
                                'inventory_item_id' => $variantForInventory->inventory_item_id,
                                'available' => $targetInventoryRetry
                            ]
                        );
                        PriceInventoryLog::create([
                            'marketplace' => 'Shopify',
                            'item_identifier' => $variantForInventory->sku ?? (string)$variantForInventory->inventory_item_id,
                            'change_type' => 'inventory_retry',
                            'from_value' => $originalInventoryRetry,
                            'to_value' => $targetInventoryRetry,
                            'status' => 'success',
                            'job_name' => $this->signature,
                            'message' => "Inventory update retry successful. Inventory Item ID: {$variantForInventory->inventory_item_id}",
                        ]);

                        $variantForInventory->update([
                            'inventory_quantity' => $targetInventoryRetry,
                            'inventory_requires_update' => 0 // Cleared on successful retry
                        ]);
                        $skuValue = $variantForInventory->sku ?: '[EMPTY SKU]';
                        $this->info("Retry successful: Inventory updated for SKU {$skuValue} (Variant ID: {$variantForInventory->variant_id})");

                        // Check and update product status if archived and quantity > 0 (same logic as regular updates)
                        if ($targetInventoryRetry > 0 && $variantForInventory->product && $variantForInventory->product->status == 'archived') {
                            try {
                                $shopifyProductAPI = new ShopifyProductAPI($session);
                                $shopifyProductAPI->id = $variantForInventory->product->product_id;
                                $shopifyProductAPI->status = 'active';
                                $shopifyProductAPI->save(true);

                                ShopifyProduct::where('id', $variantForInventory->product->id)
                                    ->update(['status' => 'active']);
                                $msg = "Product '{$variantForInventory->product->title}' (ID: {$variantForInventory->product->product_id}) status updated from archived to active during retry.";
                                $this->info($msg);
                                Log::info($msg);
                            } catch (\Exception $e) {
                                $msg = "Error updating Shopify product status from archived to active for '{$variantForInventory->product->title}' during retry. Error: {$e->getMessage()}";
                                $this->error($msg);
                                Log::error($msg);
                            }
                        }
                    } catch (\Exception $e) {
                        PriceInventoryLog::create([
                            'marketplace' => 'Shopify',
                            'item_identifier' => $variantForInventory->sku ?? (string)$variantForInventory->inventory_item_id,
                            'change_type' => 'inventory_retry',
                            'from_value' => $originalInventoryRetry,
                            'to_value' => $targetInventoryRetry,
                            'status' => 'failed',
                            'job_name' => $this->signature,
                            'message' => "API Error on retry: " . $e->getMessage(),
                        ]);
                        $skuValue = $variantForInventory->sku ?: '[EMPTY SKU]';
                        Log::error("Retry failed for inventory update on SKU {$skuValue} (Variant ID: {$variantForInventory->id}). Error: {$e->getMessage()}");
                        $this->error("Retry failed for SKU {$skuValue}. Error: {$e->getMessage()}");
                        // Variant remains 'inventory_requires_update' => 2
                    }
                    usleep(config('shopify.delay_failed', 2000000)); // Use a potentially longer, configurable delay for retries
                }
                $failedInventoryCount = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 2)->count();
                if ($failedInventoryCount > 0) {
                    $this->info("Remaining failed inventory updates to retry: {$failedInventoryCount}");
                }
            }
        } else {
            $this->info("No failed inventory updates to process.");
        }
        $this->info("Failed inventory updates processing completed.");
    }
}
