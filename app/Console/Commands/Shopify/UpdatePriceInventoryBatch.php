<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\PriceInventoryLog;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncFailureLogger;
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

    protected SyncFailureLogger $failureLogger;

    public function __construct(ShopifyGraphQLService $graphqlService, SyncFailureLogger $failureLogger)
    {
        parent::__construct();
        $this->graphqlService = $graphqlService;
        $this->failureLogger = $failureLogger;
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
     * Process batch updates using hybrid chunking approach:
     * - Price updates: per product using productVariantsBulkUpdate
     * - Inventory updates: batched across products using inventorySetQuantities (250 items per chunk)
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

        // Separate price updates (grouped by product) and inventory updates (batched across products)
        $priceUpdatesByProduct = [];
        $inventoryUpdates = [];
        $variantMap = []; // Map inventory_item_id to variant model for logging

        foreach ($variantsNeedingUpdate as $variant) {
            if (! $variant->retailEdgeProduct) {
                $this->handleMissingRetailEdgeProduct($variant, $marketplace);
                continue;
            }

            // Collect price updates grouped by product
            if ($variant->price_requires_update == 1) {
                $productId = $variant->product_id;
                if (! isset($priceUpdatesByProduct[$productId])) {
                    $priceUpdatesByProduct[$productId] = [
                        'variants' => [],
                        'records' => [],
                    ];
                }

                $compareAtPrice = $variant->retailEdgeProduct->compare_at_price;
                $price = $variant->retailEdgeProduct->price;

                $priceUpdatesByProduct[$productId]['variants'][] = [
                    'variant_id' => $variant->variant_id,
                    'price' => $price,
                    'compare_at_price' => ($compareAtPrice == 0 || $compareAtPrice == $price) ? null : $compareAtPrice,
                ];
                $priceUpdatesByProduct[$productId]['records'][] = $variant;
            }

            // Collect inventory updates for bulk processing across products
            if ($variant->inventory_requires_update == 1 && $variant->inventory_item_id) {
                $inventoryUpdates[] = [
                    'inventory_item_id' => $variant->inventory_item_id,
                    'quantity' => $variant->retailEdgeProduct->quantity,
                    'variant' => $variant,
                ];
                $variantMap[$variant->inventory_item_id] = $variant;
            }
        }

        // Process price updates per product
        $this->processPriceUpdates($priceUpdatesByProduct, $marketplace);

        // Process inventory updates in chunks of 250 across all products
        $this->processInventoryUpdates($inventoryUpdates, $location, $marketplace);

        $this->info('Batch updates completed.');
    }

    /**
     * Process price updates grouped by product
     */
    private function processPriceUpdates(array $priceUpdatesByProduct, string $marketplace)
    {
        $totalProducts = count($priceUpdatesByProduct);
        if ($totalProducts === 0) {
            $this->info('No price updates to process.');

            return;
        }

        $this->info("Processing price updates for {$totalProducts} product(s)...");
        $processedProducts = 0;

        foreach ($priceUpdatesByProduct as $productId => $data) {
            $processedProducts++;
            $variantCount = count($data['variants']);
            $this->info("[{$processedProducts}/{$totalProducts}] Updating prices for product {$productId} ({$variantCount} variant(s))");

            $result = $this->graphqlService->updateProductVariantPrices($productId, $data['variants']);

            if ($result['success']) {
                $this->handleSuccessfulPriceUpdate($data['records'], $data['variants'], $marketplace);
            } else {
                $this->handleFailedPriceUpdate($data['records'], $data['variants'], $result, $marketplace);
            }

            usleep(250000); // 0.25 seconds between products
        }
    }

    /**
     * Process inventory updates in chunks of 250 across all products
     */
    private function processInventoryUpdates(array $inventoryUpdates, $location, string $marketplace)
    {
        $totalItems = count($inventoryUpdates);
        if ($totalItems === 0) {
            $this->info('No inventory updates to process.');

            return;
        }

        $chunkSize = 250;
        $chunks = array_chunk($inventoryUpdates, $chunkSize);
        $totalChunks = count($chunks);

        $this->info("Processing {$totalItems} inventory update(s) in {$totalChunks} chunk(s) of up to {$chunkSize}...");

        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkNumber = $chunkIndex + 1;
            $this->info("[Chunk {$chunkNumber}/{$totalChunks}] Processing ".count($chunk).' inventory items...');

            // Prepare items for bulk update
            $items = array_map(function ($item) {
                return [
                    'inventory_item_id' => $item['inventory_item_id'],
                    'quantity' => $item['quantity'],
                ];
            }, $chunk);

            $result = $this->graphqlService->bulkUpdateInventory($items, $location->location_id);

            if ($result['success']) {
                $this->handleSuccessfulInventoryChunk($chunk, $marketplace);
            } else {
                $this->handleFailedInventoryChunk($chunk, $result, $marketplace);
            }

            usleep(500000); // 0.5 seconds between chunks
        }
    }

    /**
     * Handle successful price update for a product
     */
    private function handleSuccessfulPriceUpdate(array $variantRecords, array $variantsData, string $marketplace)
    {
        foreach ($variantRecords as $index => $variant) {
            $variantData = $variantsData[$index];
            $skuValue = $variant->sku ?: '[EMPTY SKU]';

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
                'message' => 'Price updated via GraphQL productVariantsBulkUpdate. Variant ID: '.$variant->variant_id,
            ]);

            $updates = [
                'price' => $newPrice,
                'price_requires_update' => 0,
            ];

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
                    'message' => 'Compare_at_price updated via GraphQL productVariantsBulkUpdate. Variant ID: '.$variant->variant_id,
                ]);

                $updates['compare_at_price'] = $newCompareAtPrice ?? 0;
            }

            $variant->update($updates);
            $this->info("✓ Price updated for {$skuValue}");
        }
    }

    /**
     * Handle failed price update for a product
     */
    private function handleFailedPriceUpdate(array $variantRecords, array $variantsData, array $result, string $marketplace)
    {
        $errorMessage = 'GraphQL Error: ';
        if (! empty($result['user_errors'])) {
            $errorMessage .= json_encode($result['user_errors']);
        } elseif (! empty($result['graphql_errors'])) {
            $errorMessage .= json_encode($result['graphql_errors']);
        } else {
            $errorMessage .= 'Unknown error';
        }

        foreach ($variantRecords as $index => $variant) {
            $variantData = $variantsData[$index] ?? [];
            $skuValue = $variant->sku ?: '[EMPTY SKU]';

            // Check if this is a "product not exists" error - if so, clean up stale record
            if ($this->isProductNotExistsError($errorMessage)) {
                $this->cleanupStaleVariant($variant);

                continue; // Skip further processing for this variant
            }

            $this->failureLogger->logFailure(
                $variant,
                'price',
                $errorMessage,
                $result,
                [
                    'job_name' => $this->signature,
                    'api_request' => $variantData,
                    'user_errors' => $result['user_errors'] ?? null,
                    'graphql_errors' => $result['graphql_errors'] ?? null,
                ]
            );

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

            $variant->update(['price_requires_update' => 2]);
            $this->error("✗ Price update failed for {$skuValue}");
        }
    }

    /**
     * Handle successful inventory chunk update
     */
    private function handleSuccessfulInventoryChunk(array $chunk, string $marketplace)
    {
        foreach ($chunk as $item) {
            $variant = $item['variant'];
            $skuValue = $variant->sku ?: '[EMPTY SKU]';
            $oldInventory = $variant->inventory_quantity;
            $newInventory = $item['quantity'];

            PriceInventoryLog::create([
                'marketplace' => $marketplace,
                'item_identifier' => $skuValue,
                'change_type' => 'inventory',
                'from_value' => $oldInventory,
                'to_value' => $newInventory,
                'status' => 'success',
                'job_name' => $this->signature,
                'message' => 'Inventory updated via GraphQL inventorySetQuantities (bulk). Variant ID: '.$variant->variant_id,
            ]);

            $variant->update([
                'inventory_quantity' => $newInventory,
                'inventory_requires_update' => 0,
            ]);

            // Check if product needs to be reactivated
            if ($newInventory > 0 && $variant->product && $variant->product->status == 'archived') {
                $this->reactivateProduct($variant->product, $marketplace);
            }

            $this->info("✓ Inventory updated for {$skuValue}");
        }
    }

    /**
     * Handle failed inventory chunk update
     */
    private function handleFailedInventoryChunk(array $chunk, array $result, string $marketplace)
    {
        $errorMessage = 'GraphQL Error: ';
        if (! empty($result['user_errors'])) {
            $errorMessage .= json_encode($result['user_errors']);
        } elseif (! empty($result['graphql_errors'])) {
            $errorMessage .= json_encode($result['graphql_errors']);
        } else {
            $errorMessage .= 'Unknown error';
        }

        foreach ($chunk as $item) {
            $variant = $item['variant'];
            $skuValue = $variant->sku ?: '[EMPTY SKU]';

            // Check if this is a "product not exists" error - if so, clean up stale record
            if ($this->isProductNotExistsError($errorMessage)) {
                $this->cleanupStaleVariant($variant);

                continue; // Skip further processing for this variant
            }

            $this->failureLogger->logFailure(
                $variant,
                'inventory',
                $errorMessage,
                $result,
                [
                    'job_name' => $this->signature,
                    'inventory_item_id' => $item['inventory_item_id'],
                    'target_quantity' => $item['quantity'],
                    'user_errors' => $result['user_errors'] ?? null,
                    'graphql_errors' => $result['graphql_errors'] ?? null,
                ]
            );

            PriceInventoryLog::create([
                'marketplace' => $marketplace,
                'item_identifier' => $skuValue,
                'change_type' => 'inventory',
                'from_value' => $variant->inventory_quantity,
                'to_value' => $item['quantity'],
                'status' => 'failed',
                'job_name' => $this->signature,
                'message' => $errorMessage,
            ]);

            $variant->update(['inventory_requires_update' => 2]);
            $this->error("✗ Inventory update failed for {$skuValue}");
        }
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
     * Process previously failed updates with retry logic using hybrid chunking
     */
    private function processFailedUpdates($location, $marketplace)
    {
        // Get failed variants (limit to 250 for inventory chunk size)
        $failedVariants = ShopifyProductVariant::with(['retailEdgeProduct', 'product'])
            ->whereNotNull('variant_id')
            ->whereNotNull('product_id')
            ->where(function ($query) {
                $query->where('price_requires_update', 2)
                    ->orWhere('inventory_requires_update', 2);
            })
            ->limit(250)
            ->get();

        if ($failedVariants->isEmpty()) {
            $this->info('No failed updates to retry.');

            return;
        }

        $failedCount = $failedVariants->count();
        $this->info("Retrying {$failedCount} previously failed update(s)...");

        // Separate price and inventory retries
        $priceRetryByProduct = [];
        $inventoryRetries = [];

        foreach ($failedVariants as $variant) {
            if (! $variant->retailEdgeProduct) {
                $this->handleMissingRetailEdgeProduct($variant, $marketplace);
                continue;
            }

            // Collect price retries grouped by product
            if ($variant->price_requires_update == 2) {
                $productId = $variant->product_id;
                if (! isset($priceRetryByProduct[$productId])) {
                    $priceRetryByProduct[$productId] = [
                        'variants' => [],
                        'records' => [],
                    ];
                }

                $compareAtPrice = $variant->retailEdgeProduct->compare_at_price;
                $price = $variant->retailEdgeProduct->price;

                $priceRetryByProduct[$productId]['variants'][] = [
                    'variant_id' => $variant->variant_id,
                    'price' => $price,
                    'compare_at_price' => ($compareAtPrice == 0 || $compareAtPrice == $price) ? null : $compareAtPrice,
                ];
                $priceRetryByProduct[$productId]['records'][] = $variant;
            }

            // Collect inventory retries for bulk processing
            if ($variant->inventory_requires_update == 2 && $variant->inventory_item_id) {
                $inventoryRetries[] = [
                    'inventory_item_id' => $variant->inventory_item_id,
                    'quantity' => $variant->retailEdgeProduct->quantity,
                    'variant' => $variant,
                ];
            }
        }

        // Process price retries per product
        $this->processPriceRetries($priceRetryByProduct, $marketplace);

        // Process inventory retries in bulk
        $this->processInventoryRetries($inventoryRetries, $location, $marketplace);

        $this->info('Failed updates retry completed.');
    }

    /**
     * Process price retries grouped by product
     */
    private function processPriceRetries(array $priceRetryByProduct, string $marketplace)
    {
        $totalProducts = count($priceRetryByProduct);
        if ($totalProducts === 0) {
            return;
        }

        $this->info("Retrying price updates for {$totalProducts} product(s)...");

        foreach ($priceRetryByProduct as $productId => $data) {
            $result = $this->graphqlService->updateProductVariantPrices($productId, $data['variants']);

            if ($result['success']) {
                foreach ($data['records'] as $index => $variant) {
                    $variantData = $data['variants'][$index];
                    $skuValue = $variant->sku ?: '[EMPTY SKU]';

                    PriceInventoryLog::create([
                        'marketplace' => $marketplace,
                        'item_identifier' => $skuValue,
                        'change_type' => 'price_retry',
                        'from_value' => $variant->price,
                        'to_value' => $variantData['price'],
                        'status' => 'success',
                        'job_name' => $this->signature,
                        'message' => 'Retry successful: Price updated via GraphQL productVariantsBulkUpdate. Variant ID: '.$variant->variant_id,
                    ]);

                    $this->failureLogger->logSuccess($variant, 'price', ['job_name' => $this->signature]);

                    $variant->update([
                        'price' => $variantData['price'],
                        'compare_at_price' => $variantData['compare_at_price'] ?? 0,
                        'price_requires_update' => 0,
                    ]);

                    $this->info("✓ Price retry successful for {$skuValue}");
                }
            } else {
                $errorMessage = $this->formatErrorMessage($result, 'Retry failed - ');

                foreach ($data['records'] as $index => $variant) {
                    $skuValue = $variant->sku ?: '[EMPTY SKU]';

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

                    // Mark as status 3 (repeated failure)
                    $variant->update(['price_requires_update' => 3]);
                    $this->error("✗ Price retry failed for {$skuValue}");
                }
            }

            usleep(500000); // 0.5 second delay
        }
    }

    /**
     * Process inventory retries in bulk
     */
    private function processInventoryRetries(array $inventoryRetries, $location, string $marketplace)
    {
        $totalItems = count($inventoryRetries);
        if ($totalItems === 0) {
            return;
        }

        $this->info("Retrying {$totalItems} inventory update(s) in bulk...");

        $items = array_map(function ($item) {
            return [
                'inventory_item_id' => $item['inventory_item_id'],
                'quantity' => $item['quantity'],
            ];
        }, $inventoryRetries);

        $result = $this->graphqlService->bulkUpdateInventory($items, $location->location_id);

        if ($result['success']) {
            foreach ($inventoryRetries as $item) {
                $variant = $item['variant'];
                $skuValue = $variant->sku ?: '[EMPTY SKU]';

                PriceInventoryLog::create([
                    'marketplace' => $marketplace,
                    'item_identifier' => $skuValue,
                    'change_type' => 'inventory_retry',
                    'from_value' => $variant->inventory_quantity,
                    'to_value' => $item['quantity'],
                    'status' => 'success',
                    'job_name' => $this->signature,
                    'message' => 'Retry successful: Inventory updated via GraphQL inventorySetQuantities (bulk). Variant ID: '.$variant->variant_id,
                ]);

                $this->failureLogger->logSuccess($variant, 'inventory', ['job_name' => $this->signature]);

                $variant->update([
                    'inventory_quantity' => $item['quantity'],
                    'inventory_requires_update' => 0,
                ]);

                if ($item['quantity'] > 0 && $variant->product && $variant->product->status == 'archived') {
                    $this->reactivateProduct($variant->product, $marketplace);
                }

                $this->info("✓ Inventory retry successful for {$skuValue}");
            }
        } else {
            $errorMessage = $this->formatErrorMessage($result, 'Retry failed - ');

            foreach ($inventoryRetries as $item) {
                $variant = $item['variant'];
                $skuValue = $variant->sku ?: '[EMPTY SKU]';

                PriceInventoryLog::create([
                    'marketplace' => $marketplace,
                    'item_identifier' => $skuValue,
                    'change_type' => 'inventory_retry',
                    'from_value' => $variant->inventory_quantity,
                    'to_value' => $item['quantity'],
                    'status' => 'failed',
                    'job_name' => $this->signature,
                    'message' => $errorMessage,
                ]);

                // Mark as status 3 (repeated failure)
                $variant->update(['inventory_requires_update' => 3]);
                $this->error("✗ Inventory retry failed for {$skuValue}");
            }
        }
    }

    /**
     * Format error message from GraphQL result
     */
    private function formatErrorMessage(array $result, string $prefix = ''): string
    {
        $errorMessage = $prefix.'GraphQL Error: ';
        if (! empty($result['user_errors'])) {
            $errorMessage .= json_encode($result['user_errors']);
        } elseif (! empty($result['graphql_errors'])) {
            $errorMessage .= json_encode($result['graphql_errors']);
        } else {
            $errorMessage .= 'Unknown error';
        }

        return $errorMessage;
    }

    /**
     * Clean up stale variant record when Shopify returns "Product does not exist" or similar errors.
     * This removes the local record and resets the RetailEdge uploaded flag so the product can be recreated.
     */
    private function cleanupStaleVariant($variant): void
    {
        $sku = $variant->sku;
        $productId = $variant->shopify_product_id;

        // Delete variant record
        $variant->delete();

        // Check if product has other variants, if not delete product too
        $remainingVariants = ShopifyProductVariant::where('shopify_product_id', $productId)->count();
        if ($remainingVariants === 0) {
            ShopifyProduct::where('id', $productId)->delete();
        }

        // Reset RetailEdge uploaded flag so product can be recreated
        \App\Models\RetailEdgeProduct::where('sku', $sku)->update(['uploaded_to_shopify' => 0]);

        $this->info("🧹 Auto-cleaned stale variant: {$sku}");
        Log::info("Auto-cleaned stale Shopify variant: {$sku}");
    }

    /**
     * Check if error message indicates the product/variant no longer exists on Shopify
     */
    private function isProductNotExistsError(string $errorMessage): bool
    {
        return str_contains($errorMessage, 'Product does not exist')
            || str_contains($errorMessage, 'inventory item could not be found');
    }
}
