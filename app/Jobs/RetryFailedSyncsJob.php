<?php

namespace App\Jobs;

use App\Models\ShopifyLocation;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Models\SyncRetryJob;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncFailureLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2025_04\Product as ShopifyProductAPI;

class RetryFailedSyncsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $retryJobId;

    public array $flags;

    public ?string $triggeredBy;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 3600; // 1 hour

    /**
     * Create a new job instance.
     */
    public function __construct(int $retryJobId, array $flags = [2, 3], ?string $triggeredBy = null)
    {
        $this->retryJobId = $retryJobId;
        $this->flags = $flags;
        $this->triggeredBy = $triggeredBy;
    }

    /**
     * Execute the job.
     */
    public function handle(ShopifyGraphQLService $graphqlService, SyncFailureLogger $failureLogger): void
    {
        $retryJob = SyncRetryJob::find($this->retryJobId);

        if (! $retryJob) {
            Log::error('RetryFailedSyncsJob: Retry job not found', ['job_id' => $this->retryJobId]);

            return;
        }

        try {
            // Update job status to processing
            $retryJob->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);

            Log::info('RetryFailedSyncsJob started', [
                'job_id' => $this->retryJobId,
                'flags' => $this->flags,
                'triggered_by' => $this->triggeredBy,
            ]);

            // Get location for inventory updates
            $location = ShopifyLocation::first();
            if (! $location) {
                throw new \Exception('No Shopify location found');
            }

            // Get all variants that need retry
            $variants = ShopifyProductVariant::with(['retailEdgeProduct', 'product'])
                ->whereNotNull('variant_id')
                ->whereNotNull('product_id')
                ->where(function ($query) {
                    foreach ($this->flags as $flag) {
                        $query->orWhere('price_requires_update', $flag)
                            ->orWhere('inventory_requires_update', $flag);
                    }
                })
                ->get();

            $totalVariants = $variants->count();

            // Update total items
            $retryJob->update(['total_items' => $totalVariants]);

            if ($totalVariants === 0) {
                $retryJob->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
                Log::info('RetryFailedSyncsJob completed: No items to retry');

                return;
            }

            Log::info("RetryFailedSyncsJob processing {$totalVariants} variant(s)");

            // Group variants by product for batch processing
            $variantsByProduct = $variants->groupBy('product_id');
            $successCount = 0;
            $failureCount = 0;
            $processedCount = 0;

            foreach ($variantsByProduct as $productId => $productVariants) {
                // Prepare variant data for GraphQL
                $variantsData = [];
                $variantRecords = [];

                foreach ($productVariants as $variant) {
                    // Reset flag from 3 to 1 for retry (give it a fresh chance)
                    if ($variant->price_requires_update == 3) {
                        $variant->update(['price_requires_update' => 1]);
                    }
                    if ($variant->inventory_requires_update == 3) {
                        $variant->update(['inventory_requires_update' => 1]);
                    }
                    $variant->refresh(); // Reload to get updated flags

                    if (! $variant->retailEdgeProduct) {
                        $failureLogger->logFailure(
                            $variant,
                            'price_inventory',
                            'Missing RetailEdgeProduct',
                            null,
                            [
                                'job_name' => 'RetryFailedSyncsJob',
                                'retry_job_id' => $this->retryJobId,
                            ]
                        );
                        $failureCount++;
                        $processedCount++;
                        continue;
                    }

                    $variantData = ['variant_id' => $variant->variant_id];

                    // Add price data if flag indicates update needed
                    if (in_array($variant->price_requires_update, [1, 2])) {
                        $variantData['price'] = $variant->retailEdgeProduct->price;
                        $compareAtPrice = $variant->retailEdgeProduct->compare_at_price;
                        $variantData['compare_at_price'] = ($compareAtPrice == 0 || $compareAtPrice == $variantData['price'])
                            ? null
                            : $compareAtPrice;
                    }

                    // Add inventory data if flag indicates update needed
                    if (in_array($variant->inventory_requires_update, [1, 2])) {
                        $variantData['inventory_quantity'] = $variant->retailEdgeProduct->quantity;
                    }

                    $variantsData[] = $variantData;
                    $variantRecords[] = $variant;
                }

                if (empty($variantsData)) {
                    continue;
                }

                // Execute GraphQL mutation
                $result = $graphqlService->updateProductPriceAndInventory(
                    $productId,
                    $variantsData,
                    $location->location_id
                );

                if ($result['success']) {
                    // Handle success
                    foreach ($variantRecords as $index => $variant) {
                        $variantData = $variantsData[$index];
                        $this->handleSuccess($variant, $variantData, $location, $failureLogger);
                        $successCount++;
                    }
                } else {
                    // Handle failure
                    foreach ($variantRecords as $index => $variant) {
                        $variantData = $variantsData[$index];
                        $this->handleFailure($variant, $variantData, $result, $failureLogger);
                        $failureCount++;
                    }
                }

                $processedCount += count($variantRecords);

                // Update progress
                $retryJob->update([
                    'processed_items' => $processedCount,
                    'successful_items' => $successCount,
                    'failed_items' => $failureCount,
                ]);

                // Rate limiting
                $delay = config('sync.retry_rate_limiting.delay_between_items', 500);
                usleep($delay * 1000); // Convert ms to microseconds
            }

            // Mark job as completed
            $retryJob->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            Log::info('RetryFailedSyncsJob completed', [
                'job_id' => $this->retryJobId,
                'total' => $totalVariants,
                'success' => $successCount,
                'failed' => $failureCount,
            ]);
        } catch (\Exception $e) {
            // Mark job as failed
            $retryJob->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            Log::error('RetryFailedSyncsJob failed', [
                'job_id' => $this->retryJobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle successful update
     */
    private function handleSuccess(
        ShopifyProductVariant $variant,
        array $variantData,
        ShopifyLocation $location,
        SyncFailureLogger $failureLogger
    ): void {
        $updates = [];

        // Handle price update
        if (isset($variantData['price'])) {
            $updates['price'] = $variantData['price'];
            $updates['compare_at_price'] = $variantData['compare_at_price'] ?? 0;
            $updates['price_requires_update'] = 0;

            $failureLogger->logSuccess($variant, 'price', [
                'job_name' => 'RetryFailedSyncsJob',
                'retry_job_id' => $this->retryJobId,
            ]);
        }

        // Handle inventory update
        if (isset($variantData['inventory_quantity'])) {
            $updates['inventory_quantity'] = $variantData['inventory_quantity'];
            $updates['inventory_requires_update'] = 0;

            $failureLogger->logSuccess($variant, 'inventory', [
                'job_name' => 'RetryFailedSyncsJob',
                'retry_job_id' => $this->retryJobId,
            ]);

            // Reactivate product if inventory > 0 and currently archived
            if ($variantData['inventory_quantity'] > 0 && $variant->product && $variant->product->status == 'archived') {
                $this->reactivateProduct($variant->product);
            }
        }

        $variant->update($updates);
    }

    /**
     * Handle failed update
     */
    private function handleFailure(
        ShopifyProductVariant $variant,
        array $variantData,
        array $result,
        SyncFailureLogger $failureLogger
    ): void {
        $operationType = 'price_inventory';
        if (isset($variantData['price']) && ! isset($variantData['inventory_quantity'])) {
            $operationType = 'price';
        } elseif (isset($variantData['inventory_quantity']) && ! isset($variantData['price'])) {
            $operationType = 'inventory';
        }

        $failureLogger->logFailure(
            $variant,
            $operationType,
            'GraphQL API Error: '.json_encode($result['user_errors'] ?? $result['graphql_errors']),
            $result,
            [
                'job_name' => 'RetryFailedSyncsJob',
                'retry_job_id' => $this->retryJobId,
                'api_request' => $variantData,
                'user_errors' => $result['user_errors'] ?? null,
                'graphql_errors' => $result['graphql_errors'] ?? null,
                'target_data' => $variantData,
            ]
        );

        // Keep flags at 2 for further retry attempts
        // Don't escalate to 3 on manual retry - give admin visibility
    }

    /**
     * Reactivate archived product
     */
    private function reactivateProduct(ShopifyProduct $product): void
    {
        try {
            $graphqlService = app(ShopifyGraphQLService::class);
            $session = $graphqlService->getSession();

            $shopifyProductAPI = new ShopifyProductAPI($session);
            $shopifyProductAPI->id = $product->product_id;
            $shopifyProductAPI->status = 'active';
            $shopifyProductAPI->save(true);

            $product->update(['status' => 'active']);

            Log::info("Product reactivated: {$product->title} (ID: {$product->product_id})");
        } catch (\Exception $e) {
            Log::error("Failed to reactivate product: {$product->title}", ['error' => $e->getMessage()]);
        }
    }
}
