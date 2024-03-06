<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use Shopify\Rest\Admin2024_01\InventoryLevel;
use App\Models\ShopifyLocation;
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
                // $job->update(['status' => 1]);

                $location = ShopifyLocation::first();
                $session = (new ShopifyService)->getSession();

                $count = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->count();

                while ($count) {
                    $product = ShopifyProductVariant::with('retailEdgeProduct')->whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->first();

                    if ($product) {
                        try {
                            $inventoryLevel = new InventoryLevel($session);
                            $inventoryLevel->set(
                                [], // Params
                                [
                                    'location_id' => $location->location_id,
                                    'inventory_item_id' => $product->inventory_item_id,
                                    'available' => $product->retailEdgeProduct->quantity
                                ],
                            );

                            $product->update(['inventory_quantity' => $product->retailEdgeProduct->quantity, 'inventory_requires_update' => 0]);
                            $this->info('Inventory updated');
                        } catch (\Exception $e) {
                            Log::debug("There was an error while updating the inventory to {$product->retailEdgeProduct->quantity} for {$product->sku}. Error message : {$e->getMessage()}");
                            $product->update(['inventory_requires_update' => 2]);
                            // report($e);
                        }
                        usleep(1500000);
                    }

                    $count = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->count();
                }
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
}
