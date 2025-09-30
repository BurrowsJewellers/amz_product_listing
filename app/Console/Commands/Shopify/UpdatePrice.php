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
                    if (!$variant->retailEdgeProduct) {
                        $skuValue = $variant->sku ?: '[EMPTY SKU]';
                        Log::warning("Missing RetailEdgeProduct for price update on SKU: {$skuValue} (Variant ID: {$variant->id})");
                        PriceInventoryLog::create([
                            'marketplace' => $marketplace,
                            'item_identifier' => $skuValue ?? (string)$variant->variant_id,
                            'change_type' => 'price',
                            'from_value' => $variant->price,
                            'to_value' => null,
                            'status' => 'failed',
                            'job_name' => $this->signature,
                            'message' => "Missing RetailEdgeProduct. Price update skipped.",
                        ]);
                        $variant->update(['price_requires_update' => 2]);
                        $this->info("Marked variant {$skuValue} (ID: {$variant->id}) for review due to missing RetailEdgeProduct.");
                        usleep(1500000);
                        $count = ShopifyProductVariant::whereNotNull('variant_id')->where('price_requires_update', 1)->count();
                        $this->info("Remaining {$count}");
                        continue;
                    }

                    $originalPrice = $variant->price;
                    $originalCompareAtPrice = $variant->compare_at_price;
                    $newPrice = $variant->retailEdgeProduct->price;
                    $newCompareAtPrice = $variant->retailEdgeProduct->compare_at_price;
                    $compareAtPriceIsSetForApi = false;

                    try {
                        $shopifyVariantApi = new Variant($session);
                        $shopifyVariantApi->id = $variant->variant_id;
                        $shopifyVariantApi->price = $newPrice;

                        // Only set compare_at_price if it's provided and different from price, or if it's explicitly being cleared
                        if ($newCompareAtPrice > 0 && $newCompareAtPrice !== $newPrice) {
                            $shopifyVariantApi->compare_at_price = $newCompareAtPrice;
                            $compareAtPriceIsSetForApi = true;
                        } elseif ($newCompareAtPrice == 0 || is_null($newCompareAtPrice)) {
                            $shopifyVariantApi->compare_at_price = null; // explicitly setting to null
                            $compareAtPriceIsSetForApi = true;
                        }


                        $shopifyVariantApi->save(
                            true, // Update Object
                        );

                        PriceInventoryLog::create([
                            'marketplace' => $marketplace,
                            'item_identifier' => $variant->sku ?? (string)$variant->variant_id,
                            'change_type' => 'price',
                            'from_value' => $originalPrice,
                            'to_value' => $newPrice,
                            'status' => 'success',
                            'job_name' => $this->signature,
                            'message' => "Price updated via API for variant ID {$variant->variant_id}",
                        ]);

                        if ($compareAtPriceIsSetForApi) {
                            PriceInventoryLog::create([
                                'marketplace' => $marketplace,
                                'item_identifier' => $variant->sku ?? (string)$variant->variant_id,
                                'change_type' => 'compare_at_price',
                                'from_value' => $originalCompareAtPrice,
                                'to_value' => $newCompareAtPrice,
                                'status' => 'success',
                                'job_name' => $this->signature,
                                'message' => "Compare_at_price updated via API for variant ID {$variant->variant_id}",
                            ]);
                        }

                        $this->info("Price updated for id {$variant->id}, sku {$variant->sku}, variant id {$variant->variant_id}");
                        $variant->update([
                            'price' => $newPrice,
                            'compare_at_price' => $newCompareAtPrice,
                            'price_requires_update' => 0
                        ]); // Status updated after successful API call and logging

                    } catch (\Exception $e) {
                        Log::debug("Error updating price for SKU {$variant->sku} (Variant ID: {$variant->variant_id}). Error: {$e->getMessage()}");

                        PriceInventoryLog::create([
                            'marketplace' => $marketplace,
                            'item_identifier' => $variant->sku ?? (string)$variant->variant_id,
                            'change_type' => 'price',
                            'from_value' => $originalPrice,
                            'to_value' => $newPrice,
                            'status' => 'failed',
                            'job_name' => $this->signature,
                            'message' => "API Error: " . $e->getMessage(),
                        ]);
                        // Also log failed compare_at_price if it was attempted
                        if ($compareAtPriceIsSetForApi) {
                            PriceInventoryLog::create([
                                'marketplace' => $marketplace,
                                'item_identifier' => $variant->sku ?? (string)$variant->variant_id,
                                'change_type' => 'compare_at_price',
                                'from_value' => $originalCompareAtPrice,
                                'to_value' => $newCompareAtPrice,
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
