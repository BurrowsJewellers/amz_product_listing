<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use Shopify\Rest\Admin2024_01\InventoryLevel;
use Shopify\Rest\Admin2024_01\Product;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Shopify\Clients\Graphql;

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
    protected $description = 'Update Shopify inventory using GraphQL';

    private $client;
    private $shopifyService;

    /**
     * Execute the console command.
     */

    public function __construct()
    {
        parent::__construct();
        $this->shopifyService = new ShopifyService();
    }

    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUpdateInventory';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $session = $this->shopifyService->getSession();
                $this->client = new Graphql($session->getShop(), $session->getAccessToken());

                $location = ShopifyLocation::first();

                $count = ShopifyProductVariant::whereNotNull('inventory_item_id')
                    ->where('inventory_requires_update', 1)
                    ->count();
                $this->info("Remaining {$count}");

                while ($count) {
                    $variant = ShopifyProductVariant::with(['retailEdgeProduct', 'product'])
                        ->whereNotNull('inventory_item_id')
                        ->where('inventory_requires_update', 1)
                        ->first();

                    if ($variant) {
                        try {
                            $currentQuantity = $variant->inventory_quantity ?? 0;
                            $newQuantity = $variant->retailEdgeProduct->quantity;

                            // Update inventory level using GraphQL
                            $response = $this->updateInventoryLevel(
                                $location->location_id,
                                $variant->inventory_item_id,
                                $newQuantity,
                                $currentQuantity
                            );

                            if (!empty($response['errors'])) {
                                throw new \Exception(json_encode($response['errors']));
                            }

                            $variant->update([
                                'inventory_quantity' => $newQuantity,
                                'inventory_requires_update' => 0
                            ]);

                            $this->info("Inventory updated for sku {$variant->sku}, variant id {$variant->variant_id}");

                            // Update product status if necessary
                            if ($variant->retailEdgeProduct->quantity > 0 && $variant->product->status == 'archived') {
                                try {
                                    $status = 'active';
                                    $statusResponse = $this->updateProductStatus(
                                        $variant->product->product_id,
                                        $status
                                    );

                                    if (!empty($statusResponse['errors'])) {
                                        throw new \Exception(json_encode($statusResponse['errors']));
                                    }

                                    ShopifyProduct::where('id', $variant->product->id)
                                        ->update(['status' => $status]);

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
                            Log::debug("There was an error while updating the inventory for {$variant->sku}. Error message : {$e->getMessage()}");
                        }
                        usleep(1500000);
                    }

                    $count = ShopifyProductVariant::whereNotNull('inventory_item_id')
                        ->where('inventory_requires_update', 1)
                        ->count();
                    $this->info("Remaining {$count}");
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

    private function updateInventoryLevel($locationId, $inventoryItemId, $newQuantity, $currentQuantity)
    {
        $mutation = <<<'GRAPHQL'
        mutation InventorySet($input: InventorySetQuantitiesInput!) {
            inventorySetQuantities(input: $input) {
                inventoryAdjustmentGroup {
                    createdAt
                    reason
                    changes {
                        name
                        delta
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $variables = [
            'input' => [
                'name' => 'available',
                'reason' => 'correction',
                'referenceDocumentUri' => 'logistics://inventory/update/' . date('Y-m-d\TH:i:s\Z'),
                'quantities' => [
                    [
                        'inventoryItemId' => "gid://shopify/InventoryItem/{$inventoryItemId}",
                        'locationId' => "gid://shopify/Location/{$locationId}",
                        'quantity' => $newQuantity,
                        'compareQuantity' => $currentQuantity
                    ]
                ]
            ]
        ];

        return $this->client->query(['query' => $mutation, 'variables' => $variables]);
    }

    private function updateProductStatus($productId, $status)
    {
        $mutation = <<<'GRAPHQL'
        mutation productUpdate($input: ProductInput!) {
            productUpdate(input: $input) {
                product {
                    id
                    status
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $variables = [
            'input' => [
                'id' => "gid://shopify/Product/{$productId}",
                'status' => strtoupper($status)
            ]
        ];

        return $this->client->query(['query' => $mutation, 'variables' => $variables]);
    }


    public function handleOld()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUpdateInventory';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $location = ShopifyLocation::first();
                $session = (new ShopifyService)->getSession();

                $count = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->count();
                $this->info("Remaining {$count}");

                while ($count) {
                    $variant = ShopifyProductVariant::with(['retailEdgeProduct', 'product'])->whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->first();

                    if ($variant) {
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
                            $this->info("Inventory updated for sku {$variant->sku}, variant id {$variant->variant_id}");

                            if ($variant->retailEdgeProduct->quantity > 0 && $variant->product->status == 'archived') {
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
                            Log::debug("There was an error while updating the inventory for {$variant->sku}. Error message : {$e->getMessage()}");
                        }
                        usleep(1500000);
                    }

                    $count = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->count();
                    $this->info("Remaining {$count}");
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
