<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2025_04\Image;

class CountImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyCountImages';

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
        $jobType = 'shopifyCountImages';

        // Acquire lock using locking system
        $job = SyncJobController::acquireLock($jobType, $marketplace);
        if (! $job) {
            $this->warn('Job is already running or paused.');
            Log::info("$marketplace $jobType: Cannot acquire lock (running or paused)");

            return Command::SUCCESS;
        }

        try {
            Log::info("$marketplace $jobType started!");

            $this->getImagesCount();

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

    public function getImagesCount()
    {
        try {
            $session = (new ShopifyService)->getSession();

            $variants = ShopifyProductVariant::select('id', 'product_id', 'variant_id', 'sku')->get();
            $totalVariants = count($variants);
            $this->info("Processing {$totalVariants} variants");

            $processedCount = 0;
            $errorCount = 0;

            foreach ($variants as $variant) {
                $processedCount++;
                $skuValue = $variant->sku ? $variant->sku : '[EMPTY SKU]';

                if (! $variant->product_id) {
                    $this->warn("Skipping variant {$skuValue} (ID: {$variant->id}) - Missing product_id");

                    continue;
                }

                $maxRetries = 3;
                $retryCount = 0;
                $success = false;

                while (! $success && $retryCount < $maxRetries) {
                    try {
                        if ($retryCount > 0) {
                            $this->info("Retry #{$retryCount} for SKU: {$skuValue}");
                            // Exponential backoff: 2, 4, 8 seconds
                            sleep(pow(2, $retryCount));
                        }

                        $this->info("Fetching images count for SKU: {$skuValue} ({$processedCount}/{$totalVariants})");
                        $resp = Image::count(
                            $session,
                            ['product_id' => $variant->product_id],
                        );

                        $this->info("Found {$resp['count']} images.");
                        if ($resp['count'] == 0) {
                            $variant->update(['images_requires_update' => 1]);
                        }

                        $success = true;
                    } catch (\GuzzleHttp\Exception\ConnectException $e) {
                        $retryCount++;
                        $errorMessage = $e->getMessage();

                        if ($retryCount >= $maxRetries) {
                            $errorCount++;
                            Log::error("SSL/Connection error after {$maxRetries} retries for product_id: {$variant->product_id}, SKU: {$skuValue}. Error: {$errorMessage}");
                            $this->error("Failed after {$maxRetries} retries: {$errorMessage}");
                        } else {
                            Log::warning("SSL/Connection error for product_id: {$variant->product_id}, SKU: {$skuValue}. Retrying... Error: {$errorMessage}");
                        }
                    } catch (\Exception $e) {
                        $errorCount++;
                        $errorMessage = $e->getMessage();
                        Log::error("Error fetching images count for product_id: {$variant->product_id}, SKU: {$skuValue}. Error: {$errorMessage}");
                        $this->error($errorMessage);
                        break; // Don't retry for non-connection errors
                    }
                }

                // Add a longer delay between requests to avoid rate limiting
                sleep(2);

                // Every 50 requests, take a longer break to avoid rate limits
                if ($processedCount % 50 == 0) {
                    $this->info("Processed {$processedCount}/{$totalVariants} variants. Taking a short break...");
                    sleep(10);
                }
            }

            $this->info("Completed processing {$totalVariants} variants with {$errorCount} errors.");
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            Log::error("Fatal error in getImagesCount: {$errorMessage}");
            report($e);
            $this->error($errorMessage);
        }
    }
}
