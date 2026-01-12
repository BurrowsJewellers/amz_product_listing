<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncLogger;
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

    private SyncLogger $syncLogger;

    public function __construct(ShopifyGraphQLService $graphqlService)
    {
        parent::__construct();
        $this->graphqlService = $graphqlService;
        $this->syncLogger = new SyncLogger;
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
                    $variant->update(['images_requires_update' => 2]);
                    $failedCount++;
                    $remainingCount--;

                    // Log failure with SyncLogger
                    $this->syncLogger->logFailure(
                        SyncLogger::MARKETPLACE_SHOPIFY,
                        'shopifyUploadImages',
                        $sku,
                        SyncLogger::OP_IMAGE_UPLOAD,
                        'Variant has no product_id',
                        [
                            'item_title' => $productTitle,
                            'shopify_variant_id' => $variant->variant_id,
                        ]
                    );

                    continue;
                }

                // Check if variant has images to upload
                if (! $variant->images || $variant->images->isEmpty()) {
                    $variant->update(['images_requires_update' => 2]);
                    $this->warn('  ⚠ No images available in RetailEdge - marked for review');
                    $skippedCount++;
                    $remainingCount--;

                    // Log skipped with SyncLogger
                    $this->syncLogger->logSkipped(
                        SyncLogger::MARKETPLACE_SHOPIFY,
                        'shopifyUploadImages',
                        $sku,
                        SyncLogger::OP_IMAGE_UPLOAD,
                        'No images found in RetailEdge',
                        [
                            'item_title' => $productTitle,
                            'shopify_product_id' => $variant->product_id,
                            'shopify_variant_id' => $variant->variant_id,
                        ]
                    );

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
                    $this->warn('  ⚠ No valid image URLs found - marked for review');
                    $skippedCount++;
                    $remainingCount--;

                    // Log skipped with SyncLogger
                    $this->syncLogger->logSkipped(
                        SyncLogger::MARKETPLACE_SHOPIFY,
                        'shopifyUploadImages',
                        $sku,
                        SyncLogger::OP_IMAGE_UPLOAD,
                        'No valid image URLs found',
                        [
                            'item_title' => $productTitle,
                            'shopify_product_id' => $variant->product_id,
                            'shopify_variant_id' => $variant->variant_id,
                        ]
                    );

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
                    $assignmentSucceeded = false;

                    if (! empty($mediaIds) && $variant->variant_id) {
                        $mediaCount = count($mediaIds);

                        // Wait for media to finish processing before assigning to variant
                        $this->line('  Waiting for media to finish processing...');
                        $waitResult = $this->graphqlService->waitForMediaReady($variant->product_id, $mediaIds);

                        if (! empty($waitResult['ready'])) {
                            $readyCount = count($waitResult['ready']);
                            $this->line("  {$readyCount}/{$mediaCount} media file(s) ready");

                            // Assign only ready media to variant
                            $this->line("  Assigning {$readyCount} media file(s) to variant...");
                            $assignResult = $this->graphqlService->assignMediaToVariant(
                                $variant->product_id,
                                $variant->variant_id,
                                $waitResult['ready']
                            );

                            if ($assignResult['success']) {
                                $assigned = $assignResult['assigned_count'] ?? $readyCount;
                                $this->info("  ✓ {$assigned}/{$readyCount} media file(s) assigned to variant");
                                $assignmentSucceeded = true;
                            } else {
                                $this->warn('  ⚠ Could not assign media to variant: '.$this->formatGraphQLErrorMessage($assignResult));
                            }
                        } else {
                            $this->warn('  ⚠ No media became ready after waiting (timed out or failed)');
                            if (! empty($waitResult['failed'])) {
                                $this->warn('    Failed media: '.count($waitResult['failed']));
                            }
                            if (! empty($waitResult['pending'])) {
                                $this->warn('    Still pending: '.count($waitResult['pending']));
                            }
                        }
                    } elseif (! $variant->variant_id) {
                        $this->line('  (Skipping variant assignment - no variant_id)');
                        $assignmentSucceeded = true; // No variant to assign, upload alone is success
                    } else {
                        $assignmentSucceeded = true; // No media IDs to assign
                    }

                    // Only mark success if assignment succeeded (or wasn't needed)
                    if ($assignmentSucceeded) {
                        $variant->update(['images_requires_update' => 0]);
                        $successCount++;

                        // Log success with SyncLogger
                        $this->syncLogger->logSuccess(
                            SyncLogger::MARKETPLACE_SHOPIFY,
                            'shopifyUploadImages',
                            $sku,
                            SyncLogger::OP_IMAGE_UPLOAD,
                            [
                                'item_title' => $productTitle,
                                'to_value' => "{$imageCount} image(s)",
                                'message' => "Uploaded {$imageCount} image(s) successfully",
                                'shopify_product_id' => $variant->product_id,
                                'shopify_variant_id' => $variant->variant_id,
                            ]
                        );
                    } else {
                        // Mark for retry - assignment failed
                        $variant->update(['images_requires_update' => 2]);
                        $failCount++;

                        $this->syncLogger->logError(
                            SyncLogger::MARKETPLACE_SHOPIFY,
                            'shopifyUploadImages',
                            $sku,
                            SyncLogger::OP_IMAGE_UPLOAD,
                            'Media uploaded but variant assignment failed - will retry',
                            [
                                'item_title' => $productTitle,
                                'shopify_product_id' => $variant->product_id,
                                'shopify_variant_id' => $variant->variant_id,
                            ]
                        );
                    }
                } else {
                    $errorMessage = $this->formatGraphQLErrorMessage($result);

                    // Check if product no longer exists on Shopify - clean up stale record
                    if ($this->isResourceNotExistsError($errorMessage)) {
                        $this->warn('  ⚠ Product no longer exists on Shopify - cleaning up stale record');
                        $this->cleanupStaleVariant($variant, 'shopifyUploadImages');
                        $cleanedCount++;
                        $remainingCount--;

                        // Log cleanup success with SyncLogger
                        $this->syncLogger->logSuccess(
                            SyncLogger::MARKETPLACE_SHOPIFY,
                            'shopifyUploadImages',
                            $sku,
                            SyncLogger::OP_DUPLICATE_CLEANUP,
                            [
                                'item_title' => $productTitle,
                                'message' => 'Cleaned up stale database record (product not found on Shopify)',
                                'shopify_product_id' => $variant->product_id,
                                'shopify_variant_id' => $variant->variant_id,
                            ]
                        );

                        continue;
                    }

                    $variant->update(['images_requires_update' => 2]);
                    $this->error("  ✗ Upload failed: {$errorMessage}");
                    $failedCount++;

                    // Log failure with SyncLogger
                    $this->syncLogger->logFailure(
                        SyncLogger::MARKETPLACE_SHOPIFY,
                        'shopifyUploadImages',
                        $sku,
                        SyncLogger::OP_IMAGE_UPLOAD,
                        $errorMessage,
                        [
                            'item_title' => $productTitle,
                            'shopify_product_id' => $variant->product_id,
                            'shopify_variant_id' => $variant->variant_id,
                            'errors' => array_merge($result['user_errors'] ?? [], $result['graphql_errors'] ?? []),
                        ]
                    );
                }

                $remainingCount--;
            } catch (\Throwable $e) {
                $variantSku = isset($variant) ? ($variant->sku ?: '[EMPTY SKU]') : 'unknown';
                $variantTitle = isset($variant) ? ($variant->product?->title ?? '[Unknown Product]') : '[Unknown]';
                $this->error("  ✗ Exception: {$e->getMessage()}");
                report($e);

                // Mark variant as failed if we have one
                if (isset($variant)) {
                    $variant->update(['images_requires_update' => 2]);
                }

                $failedCount++;
                $remainingCount--;

                // Log failure with SyncLogger
                $this->syncLogger->logFailure(
                    SyncLogger::MARKETPLACE_SHOPIFY,
                    'shopifyUploadImages',
                    $variantSku,
                    SyncLogger::OP_IMAGE_UPLOAD,
                    $e,
                    [
                        'item_title' => $variantTitle,
                        'shopify_product_id' => isset($variant) ? $variant->product_id : null,
                        'shopify_variant_id' => isset($variant) ? $variant->variant_id : null,
                    ]
                );
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

        // Individual operations logged via SyncLogger; job lifecycle logged by handle()
    }
}
