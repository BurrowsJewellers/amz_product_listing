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
        $totalCount = ShopifyProductVariant::where('images_requires_update', 1)->count();
        $remainingCount = $totalCount;

        $this->newLine();
        $this->info('========================================');
        $this->info('  Shopify Image Upload Process Started');
        $this->info('========================================');
        $this->info("Total variants requiring image uploads: {$totalCount}");
        $this->newLine();

        if ($totalCount === 0) {
            $this->info('No variants require image uploads. Exiting.');

            return;
        }

        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        $cleanedCount = 0;
        $processedCount = 0;

        while ($remainingCount > 0) {
            try {
                $variant = ShopifyProductVariant::where('images_requires_update', 1)
                    ->with(['images', 'product'])
                    ->first();

                if (! $variant) {
                    $this->warn('No variant found with images_requires_update = 1');
                    break;
                }

                $processedCount++;
                $sku = $variant->sku ?: '[EMPTY SKU]';
                $productTitle = $variant->product?->title ?? '[Unknown Product]';
                $productTitle = strlen($productTitle) > 50 ? substr($productTitle, 0, 47).'...' : $productTitle;

                $this->newLine();
                $this->line('----------------------------------------');
                $this->info("[{$processedCount}/{$totalCount}] Processing: {$sku}");
                $this->line("  Product: {$productTitle}");
                $this->line('  Product ID: '.($variant->product_id ?: 'N/A'));
                $this->line('  Variant ID: '.($variant->variant_id ?: 'N/A'));

                // Validate variant has required IDs
                if (empty($variant->product_id)) {
                    $this->error('  ✗ No product_id - marking as failed');
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
                    $this->warn('  ⚠ No images available in RetailEdge - marked for review');
                    $skippedCount++;
                    $remainingCount--;

                    // Short delay before next variant
                    usleep(500000);

                    continue;
                }

                $this->line("  Images in database: {$variant->images->count()}");

                // Collect and validate image URLs
                $imageUrls = $variant->images->pluck('url')->filter(function ($url) {
                    return ! empty($url) && filter_var($url, FILTER_VALIDATE_URL);
                })->toArray();

                if (empty($imageUrls)) {
                    $variant->update(['images_requires_update' => 2]);
                    Log::warning("shopifyUploadImages: No valid image URLs for {$sku}");
                    $this->warn('  ⚠ No valid image URLs found - marked for review');
                    $skippedCount++;
                    $remainingCount--;

                    continue;
                }

                $imageCount = count($imageUrls);
                $this->info("  Uploading {$imageCount} image(s) to Shopify...");

                // Display image URLs being uploaded
                foreach ($imageUrls as $index => $url) {
                    $urlDisplay = strlen($url) > 60 ? '...'.substr($url, -57) : $url;
                    $this->line('    ['.($index + 1)."] {$urlDisplay}");
                }

                // Upload all images at once using GraphQL batch
                $result = $this->graphqlService->createProductMedia($variant->product_id, $imageUrls);

                if ($result['success'] && ! empty($result['media'])) {
                    $uploadedMediaCount = count($result['media']);
                    $this->info("  ✓ Uploaded {$uploadedMediaCount} media file(s)");

                    // Display uploaded media IDs
                    foreach ($result['media'] as $index => $media) {
                        $mediaId = $media['id'] ?? 'N/A';
                        $mediaStatus = $media['status'] ?? 'unknown';
                        $this->line('    Media '.($index + 1).": {$mediaId} (status: {$mediaStatus})");
                    }

                    // Assign all images to variant if we have media
                    $mediaIds = array_filter(array_column($result['media'], 'id'));
                    if (! empty($mediaIds) && $variant->variant_id) {
                        $mediaCount = count($mediaIds);
                        $this->line("  Assigning {$mediaCount} media file(s) to variant...");
                        $assignResult = $this->graphqlService->assignMediaToVariant(
                            $variant->product_id,
                            $variant->variant_id,
                            $mediaIds
                        );
                        if ($assignResult['success']) {
                            $this->info("  ✓ {$mediaCount} media file(s) assigned to variant successfully");
                        } else {
                            $this->warn('  ⚠ Could not assign media to variant: '.$this->formatGraphQLErrorMessage($assignResult));
                        }
                    } elseif (! $variant->variant_id) {
                        $this->line('  (Skipping variant assignment - no variant_id)');
                    }

                    $variant->update(['images_requires_update' => 0]);
                    Log::info("shopifyUploadImages: Uploaded {$imageCount} image(s) for SKU {$sku}");
                    $successCount++;
                } else {
                    $errorMessage = $this->formatGraphQLErrorMessage($result);

                    // Check if product no longer exists on Shopify - clean up stale record
                    if ($this->isResourceNotExistsError($errorMessage)) {
                        $this->warn('  ⚠ Product no longer exists on Shopify - cleaning up stale record');
                        $this->cleanupStaleVariant($variant, 'shopifyUploadImages');
                        $cleanedCount++;
                        $remainingCount--;

                        continue;
                    }

                    $variant->update(['images_requires_update' => 2]);
                    $this->error("  ✗ Upload failed: {$errorMessage}");
                    Log::error("shopifyUploadImages: Failed for SKU {$sku}: {$errorMessage}");
                    $failedCount++;
                }

                $remainingCount--;
            } catch (\Throwable $e) {
                $variantSku = isset($variant) ? ($variant->sku ?: '[EMPTY SKU]') : 'unknown';
                $this->error("  ✗ Exception: {$e->getMessage()}");
                Log::error("Exception uploading images for {$variantSku}: {$e->getMessage()}");
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

            // Progress summary
            $percentComplete = $totalCount > 0 ? round(($processedCount / $totalCount) * 100) : 0;
            $this->line("  Progress: {$processedCount}/{$totalCount} ({$percentComplete}%) | Remaining: {$remainingCount}");
        }

        // Final summary
        $this->newLine();
        $this->info('========================================');
        $this->info('         Upload Process Complete');
        $this->info('========================================');
        $this->table(
            ['Status', 'Count'],
            [
                ['✓ Succeeded', $successCount],
                ['✗ Failed', $failedCount],
                ['⚠ Skipped (no images)', $skippedCount],
                ['🧹 Stale records cleaned', $cleanedCount],
                ['Total Processed', $processedCount],
            ]
        );
        $this->newLine();

        Log::info("$marketplace shopifyUploadImages: {$successCount} succeeded, {$failedCount} failed, {$skippedCount} skipped, {$cleanedCount} cleaned");
    }
}
