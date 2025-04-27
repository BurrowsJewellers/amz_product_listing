<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2024_10\Image;
use App\Services\ShopifyService;
use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProductVariant;

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

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $this->getImagesCount();

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

                if (!$variant->product_id) {
                    $this->warn("Skipping variant {$skuValue} (ID: {$variant->id}) - Missing product_id");
                    continue;
                }

                $maxRetries = 3;
                $retryCount = 0;
                $success = false;

                while (!$success && $retryCount < $maxRetries) {
                    try {
                        if ($retryCount > 0) {
                            $this->info("Retry #{$retryCount} for SKU: {$skuValue}");
                            // Exponential backoff: 2, 4, 8 seconds
                            sleep(pow(2, $retryCount));
                        }

                        $this->info("Fetching images count for SKU: {$skuValue} ({$processedCount}/{$totalVariants})");
                        $resp = Image::count(
                            $session,
                            ["product_id" => $variant->product_id],
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
