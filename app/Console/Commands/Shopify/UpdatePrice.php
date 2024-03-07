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
                $this->info("Remaining {$count}");

                while ($count) {
                    $variant = ShopifyProductVariant::with('retailEdgeProduct')->whereNotNull('variant_id')->where('price_requires_update', 1)->first();

                    if ($variant) {
                        try {
                            $retailPrices = [$variant->retailEdgeProduct->retail_price1, $variant->retailEdgeProduct->retail_price2];

                            // Convert all prices to float and filter out non-positive values
                            $prices = array_filter(array_map('floatval', $retailPrices), function ($price) {
                                return $price > 0;
                            });

                            // Set default values
                            $price = 0;
                            $compareAtPrice = 0;

                            // Find the lower price and higher compare_at_price
                            if (!empty($prices)) {
                                $price = min($prices);
                                $compareAtPrice = max($prices);
                            }

                            if ($price > 0) {
                                $v = new Variant($session);
                                $v->id = $variant->variant_id;
                                $v->price = $price;
                                $v->compare_at_price = ($price == $compareAtPrice) ? 0 : $compareAtPrice;
                                $v->save(
                                    true, // Update Object
                                );

                                $this->info("Price updated for variant {$variant->variant_id}");
                            }

                            $variant->update(['price_requires_update' => 0]);
                        } catch (\Exception $e) {
                            Log::debug("There was an error while updating the price to {$variant->price} for {$variant->sku}. Error message : {$e->getMessage()}");
                            $variant->update(['price_requires_update' => 2]);
                        }
                        usleep(1500000);
                    }

                    $count = ShopifyProductVariant::whereNotNull('variant_id')->where('price_requires_update', 1)->count();
                    $this->info("Remaining {$count}");
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
