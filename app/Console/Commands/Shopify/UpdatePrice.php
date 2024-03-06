<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Shopify\Rest\Admin2024_01\Variant;

class UpdatePrice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyUpdatePrice';

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
        $jobType = 'shopifyUpdatePrice';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $session = (new ShopifyService)->getSession();

                $count = ShopifyProductVariant::whereNotNull('variant_id')->where('price_requires_update', 1)->count();

                while ($count) {
                    $product = ShopifyProductVariant::whereNotNull('variant_id')->where('price_requires_update', 1)->first();

                    if ($product) {
                        try {
                            $variant = new Variant($session);
                            $variant->id = $product->variant_id;
                            $variant->price = $product->price;
                            $variant->compare_at_price = $product->compare_at_price;
                            $variant->save(
                                true, // Update Object
                            );

                            $product->update(['price_requires_update' => 0]);
                        } catch (\Exception $e) {
                            Log::debug("There was an error while updating the price to {$product->price} for {$product->sku}. Error message : {$e->getMessage()}");
                            // report($e);
                            // $this->error($e->getMessage());
                        }
                        usleep(1500000);
                    }

                    $count = ShopifyProductVariant::whereNotNull('variant_id')->where('price_requires_update', 1)->count();
                }
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
