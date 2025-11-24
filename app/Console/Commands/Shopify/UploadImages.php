<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2025_04\Image;

class UploadImages extends Command
{
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
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'uploadImages';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (! $job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);
                $session = (new ShopifyService)->getSession();

                $count = ShopifyProductVariant::where('images_requires_update', 1)->count();
                $this->info("Remaining {$count}");

                while ($count) {
                    if ($variant = ShopifyProductVariant::where('images_requires_update', 1)->with(['images', 'product'])->first()) {
                        $this->info("Uploading images for {$variant->sku}");
                        if ($variant->images->count()) {
                            foreach ($variant->images as $i) {
                                try {
                                    $image = new Image($session);
                                    $image->product_id = $variant->product_id;
                                    $image->src = $i->url;
                                    $image->variant_ids = [
                                        $variant->variant_id,
                                    ];

                                    $image->save(
                                        true, // Update Object
                                    );
                                    $this->info("Image uploaded for sku {$variant->sku}, variant id  {$variant->variant_id}");
                                    $variant->update(['images_requires_update' => 0]);
                                } catch (\Exception $e) {
                                    Log::debug("There was an error while uploading the images for {$variant->sku}. Error message : {$e->getMessage()}");
                                    $variant->update(['images_requires_update' => 2]);
                                }
                            }
                            sleep(2);
                        } else {
                            $variant->update(['images_requires_update' => 2]);
                            Log::debug("No images found on Retail Edge for {$variant->sku}");
                            Log::debug('shopifyUploadImages sleep 60 seconds');
                            sleep(60);
                        }
                    } else {
                        $this->error('No variant found with images_requires_update = 1');
                    }

                    $count = ShopifyProductVariant::where('images_requires_update', 1)->count();
                    $this->info("Remaining {$count}");
                }

                $job->update(['status' => 0]);

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

    public function handleBackup()
    {
        $marketplace = 'Shopify';
        $jobType = 'uploadImages';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (! $job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);
                $session = (new ShopifyService)->getSession();

                $count = ShopifyProductVariant::where('images_requires_update', 1)->count();
                $this->info("Remaining {$count}");

                while ($count) {
                    $variant = ShopifyProductVariant::where('images_requires_update', 1)->with(['images', 'product'])->first();

                    if (! $variant) {
                        $this->error('No variant found with images_requires_update = 1');
                    }

                    $this->info("Uploading images for {$variant->sku}");

                    if ($variant->images) {
                        foreach ($variant->images as $i) {
                            try {
                                $image = new Image($session);
                                $image->product_id = $variant->product_id;
                                $image->src = $i->url;
                                $image->variant_ids = [
                                    $variant->variant_id,
                                ];

                                $image->save(
                                    true, // Update Object
                                );
                                $this->info("Image uploaded for sku {$variant->sku}, variant id  {$variant->variant_id}");
                                $variant->update(['images_requires_update' => 0]);
                            } catch (\Exception $e) {
                                Log::debug("There was an error while uploading the images for {$variant->sku}. Error message : {$e->getMessage()}");
                                $variant->update(['images_requires_update' => 2]);
                            }
                        }
                    }
                    $variant->update(['images_requires_update' => 0]);
                    $count = ShopifyProductVariant::where('images_requires_update', 1)->count();
                    $this->info("Remaining {$count}");
                }

                $job->update(['status' => 0]);

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
