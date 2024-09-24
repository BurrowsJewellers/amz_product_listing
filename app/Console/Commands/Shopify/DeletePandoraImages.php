<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2024_07\Image;
use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;

class DeletePandoraImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyDeletePandoraImages';

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
        $jobType = 'shopifyDeletePandoraImages';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                // $job->update(['status' => 1]);
                $session = (new ShopifyService)->getSession();

                $shopifyProducts = ShopifyProduct::where('vendor', 'Pandora')->select('id', 'product_id', 'title', 'sku', 'vendor')->with(['variants'])->get();

                foreach ($shopifyProducts as $shopifyProduct) {
                    try {
                        $this->info($shopifyProduct->title);
                        $imagesResp = Image::all(
                            $session,
                            ["product_id" => $shopifyProduct->product_id]
                        );
                        foreach ($imagesResp as $image) {
                            $this->info("Image id: {$image->id}");
                            Image::delete(
                                $session,
                                $image->id,
                                ["product_id" => $shopifyProduct->product_id],
                            );
                            $this->info("Image deleted");
                        }

                        ShopifyProductVariant::where("product_id", $shopifyProduct->product_id)->update(['images_requires_update' => 1]);
                    } catch (\Exception $e) {
                        report($e);
                        $this->error($e->getMessage());
                    }
                    sleep(1);
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
