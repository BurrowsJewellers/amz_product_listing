<?php

namespace App\Console\Commands\EWeb;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Models\RetailEdgeProduct;
use App\Models\RetailEdgeProductImage;
use App\Models\Shopify\ShopifySku;
use App\Services\RetailEdgeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GetProductsFromEWebMain extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'getProductsFromEWebMain';

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
        $marketplace = 'EWeb';
        $jobType = 'getProductsFromEWebMain';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if ($job->isRunning()) {
            Log::info("$marketplace $jobType is already running.");
            return;
        }

        Log::info("$marketplace $jobType started!");
        // $job->update(['status' => 1]);

        try {
            try {
                // Backup existing Shopify SKUs before clearing data
                $shopifySkus = RetailEdgeProduct::where('uploaded_to_shopify', 1)
                    ->pluck('sku')
                    ->toArray();

                Log::info("Backed up " . count($shopifySkus) . " Shopify SKUs");

                // Clear existing data - truncate operations must be outside transaction
                ShopifySku::truncate();
                RetailEdgeProduct::truncate();
                RetailEdgeProductImage::truncate();

                Log::info("Truncated tables successfully");

                // Store backup in ShopifySku table
                foreach ($shopifySkus as $shopifySku) {
                    ShopifySku::create(['sku' => $shopifySku]);
                }

                Log::info("Restored Shopify SKUs to ShopifySku table");
            } catch (\Exception $e) {
                // If there's an error during truncate or backup operations
                report($e);
                $job->update(['status' => 0, 'message' => "Error during data preparation: " . $e->getMessage()]);
                Log::error("$marketplace $jobType failed during data preparation: " . $e->getMessage());
                return;
            }

            try {
                // Start transaction for all database operations
                DB::beginTransaction();

                // Process products from RetailEdge
                $this->processProducts();

                // Update Shopify products with new data
                $this->updateShopifyProducts();

                // Commit all changes
                DB::commit();

                $job->update(['status' => 0, 'message' => null]);
                Log::info("$marketplace $jobType finished successfully!");
            } catch (\Exception $e) {
                // If there's an error during transaction operations
                DB::rollBack();
                report($e);
                $job->update(['status' => 0, 'message' => "Error during transaction: " . $e->getMessage()]);
                Log::error("$marketplace $jobType failed during transaction: " . $e->getMessage());
            }
        } catch (\Exception $e) {
            // Catch any other unexpected errors
            report($e);
            $job->update(['status' => 0, 'message' => "Unexpected error: " . $e->getMessage()]);
            Log::error("$marketplace $jobType failed with unexpected error: " . $e->getMessage());
        }
    }

    private function processProducts()
    {
        try {
            $activeItems = (new RetailEdgeService)->getAllActiveItems();
            $totalItems = count($activeItems);
            $processedCount = 0;
            $errorCount = 0;

            $this->info("Processing {$totalItems} items from RetailEdge");

            foreach ($activeItems as $item) {
                try {
                    $this->processItem($item);
                    $processedCount++;

                    if ($processedCount % 100 === 0) {
                        $this->info("Processed {$processedCount}/{$totalItems} items");
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    report($e);
                    Log::warning("Failed to process item: " . json_encode($item->SKU ?? 'Unknown SKU') . " Error: " . $e->getMessage());
                    continue;
                }
            }

            $this->info("Completed processing {$processedCount}/{$totalItems} items with {$errorCount} errors");
        } catch (\Exception $e) {
            Log::error("Error in processProducts: " . $e->getMessage());
            throw $e; // Re-throw to be caught by the handle method
        }
    }

    private function processItem($item)
    {
        // Validate SKU format
        if (!isset($item->SKU) || !preg_match('/^\d{3}-\d{3}-\d{5}$/', $item->SKU)) {
            return;
        }

        $skuArray = array_map('trim', explode('-', $item->SKU));
        $sku = $skuArray[1] . "-" . $skuArray[2];

        $processedItem = $this->processItemAttributes($item, $skuArray);
        $this->createProduct($processedItem, $sku);

        // Process images if they exist
        if (isset($item->Images) && isset($item->Images->ItemImage)) {
            $this->processImages($item->Images->ItemImage ?? [], $sku);
        }
    }

    private function processItemAttributes($item, $skuArray)
    {
        $item->OldKey = trim($item->OldKey);
        $item->ID3 = trim($item->ID3);

        foreach ($item->ISDs->ItemISD as $other) {
            $keyName = str_replace(['.', ' ', ',', '_', '\''], [], $other->Name);

            // Handle special case for department 022
            if ($skuArray[1] == '022') {
                if (!isset($item->{$keyName})) {
                    $item->{$keyName} = trim($other->Value);
                }
            } else {
                $item->{$keyName} = trim($other->Value);
            }
        }

        return $this->calculatePricing($item);
    }

    private function calculatePricing($item)
    {
        $price = $item->RetailPrice;
        $compareAtPrice = 0;

        if (isset($item->SpecialPrice) && $item->SpecialPrice > 0) {
            $price = $item->SpecialPrice;
            $compareAtPrice = $item->RetailPrice;
        }

        $item->price = $price;
        $item->compareAtPrice = $compareAtPrice;

        return $item;
    }

    private function createProduct($item, $sku)
    {
        RetailEdgeProduct::create([
            'sku' => $sku,
            'title' => trim($item->ShortMarketingDescription),
            'marketing_description' => $item->MarketingDescription,
            'brand_id' => trim($item->BrandID),
            'barcode' => trim($item->Barcode),
            'retail_price1' => $item->RetailPrice,
            'retail_price2' => $item->RetailPrice2,
            'price' => $item->price,
            'compare_at_price' => $item->compareAtPrice,
            'quantity' => intval($item->TotalAvailQOH),
            'id1' => trim($item->ID1),
            'id2' => trim($item->ID2),
            'id3' => trim($item->ID3),
            'id4' => trim($item->ID4),
            'old_key' => trim($item->OldKey),
            'is_valid_child' => preg_match('/^\d{3}-\d{5}$/', $item->OldKey),
            'real_design_number' => trim($item->RealDesignNum),
            'pendant_style' => $item->PendantStyle ?? null,
            'metal_colour' => $item->MetalColour ?? null,
            's_web_menu' => $item->SWebMenu ?? null,
            's_metal_type' => $item->SMetalType ?? null,
            's_stone_type' => $item->SStoneType ?? null,
            's_cat' => $item->SCat ?? null,
            's_sub_cat' => $item->SSubCat ?? null,
            'ring_size' => $item->RingSize ?? null,
            'bracelet_length' => $item->Length ?? null,
            'web_option_boolean1' => $item->WebOptionBoolean1,
            'web_option_boolean2' => $item->WebOptionBoolean2,
            'web_option_boolean3' => $item->WebOptionBoolean3,
            'web_option_boolean4' => $item->WebOptionBoolean4,
            'web_option_boolean5' => $item->WebOptionBoolean5,
            'web_option_boolean6' => $item->WebOptionBoolean6,
            'web_option_boolean7' => $item->WebOptionBoolean7,
            'web_option_boolean8' => $item->WebOptionBoolean8,
            'update_date_time' => isset($item->UpdateDateTime) ? Carbon::parse($item->UpdateDateTime) : null,
        ]);
    }

    private function processImages($images, $sku)
    {
        if (empty($images)) {
            return;
        }

        $images = is_object($images) ? [$images] : $images;

        foreach ($images as $image) {
            RetailEdgeProductImage::create([
                'sku' => $sku,
                'e_web_index' => $image->Index,
                'width' => $image->Width,
                'height' => $image->Height,
                'url' => htmlspecialchars_decode($image->URL),
            ]);
        }
    }

    private function updateShopifyProducts()
    {
        $shopifySkus = ShopifySku::pluck('sku')->toArray();

        if (!empty($shopifySkus)) {
            RetailEdgeProduct::whereIn('sku', $shopifySkus)
                ->update(['uploaded_to_shopify' => 1]);
        }

        DB::update("UPDATE retail_edge_products
            SET uploaded_to_shopify = 1
            WHERE sku IN (SELECT sku FROM shopify_product_variants)
        ");

        DB::update("UPDATE shopify_product_variants spv
            JOIN retail_edge_products rep ON spv.sku = rep.sku
            SET
                spv.price = rep.price,
                spv.compare_at_price = rep.compare_at_price,
                spv.inventory_quantity = rep.quantity,
                spv.inventory_requires_update = CASE
                    WHEN spv.inventory_quantity <> rep.quantity THEN 1
                    ELSE spv.inventory_requires_update
                END,
                spv.price_requires_update = CASE
                    WHEN spv.price <> rep.price OR spv.compare_at_price <> rep.compare_at_price THEN 1
                    ELSE spv.price_requires_update
                END
            WHERE
                spv.inventory_quantity <> rep.quantity
                OR spv.price <> rep.price 
                OR spv.compare_at_price <> rep.compare_at_price
        ");
    }
}
