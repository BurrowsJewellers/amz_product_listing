<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Shopify\Rest\Admin2025_04\Variant;
use App\Models\PriceInventoryLog;

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

        // if (!$job->isRunning()) {
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
                        $shopifyVariantApi = new Variant($session);
                        $shopifyVariantApi->id = $variant->variant_id;
                        $originalPrice = $variant->getOriginal('price'); // Attempt to get value before potential in-memory changes
                        $originalCompareAtPrice = $variant->getOriginal('compare_at_price');

                        $shopifyVariantApi->price = $variant->price;
                        // Only set compare_at_price if it's provided and different from price, or if it's explicitly being cleared
                        if (isset($variant->compare_at_price) && $variant->compare_at_price !== $variant->price) {
                            $shopifyVariantApi->compare_at_price = $variant->compare_at_price;
                        } elseif (isset($variant->compare_at_price) && $variant->compare_at_price === null) {
                            $shopifyVariantApi->compare_at_price = null; // explicitly setting to null
                        }


                        $shopifyVariantApi->save(
                            true, // Update Object
                        );

                        PriceInventoryLog::create([
                            'marketplace' => $marketplace,
                            'item_identifier' => $variant->sku ?? (string)$variant->variant_id,
                            'change_type' => 'price',
                            'from_value' => $originalPrice, // This is from DB before this script's $variant was hydrated with new values
                            'to_value' => $variant->price,
                            'status' => 'success',
                            'job_name' => $this->signature,
                            'message' => "Price updated via API for variant ID {$variant->variant_id}",
                        ]);

                        if (isset($shopifyVariantApi->compare_at_price)) {
                            PriceInventoryLog::create([
                                'marketplace' => $marketplace,
                                'item_identifier' => $variant->sku ?? (string)$variant->variant_id,
                                'change_type' => 'compare_at_price',
                                'from_value' => $originalCompareAtPrice,
                                'to_value' => $variant->compare_at_price,
                                'status' => 'success',
                                'job_name' => $this->signature,
                                'message' => "Compare_at_price updated via API for variant ID {$variant->variant_id}",
                            ]);
                        }

                        $this->info("Price updated for id {$variant->id}, sku {$variant->sku}, variant id {$variant->variant_id}");
                        $variant->update(['price_requires_update' => 0]); // Status updated after successful API call and logging

                    } catch (\Exception $e) {
                        Log::debug("Error updating price for SKU {$variant->sku} (Variant ID: {$variant->variant_id}). Error: {$e->getMessage()}");

                        PriceInventoryLog::create([
                            'marketplace' => $marketplace,
                            'item_identifier' => $variant->sku ?? (string)$variant->variant_id,
                            'change_type' => 'price',
                            'from_value' => $variant->getOriginal('price'),
                            'to_value' => $variant->price,
                            'status' => 'failed',
                            'job_name' => $this->signature,
                            'message' => "API Error: " . $e->getMessage(),
                        ]);
                        // Also log failed compare_at_price if it was attempted
                        if (isset($variant->compare_at_price) && $variant->compare_at_price !== $variant->getOriginal('compare_at_price')) {
                            PriceInventoryLog::create([
                                'marketplace' => $marketplace,
                                'item_identifier' => $variant->sku ?? (string)$variant->variant_id,
                                'change_type' => 'compare_at_price',
                                'from_value' => $variant->getOriginal('compare_at_price'),
                                'to_value' => $variant->compare_at_price,
                                'status' => 'failed',
                                'job_name' => $this->signature,
                                'message' => "API Error (attempting to update compare_at_price): " . $e->getMessage(),
                            ]);
                        }
                        $variant->update(['price_requires_update' => 2]);
                    }
                    usleep(1500000);
                }

                $count = ShopifyProductVariant::whereNotNull('variant_id')->where('price_requires_update', 1)->count();
                $this->info("Remaining {$count}");
            }

            $job->update(['status' => 0, 'message' => null]);

            Log::info("$marketplace $jobType finished!");
        } catch (\Exception $e) {
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
            report($e);
            $this->error($e->getMessage());
        }
        // } else {
        //     Log::info("$marketplace $jobType is already running.");
        // }
    }
}
