<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use Shopify\Rest\Admin2024_10\InventoryLevel;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;

class RetryFailedInventoryUpdates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyRetryFailedInventoryUpdates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry failed Shopify inventory updates';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyRetryFailedInventoryUpdates';

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

                $failedCount = ShopifyProductVariant::whereNotNull('inventory_item_id')
                    ->where('inventory_requires_update', 2)
                    ->count();

                if ($failedCount === 0) {
                    $this->info("No failed inventory updates to retry");
                    $job->update(['status' => 0, 'message' => null]);
                    Log::info("$marketplace $jobType finished: No failed updates to retry");
                    return;
                }

                $this->info("Found {$failedCount} failed inventory updates to retry");

                // Process in batches to avoid memory issues
                $batchSize = 50;
                $processedCount = 0;
                $successCount = 0;

                while ($processedCount < $failedCount) {
                    $variants = ShopifyProductVariant::with(['retailEdgeProduct'])
                        ->whereNotNull('inventory_item_id')
                        ->where('inventory_requires_update', 2)
                        ->limit($batchSize)
                        ->get();

                    foreach ($variants as $variant) {
                        if (!$variant->retailEdgeProduct) {
                            Log::warning("Still missing RetailEdgeProduct for variant with SKU: {$variant->sku}");
                            $variant->update(['inventory_requires_update' => 3]); // Mark as permanently failed
                            $this->info("Marked variant {$variant->sku} as permanently failed due to missing RetailEdgeProduct");
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

                            $variant->update([
                                'inventory_quantity' => $variant->retailEdgeProduct->quantity,
                                'inventory_requires_update' => 0
                            ]);

                            $successCount++;
                            $this->info("Retry successful: Inventory updated for sku {$variant->sku}");
                        } catch (\Exception $e) {
                            Log::error("Retry failed for {$variant->sku}. Error: {$e->getMessage()}");
                            $this->error("Retry failed for {$variant->sku}");
                        }

                        // Add delay to avoid rate limiting
                        usleep(2000000); // 2 seconds
                    }

                    $processedCount += $variants->count();
                    $this->info("Processed {$processedCount}/{$failedCount} failed updates, {$successCount} successful");
                }

                $job->update(['status' => 0, 'message' => null]);
                Log::info("$marketplace $jobType finished! Successfully retried {$successCount} out of {$failedCount} failed updates");
            } catch (\Exception $e) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                report($e);
                $this->error($e->getMessage());
            }
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
