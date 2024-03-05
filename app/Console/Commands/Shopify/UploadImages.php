<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2024_01\Image;
use App\Http\Controllers\SyncJobController;
use App\Services\ShopifyService;
use App\Models\ShopifyProductVariant;

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

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);
                $session = (new ShopifyService)->getSession();

                $variants = ShopifyProductVariant::with('images')->get();

                foreach ($variants as $variant) {
                    if ($variant->images) {
                        foreach ($variant->images as $i) {
                            $image = new Image($session);
                            $image->product_id = $variant->product_id;
                            $image->src = $i->url;
                            $image->variant_ids = [
                                $variant->variant_id
                            ];

                            $image->save(
                                true, // Update Object
                            );
                            $this->info('Image uploaded.');
                        }
                    }
                }

                $job->update(['status' => 0]);

                Log::info("$marketplace $jobType finished!");
            } catch (\Exception $e) {
                $job->update(['status' => 0]);
                report($e);
            }
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
