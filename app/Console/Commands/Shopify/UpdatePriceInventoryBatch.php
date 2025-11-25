<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\PriceInventoryLog;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyGraphQLService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2025_04\Product as ShopifyProductAPI;

class UpdatePriceInventoryBatch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:update-price-inventory-batch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Updates product price and inventory on Shopify using GraphQL batch operations';

    protected ShopifyGraphQLService $graphqlService;

    public function __construct(ShopifyGraphQLService $graphqlService)
    {
        parent::__construct();
        $this->graphqlService = $graphqlService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = $this->signature;

        $job = SyncJobController::getJob($jobType, $marketplace);

        try {
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            $location = ShopifyLocation::first();

            if (! $location) {
                Log::error("$marketplace $jobType failed: No Shopify location found for inventory updates.");
                PriceInventoryLog::create([
                    'marketplace' => $marketplace,
                    'item_identifier' => 'GENERAL',
                    'change_type' => 'setup',
                    'status' => 'failed',
                    'job_name' => $this->signature,
                    'message' => 'No Shopify location found for inventory updates. Command halted.',
                ]);
                $job->update(['status' => 0, 'message' => 'No Shopify location found']);

                return;
            }

            // Process batch updates
            $this->info('Starting Batch Price & Inventory Updates...');
            $this->processBatchUpdates($location, $marketplace);

            // Process failed updates with retry logic
            $this->info('Processing Failed Updates...');
            $this->processFailedUpdates($location, $marketplace);

            $job->update(['status' => 0, 'message' => null]);
            Log::info("$marketplace $jobType finished successfully!");
        } catch (\Exception $e) {
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
            report($e);
            Log::error("$marketplace $jobType failed. Error: {$e->getMessage()}");
            $this->error('Command failed: '.$e->getMessage());
        }
    }

    /**
     * Process batch updates grouped by product
     */
    private function processBatchUpdates($location, $marketplace)
    {
        // Get all variants that need price or inventory updates
        $variantsNeedingUpdate = ShopifyProductVariant::with(['retailEdgeProduct', 'product'])
            ->whereNotNull('variant_id')
            ->whereNotNull('product_id')
            ->where(function ($query) {
                $query->where('price_requires_update', 1)
                    ->orWhere('inventory_requires_update', 1);
            })
            ->get();

        if ($variantsNeedingUpdate->isEmpty()) {
            $this->info('No variants requiring updates.');

            return;
        }

        $totalVariants = $variantsNeedingUpdate->count();
        $this->info("Found {$totalVariants} variants requiring price/inventory updates");

        // Group variants by product_id for batch processing
        $variantsByProduct = $variantsNeedingUpdate->groupBy('product_id');
        $totalProducts = $variantsByProduct->count();
        $processedProducts = 0;

        $this->info("Grouped into {$totalProducts} products for batch processing");

        foreach ($variantsByProduct as $productId => $variants) {
            $processedProducts++;
            $this->info("[{$processedProducts}/{$totalProducts}] Processing product ID: {$productId} with ".count($variants).' variant(s)');

            // Prepare variant data for GraphQL mutation
            $variantsData = [];
            $variantRecords = []; // Store variant models for later updates

            foreach ($variants as $variant) {
                if (! $variant->retailEdgeProduct) {
                    $this->handleMissingRetailEdgeProduct($variant, $marketplace);
                    continue;
                }

                $variantData = [
                    'variant_id' => $variant->variant_id,
                ];

                // Add price data if update needed
                if ($variant->price_requires_update == 1) {
                    $variantData['price'] = $variant->retailEdgeProduct->price;

                    $compareAtPrice = $variant->retailEdgeProduct->compare_at_price;
                    // Set to null if 0 or equals price
                    if ($compareAtPrice == 0 || $compareAtPrice == $variantData['price']) {
                        $variantData['compare_at_price'] = null;
                    } else {
                        $variantData['compare_at_price'] = $compareAtPrice;
                    }
                }

                // Add inventory data if update needed
                if ($variant->inventory_requires_update == 1) {
                    $variantData['inventory_quantity'] = $variant->retailEdgeProduct->quantity;
                }

                $variantsData[] = $variantData;
                $variantRecords[] = $variant;
            }

            if (empty($variantsData)) {
                $this->warn("Skipping product {$productId}: No valid variants to update");
                continue;
            }

            // Execute GraphQL mutation
            $result = $this->graphqlService->updateProductPriceAndInventory(
                $productId,
                $variantsData,
                $location->location_id
            );

            if ($result['success']) {
                $this->handleSuccessfulUpdate($variantRecords, $variantsData, $marketplace, $location);
            } else {
                $this->handleFailedUpdate($variantRecords, $result, $marketplace);
            }

            // Small delay to respect rate limits
            usleep(500000); // 0.5 seconds between products
        }

        $this->info('Batch updates completed.');
    }

    /**
     * Handle missing RetailEdgeProduct scenario
     */
    private function handleMissingRetailEdgeProduct($variant, $marketplace)
    {
        $skuValue = $variant->sku ?: '[EMPTY SKU]';
        Log::warning("Missing RetailEdgeProduct for SKU: {$skuValue} (Variant ID: {$variant->id})");

        if ($variant->price_requires_update == 1) {
            PriceInventoryLog::create([
                'marketplace' => $marketplace,
                'item_identifier' => $skuValue,
                'change_type' => 'price',
                'from_value' => $variant->price,
                'to_value' => null,
                'status' => 'failed',
                'job_name' => $this->signature,
                'message' => 'Missing RetailEdgeProduct. Price update skipped.',
            ]);
        }

        if ($variant->inventory_requires_update == 1) {
            PriceInventoryLog::create([
                'marketplace' => $marketplace,
                'item_identifier' => $skuValue,
                'change_type' => 'inventory',
                'from_value' => $variant->inventory_quantity,
                'to_value' => null,
                'status' => 'failed',
                'job_name' => $this->signature,
                'message' => 'Missing RetailEdgeProduct. Inventory update skipped.',
            ]);
        }

        $variant->update([
            'price_requires_update' => $variant->price_requires_update == 1 ? 2 : $variant->price_requires_update,
            'inventory_requires_update' => $variant->inventory_requires_update == 1 ? 2 : $variant->inventory_requires_update,
        ]);

        $this->warn("Marked variant {$skuValue} (ID: {$variant->id}) for review");
    }

    /**
     * Handle successful GraphQL update
     */
    private function handleSuccessfulUpdate($variantRecords, $variantsData, $marketplace, $location)
    {
        foreach ($variantRecords as $index => $variant) {
            $variantData = $variantsData[$index];
            $retailEdgeProduct = $variant->retailEdgeProduct;

            if (! $retailEdgeProduct) {
                continue;
            }

            $updates = [];
            $skuValue = $variant->sku ?: '[EMPTY SKU]';

            // Handle price update
            if (isset($variantData['price'])) {
                $oldPrice = $variant->price;
                $newPrice = $variantData['price'];

                PriceInventoryLog::create([
                    'marketplace' => $marketplace,
                    'item_identifier' => $skuValue,
                    'change_type' => 'price',
                    'from_value' => $oldPrice,
                    'to_value' => $newPrice,
                    'status' => 'success',
                    'job_name' => $this->signature,
                    'message' => 'Price updated via GraphQL productSet (batch). Variant ID: '.$variant->variant_id,
                ]);

                $updates['price'] = $newPrice;
                $updates['price_requires_update'] = 0;

                // Handle compare_at_price
                if (array_key_exists('compare_at_price', $variantData)) {
                    $oldCompareAtPrice = $variant->compare_at_price;
                    $newCompareAtPrice = $variantData['compare_at_price'];

                    PriceInventoryLog::create([
                        'marketplace' => $marketplace,
                        'item_identifier' => $skuValue,
                        'change_type' => 'compare_at_price',
                        'from_value' => $oldCompareAtPrice,
                        'to_value' => $newCompareAtPrice,
                        'status' => 'success',
                        'job_name' => $this->signature,
                        'message' => 'Compare_at_price updated via GraphQL productSet (batch). Variant ID: '.$variant->variant_id,
                    ]);

                    $updates['compare_at_price'] = $newCompareAtPrice ?? 0;
                }
            }

            // Handle inventory update
            if (isset($variantData['inventory_quantity'])) {
                $oldInventory = $variant->inventory_quantity;
                $newInventory = $variantData['inventory_quantity'];

                PriceInventoryLog::create([
                    'marketplace' => $marketplace,
                    'item_identifier' => $skuValue,
                    'change_type' => 'inventory',
                    'from_value' => $oldInventory,
                    'to_value' => $newInventory,
                    'status' => 'success',
                    'job_name' => $this->signature,
                    'message' => 'Inventory updated via GraphQL productSet (batch). Variant ID: '.$variant->variant_id,
                ]);

                $updates['inventory_quantity'] = $newInventory;
                $updates['inventory_requires_update'] = 0;

                // Check if product needs to be reactivated
                if ($newInventory > 0 && $variant->product && $variant->product->status == 'archived') {
                    $this->reactivateProduct($variant->product, $marketplace);
                }
            }

            // Update local database
            $variant->update($updates);
            $this->info("✓ Updated {$skuValue} (Variant ID: {$variant->variant_id})");
        }
    }

    /**
     * Handle failed GraphQL update
     */
    private function handleFailedUpdate($variantRecords, $result, $marketplace)
    {
        $errorMessage = 'GraphQL Error: ';
        if (! empty($result['user_errors'])) {
            $errorMessage .= json_encode($result['user_errors']);
        } elseif (! empty($result['graphql_errors'])) {
            $errorMessage .= json_encode($result['graphql_errors']);
        } else {
            $errorMessage .= 'Unknown error';
        }

        foreach ($variantRecords as $variant) {
            $skuValue = $variant->sku ?: '[EMPTY SKU]';

            if ($variant->price_requires_update == 1) {
                PriceInventoryLog::create([
                    'marketplace' => $marketplace,
                    'item_identifier' => $skuValue,
                    'change_type' => 'price',
                    'from_value' => $variant->price,
                    'to_value' => $variant->retailEdgeProduct ? $variant->retailEdgeProduct->price : null,
                    'status' => 'failed',
                    'job_name' => $this->signature,
                    'message' => $errorMessage,
                ]);
            }

            if ($variant->inventory_requires_update == 1) {
                PriceInventoryLog::create([
                    'marketplace' => $marketplace,
                    'item_identifier' => $skuValue,
                    'change_type' => 'inventory',
                    'from_value' => $variant->inventory_quantity,
                    'to_value' => $variant->retailEdgeProduct ? $variant->retailEdgeProduct->quantity : null,
                    'status' => 'failed',
                    'job_name' => $this->signature,
                    'message' => $errorMessage,
                ]);
            }

            $variant->update([
                'price_requires_update' => $variant->price_requires_update == 1 ? 2 : $variant->price_requires_update,
                'inventory_requires_update' => $variant->inventory_requires_update == 1 ? 2 : $variant->inventory_requires_update,
            ]);

            $this->error("✗ Failed to update {$skuValue} (Variant ID: {$variant->id})");
        }

        Log::error('Batch update failed', [
            'variant_count' => count($variantRecords),
            'error' => $errorMessage,
        ]);
    }

    /**
     * Reactivate archived product when inventory becomes available
     */
    private function reactivateProduct($product, $marketplace)
    {
        try {
            $session = $this->graphqlService->getSession();
            $shopifyProductAPI = new ShopifyProductAPI($session);
            $shopifyProductAPI->id = $product->product_id;
            $shopifyProductAPI->status = 'active';
            $shopifyProductAPI->save(true);

            ShopifyProduct::where('id', $product->id)->update(['status' => 'active']);

            $msg = "Product '{$product->title}' (ID: {$product->product_id}) reactivated (archived → active).";
            $this->info($msg);
            Log::info($msg);
        } catch (\Exception $e) {
            $msg = "Error reactivating product '{$product->title}' (ID: {$product->product_id}): {$e->getMessage()}";
            $this->error($msg);
            Log::error($msg);
        }
    }

    /**
     * Process previously failed updates with retry logic
     */
    private function processFailedUpdates($location, $marketplace)
    {
        // Get failed variants
        $failedVariants = ShopifyProductVariant::with(['retailEdgeProduct', 'product'])
            ->whereNotNull('variant_id')
            ->whereNotNull('product_id')
            ->where(function ($query) {
                $query->where('price_requires_update', 2)
                    ->orWhere('inventory_requires_update', 2);
            })
            ->limit(50) // Process max 50 failed items per run
            ->get();

        if ($failedVariants->isEmpty()) {
            $this->info('No failed updates to retry.');

            return;
        }

        $failedCount = $failedVariants->count();
        $this->info("Retrying {$failedCount} previously failed update(s)...");

        // Group by product for batch retry
        $variantsByProduct = $failedVariants->groupBy('product_id');

        foreach ($variantsByProduct as $productId => $variants) {
            $this->info("Retrying product ID: {$productId} with ".count($variants).' variant(s)');

            $variantsData = [];
            $variantRecords = [];

            foreach ($variants as $variant) {
                if (! $variant->retailEdgeProduct) {
                    $this->handleMissingRetailEdgeProduct($variant, $marketplace);
                    continue;
                }

                $variantData = [
                    'variant_id' => $variant->variant_id,
                ];

                // Add price data if update needed
                if ($variant->price_requires_update == 2) {
                    $variantData['price'] = $variant->retailEdgeProduct->price;
                    $compareAtPrice = $variant->retailEdgeProduct->compare_at_price;
                    $variantData['compare_at_price'] = ($compareAtPrice == 0 || $compareAtPrice == $variantData['price'])
                        ? null
                        : $compareAtPrice;
                }

                // Add inventory data if update needed
                if ($variant->inventory_requires_update == 2) {
                    $variantData['inventory_quantity'] = $variant->retailEdgeProduct->quantity;
                }

                $variantsData[] = $variantData;
                $variantRecords[] = $variant;
            }

            if (empty($variantsData)) {
                continue;
            }

            // Retry with GraphQL mutation
            $result = $this->graphqlService->updateProductPriceAndInventory(
                $productId,
                $variantsData,
                $location->location_id
            );

            if ($result['success']) {
                $this->handleSuccessfulRetry($variantRecords, $variantsData, $marketplace, $location);
            } else {
                $this->handleFailedRetry($variantRecords, $result, $marketplace);
            }

            usleep(1000000); // 1 second delay for retries
        }

        $this->info('Failed updates retry completed.');
    }

    /**
     * Handle successful retry
     */
    private function handleSuccessfulRetry($variantRecords, $variantsData, $marketplace, $location)
    {
        foreach ($variantRecords as $index => $variant) {
            $variantData = $variantsData[$index];
            $skuValue = $variant->sku ?: '[EMPTY SKU]';
            $updates = [];

            if (isset($variantData['price'])) {
                PriceInventoryLog::create([
                    'marketplace' => $marketplace,
                    'item_identifier' => $skuValue,
                    'change_type' => 'price_retry',
                    'from_value' => $variant->price,
                    'to_value' => $variantData['price'],
                    'status' => 'success',
                    'job_name' => $this->signature,
                    'message' => 'Retry successful: Price updated via GraphQL productSet. Variant ID: '.$variant->variant_id,
                ]);

                $updates['price'] = $variantData['price'];
                $updates['compare_at_price'] = $variantData['compare_at_price'] ?? 0;
                $updates['price_requires_update'] = 0;
            }

            if (isset($variantData['inventory_quantity'])) {
                PriceInventoryLog::create([
                    'marketplace' => $marketplace,
                    'item_identifier' => $skuValue,
                    'change_type' => 'inventory_retry',
                    'from_value' => $variant->inventory_quantity,
                    'to_value' => $variantData['inventory_quantity'],
                    'status' => 'success',
                    'job_name' => $this->signature,
                    'message' => 'Retry successful: Inventory updated via GraphQL productSet. Variant ID: '.$variant->variant_id,
                ]);

                $updates['inventory_quantity'] = $variantData['inventory_quantity'];
                $updates['inventory_requires_update'] = 0;

                if ($variantData['inventory_quantity'] > 0 && $variant->product && $variant->product->status == 'archived') {
                    $this->reactivateProduct($variant->product, $marketplace);
                }
            }

            $variant->update($updates);
            $this->info("✓ Retry successful for {$skuValue} (Variant ID: {$variant->variant_id})");
        }
    }

    /**
     * Handle failed retry
     */
    private function handleFailedRetry($variantRecords, $result, $marketplace)
    {
        $errorMessage = 'Retry failed - GraphQL Error: ';
        if (! empty($result['user_errors'])) {
            $errorMessage .= json_encode($result['user_errors']);
        } elseif (! empty($result['graphql_errors'])) {
            $errorMessage .= json_encode($result['graphql_errors']);
        }

        foreach ($variantRecords as $variant) {
            $skuValue = $variant->sku ?: '[EMPTY SKU]';

            if ($variant->price_requires_update == 2) {
                PriceInventoryLog::create([
                    'marketplace' => $marketplace,
                    'item_identifier' => $skuValue,
                    'change_type' => 'price_retry',
                    'from_value' => $variant->price,
                    'to_value' => $variant->retailEdgeProduct ? $variant->retailEdgeProduct->price : null,
                    'status' => 'failed',
                    'job_name' => $this->signature,
                    'message' => $errorMessage,
                ]);
                // Keep at 2 for another retry attempt
            }

            if ($variant->inventory_requires_update == 2) {
                PriceInventoryLog::create([
                    'marketplace' => $marketplace,
                    'item_identifier' => $skuValue,
                    'change_type' => 'inventory_retry',
                    'from_value' => $variant->inventory_quantity,
                    'to_value' => $variant->retailEdgeProduct ? $variant->retailEdgeProduct->quantity : null,
                    'status' => 'failed',
                    'job_name' => $this->signature,
                    'message' => $errorMessage,
                ]);
                // Update to 3 (repeated failure)
                $variant->update(['inventory_requires_update' => 3]);
            }

            $this->error("✗ Retry failed for {$skuValue} (Variant ID: {$variant->id})");
        }
    }
}
