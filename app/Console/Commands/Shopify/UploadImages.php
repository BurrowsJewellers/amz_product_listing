<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyGraphQLService;
use App\Traits\ShopifyCleanupTrait;
use App\Traits\ShopifyErrorFormatterTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UploadImages extends Command
{
    use ShopifyCleanupTrait;
    use ShopifyErrorFormatterTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyUploadImages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upload images to Shopify products using GraphQL';

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
        $jobType = 'shopifyUploadImages';

        // Acquire lock using locking system
        $job = SyncJobController::acquireLock($jobType, $marketplace);
        if (! $job) {
            $this->warn('Job is already running or paused.');
            Log::info("$marketplace $jobType: Cannot acquire lock (running or paused)");

            return Command::SUCCESS;
        }

        try {
            Log::info("$marketplace $jobType started!");

            $this->processImageUploads($marketplace);

            $job->finishJob();
            Log::info("$marketplace $jobType finished!");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $job->finishJob($e->getMessage());
            report($e);
            $this->error($e->getMessage());
            Log::error("$marketplace $jobType failed: ".$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Process image uploads for variants that need images
     */
    private function processImageUploads(string $marketplace): void
    {
        // Get initial count once - avoid N+1 queries by decrementing instead of re-querying
        $remainingCount = ShopifyProductVariant::where('images_requires_update', 1)->count();
        $this->info("Found {$remainingCount} variant(s) requiring image uploads");

        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        $cleanedCount = 0;

        while ($remainingCount > 0) {
            try {
                $variant = ShopifyProductVariant::where('images_requires_update', 1)
                    ->with(['images', 'product'])
                    ->first();

                if (! $variant) {
                    $this->warn('No variant found with images_requires_update = 1');
                    break;
                }

                $sku = $variant->sku ?: '[EMPTY SKU]';
                $this->info("Processing images for {$sku}");

                // Validate variant has required IDs
                if (empty($variant->product_id)) {
                    Log::warning("shopifyUploadImages: Variant {$sku} has no product_id - marking as failed");
                    $variant->update(['images_requires_update' => 2]);
                    $failedCount++;
                    $remainingCount--;

                    continue;
                }

                // Check if variant has images to upload
                if (! $variant->images || $variant->images->isEmpty()) {
                    $variant->update(['images_requires_update' => 2]);
                    Log::debug("No images found in RetailEdge for {$sku}");
                    $this->warn("No images available for {$sku} - marked for review");
                    $skippedCount++;
                    $remainingCount--;

                    // Short delay before next variant
                    usleep(500000);

                    continue;
                }

                // Collect and validate image URLs
                $imageUrls = $variant->images->pluck('url')->filter(function ($url) {
                    return ! empty($url) && filter_var($url, FILTER_VALIDATE_URL);
                })->toArray();

                if (empty($imageUrls)) {
                    $variant->update(['images_requires_update' => 2]);
                    Log::warning("shopifyUploadImages: No valid image URLs for {$sku}");
                    $this->warn("No valid image URLs for {$sku} - marked for review");
                    $skippedCount++;
                    $remainingCount--;

                    continue;
                }

                $imageCount = count($imageUrls);
                $this->info("Uploading {$imageCount} image(s) for {$sku}");

                // Upload all images at once using GraphQL batch
                $result = $this->graphqlService->createProductMedia($variant->product_id, $imageUrls);

                if ($result['success'] && ! empty($result['media'])) {
                    // Assign first image to variant if we have media
                    $firstMediaId = $result['media'][0]['id'] ?? null;
                    if ($firstMediaId && $variant->variant_id) {
                        $assignResult = $this->graphqlService->assignMediaToVariant($variant->variant_id, $firstMediaId);
                        if (! $assignResult['success']) {
                            $this->warn("Images uploaded but could not assign to variant {$sku}: ".$this->formatGraphQLErrorMessage($assignResult));
                        }
                    }

                    $variant->update(['images_requires_update' => 0]);
                    $this->info("Uploaded {$imageCount} image(s) for {$sku}");
                    Log::info("shopifyUploadImages: Uploaded {$imageCount} image(s) for SKU {$sku}");
                    $successCount++;
                } else {
                    $errorMessage = $this->formatGraphQLErrorMessage($result);

                    // Check if product no longer exists on Shopify - clean up stale record
                    if ($this->isResourceNotExistsError($errorMessage)) {
                        $this->cleanupStaleVariant($variant, 'shopifyUploadImages');
                        $cleanedCount++;
                        $remainingCount--;

                        continue;
                    }

                    $variant->update(['images_requires_update' => 2]);
                    $this->error("Failed to upload images for {$sku}: {$errorMessage}");
                    Log::error("shopifyUploadImages: Failed for SKU {$sku}: {$errorMessage}");
                    $failedCount++;
                }

                $remainingCount--;
            } catch (\Throwable $e) {
                $variantSku = isset($variant) ? ($variant->sku ?: '[EMPTY SKU]') : 'unknown';
                $msg = "Exception uploading images for {$variantSku}: {$e->getMessage()}";
                $this->error($msg);
                Log::error($msg);
                report($e);

                // Mark variant as failed if we have one
                if (isset($variant)) {
                    $variant->update(['images_requires_update' => 2]);
                }

                $failedCount++;
                $remainingCount--;
            }

            // Delay between variants to avoid rate limiting (500ms)
            usleep(500000);

            $this->info("Remaining: {$remainingCount}");
        }

        $summary = "Image upload complete: {$successCount} succeeded, {$failedCount} failed, {$skippedCount} skipped (no images)";
        if ($cleanedCount > 0) {
            $summary .= ", {$cleanedCount} stale records cleaned";
        }
        $this->info($summary);
        Log::info("$marketplace shopifyUploadImages: {$successCount} succeeded, {$failedCount} failed, {$skippedCount} skipped, {$cleanedCount} cleaned");
    }
}
