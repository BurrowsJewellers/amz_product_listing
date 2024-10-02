<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2024_01\Image;
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

            $variants = ShopifyProductVariant::select('id', 'product_id', 'variant_id', 'sku')->where('inventory_quantity', '>', 0)->get();

            foreach ($variants as $variant) {
                try {
                    $this->info("Fetching images count for SKU: {$variant->sku}");
                    $resp = Image::count(
                        $session,
                        ["product_id" => $variant->product_id],
                    );

                    $this->info("Found {$resp['count']} images.");
                    if ($resp['count'] == 0) {
                        $variant->update(['images_requires_update' => 1]);
                    }
                } catch (\Exception $e) {
                    report($e);
                    $this->error($e->getMessage());
                }
                sleep(1);
            }
        } catch (\Exception $e) {
            report($e);
            $this->error($e->getMessage());
        }
    }
}
