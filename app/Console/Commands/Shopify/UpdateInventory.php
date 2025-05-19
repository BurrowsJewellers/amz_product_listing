<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use Shopify\Rest\Admin2025_04\InventoryLevel;
use Shopify\Rest\Admin2025_04\Product;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;

class UpdateInventory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyUpdateInventory';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUpdateInventory';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $location = ShopifyLocation::first();
                if (!$location) {
                    Log::error("$marketplace $jobType failed: No Shopify location found");
                    $job->update(['status' => 0, 'message' => 'No Shopify location found']);
                    return;
                }

                $session = (new ShopifyService)->getSession();

                // Process regular updates
                $this->processRegularUpdates($location, $session);

                // Process failed updates
                $this->processFailedUpdates($location, $session);

                $job->update(['status' => 0, 'message' => null]);
                Log::info("$marketplace $jobType finished!");
            } catch (\Exception $e) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                report($e);
                $this->error($e->getMessage());
            }
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }

    /**
     * Process regular inventory updates
     */
    private function processRegularUpdates($location, $session)
    {
        $count = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->count();
        $this->info("Remaining regular updates: {$count}");

        while ($count) {
            $variant = ShopifyProductVariant::with(['retailEdgeProduct', 'product'])->whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->first();

            if ($variant) {
                if (!$variant->retailEdgeProduct) {
                    $skuValue = $variant->sku ? $variant->sku : '[EMPTY SKU]';
                    Log::warning("Missing RetailEdgeProduct for variant with SKU: {$skuValue} (Variant ID: {$variant->id})");
                    $variant->update(['inventory_requires_update' => 2]);
                    $this->info("Marked variant {$skuValue} (Variant ID: {$variant->id}) for review due to missing RetailEdgeProduct");
                } else {
                    try {
                        $inventoryLevel = new InventoryLevel($session);
                        $inventoryLevel->set(
                            [], // Params
                            [
                                'location_id' => $location->location_id,
                                'inventory_item_id' => $variant->inventory_item_id,
                                'available' => $variant->retailEdgeProduct->quantity
                            ],
                        );

                        $variant->update(['inventory_quantity' => $variant->retailEdgeProduct->quantity, 'inventory_requires_update' => 0]);
                        $skuValue = $variant->sku ? $variant->sku : '[EMPTY SKU]';
                        $this->info("Inventory updated for sku {$skuValue}, variant id {$variant->variant_id}");

                        if ($variant->retailEdgeProduct->quantity > 0 && $variant->product && $variant->product->status == 'archived') {
                            try {
                                $status = 'active';
                                $product = new Product($session);
                                $product->id = $variant->product->product_id;
                                $product->status = $status;
                                $product->save(
                                    true,
                                );

                                ShopifyProduct::where('id', $variant->product->pid)->update(['status' => $status]);

                                $msg = $variant->product->title . ' marked as ' . $status;
                                $this->info($msg);
                                Log::debug($msg);
                            } catch (\Exception $e) {
                                $msg = "An error occurred while updating the Shopify product status from archived to active. Title: {$variant->product->title}";
                                $this->info($msg);
                                Log::debug($msg);
                            }
                        }
                    } catch (\Exception $e) {
                        $variant->update(['inventory_requires_update' => 2]);
                        $skuValue = $variant->sku ? $variant->sku : '[EMPTY SKU]';
                        Log::error("Error updating inventory for {$skuValue} (Variant ID: {$variant->id}). Error: {$e->getMessage()}");
                        $this->error("Error updating inventory for {$skuValue} (Variant ID: {$variant->id}). Error: {$e->getMessage()}");
                    }
                }

                // Add delay to avoid rate limiting
                usleep(1500000);
            }

            $count = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->count();
            $this->info("Remaining regular updates: {$count}");
        }
    }

    /**
     * Process previously failed inventory updates
     */
    private function processFailedUpdates($location, $session)
    {
        $failedCount = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 2)->count();

        if ($failedCount > 0) {
            $this->info("Processing {$failedCount} previously failed updates");

            $failedVariants = ShopifyProductVariant::with(['retailEdgeProduct', 'product'])
                ->whereNotNull('inventory_item_id')
                ->where('inventory_requires_update', 2)
                ->limit(10) // Process in smaller batches
                ->get();

            foreach ($failedVariants as $variant) {
                if (!$variant->retailEdgeProduct) {
                    $skuValue = $variant->sku ? $variant->sku : '[EMPTY SKU]';
                    Log::warning("Still missing RetailEdgeProduct for variant with SKU: {$skuValue} (Variant ID: {$variant->id})");
                    continue;
                }

                try {
                    $inventoryLevel = new InventoryLevel($session);
                    $inventoryLevel->set(
                        [], // Params
                        [
                            'location_id' => $location->location_id,
                            'inventory_item_id' => $variant->inventory_item_id,
                            'available' => $variant->retailEdgeProduct->quantity
                        ],
                    );

                    $variant->update(['inventory_quantity' => $variant->retailEdgeProduct->quantity, 'inventory_requires_update' => 0]);
                    $skuValue = $variant->sku ? $variant->sku : '[EMPTY SKU]';
                    $this->info("Retry successful: Inventory updated for sku {$skuValue} (Variant ID: {$variant->id})");

                    // Add delay to avoid rate limiting
                    usleep(2000000); // Longer delay for retries
                } catch (\Exception $e) {
                    $skuValue = $variant->sku ? $variant->sku : '[EMPTY SKU]';
                    Log::error("Retry failed for {$skuValue} (Variant ID: {$variant->id}). Error: {$e->getMessage()}");
                    $this->error("Retry failed for {$skuValue} (Variant ID: {$variant->id}). Error: {$e->getMessage()}");
                }
            }
        }
    }
}
