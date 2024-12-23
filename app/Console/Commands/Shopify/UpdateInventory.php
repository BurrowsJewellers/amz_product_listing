<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Shopify\Clients\Graphql;

class UpdateInventory extends Command
{
    protected $signature = 'shopifyUpdateInventory';
    protected $description = 'Update Shopify inventory using GraphQL';

    private $client;
    private $shopifyService;

    public function __construct()
    {
        parent::__construct();
        $this->shopifyService = new ShopifyService();
    }

    private function checkResponseForErrors($response)
    {
        $responseBody = $response->getDecodedBody();

        // Check for GraphQL response errors
        if (isset($responseBody['errors'])) {
            throw new \Exception(json_encode($responseBody['errors']));
        }

        // Check for user errors in the mutation response
        if (
            isset($responseBody['data']['inventorySetQuantities']['userErrors'])
            && !empty($responseBody['data']['inventorySetQuantities']['userErrors'])
        ) {
            throw new \Exception(json_encode($responseBody['data']['inventorySetQuantities']['userErrors']));
        }

        return $responseBody;
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
                'ignoreCompareQuantity' => true,
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

        $response = $this->client->query([
            'query' => $mutation,
            'variables' => $variables
        ]);

        return $this->checkResponseForErrors($response);
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

        $response = $this->client->query(['query' => $mutation, 'variables' => $variables]);
        return $this->checkResponseForErrors($response);
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

                            $variant->update([
                                'inventory_quantity' => $newQuantity,
                                'inventory_requires_update' => 0
                            ]);

                            $this->info("Inventory updated for sku {$variant->sku}, variant id {$variant->variant_id}");

                            // Update product status if necessary
                            if ($variant->retailEdgeProduct->quantity > 0 && $variant->product->status == 'archived') {
                                try {
                                    $status = 'active';
                                    $response = $this->updateProductStatus(
                                        $variant->product->product_id,
                                        $status
                                    );

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
}
