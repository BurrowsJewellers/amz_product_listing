<?php

namespace App\Console\Commands\EWeb;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Models\RetailEdgeProduct;
use App\Models\RetailEdgeProductImage;
use App\Models\RetailEdgeProductIsd;
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

        Log::info("$marketplace $jobType started!");

        $tempProductTable = 'retail_edge_products_temp';
        $tempImageTable = 'retail_edge_product_images_temp';
        $tempIsdTable = 'retail_edge_product_isds_temp';

        try {
            // Backup and restore ShopifySku logic (kept outside main product data transaction as per original structure)
            try {
                // Note: This references RetailEdgeProduct before it's cleared and repopulated.
                // This assumes that the state of 'uploaded_to_shopify' in RetailEdgeProduct before this job run
                // is the source of truth for what *was* on Shopify.
                $shopifySkus = RetailEdgeProduct::where('uploaded_to_shopify', 1)
                    ->pluck('sku')
                    ->toArray();
                Log::info("Backed up " . count($shopifySkus) . " Shopify SKUs for ShopifySku table restoration.");

                ShopifySku::truncate();
                Log::info("Truncated ShopifySku table successfully.");

                if (!empty($shopifySkus)) {
                    foreach ($shopifySkus as $shopifySku) {
                        ShopifySku::create(['sku' => $shopifySku]);
                    }
                    Log::info("Restored Shopify SKUs to ShopifySku table.");
                } else {
                    Log::info("No Shopify SKUs to restore to ShopifySku table.");
                }
            } catch (\Throwable $e) {
                report($e);
                $job->update(['status' => 0, 'message' => "Error during ShopifySku preparation: " . $e->getMessage()]);
                Log::error("$marketplace $jobType failed during ShopifySku preparation: " . $e->getMessage());
                return; // Exit if ShopifySku preparation fails
            }

            // Drop temporary tables if they exist from a previous failed run
            DB::statement("DROP TABLE IF EXISTS {$tempProductTable}");
            DB::statement("DROP TABLE IF EXISTS {$tempImageTable}");
            DB::statement("DROP TABLE IF EXISTS {$tempIsdTable}");

            // Create temporary tables by copying the structure (including primary keys) of the main tables
            DB::statement("CREATE TEMPORARY TABLE {$tempProductTable} LIKE retail_edge_products");
            DB::statement("CREATE TEMPORARY TABLE {$tempImageTable} LIKE retail_edge_product_images");
            DB::statement("CREATE TEMPORARY TABLE {$tempIsdTable} LIKE retail_edge_product_isds");

            Log::info("Temporary tables {$tempProductTable}, {$tempImageTable} and {$tempIsdTable} created with structure like main tables.");

            // Process products from RetailEdge into temporary tables
            $this->processProducts($tempProductTable, $tempImageTable, $tempIsdTable);

            // Start transaction for main database operations
            DB::beginTransaction();
            Log::info("Main transaction started for updating main product tables.");

            // Clear main tables (RetailEdgeProduct, RetailEdgeProductImage, RetailEdgeProductIsd) using DELETE to be transaction-safe
            RetailEdgeProduct::query()->delete();
            RetailEdgeProductImage::query()->delete();
            RetailEdgeProductIsd::query()->delete();
            Log::info("Deleted data from main tables: retail_edge_products, retail_edge_product_images, and retail_edge_product_isds.");

            // Copy data from temporary tables to main tables
            DB::statement("INSERT INTO retail_edge_products SELECT * FROM {$tempProductTable}");
            DB::statement("INSERT INTO retail_edge_product_images SELECT * FROM {$tempImageTable}");
            DB::statement("INSERT INTO retail_edge_product_isds SELECT * FROM {$tempIsdTable}");
            Log::info("Copied data from temporary tables to main tables.");

            // Update Shopify products with new data from main tables
            // This method uses the now-populated main tables.
            $this->updateShopifyProducts();

            DB::commit();
            Log::info("Main transaction committed successfully.");

            $job->update(['status' => 0, 'message' => null]); // Reset job status to success
            Log::info("$marketplace $jobType finished successfully!");
        } catch (\Throwable $e) {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                Log::info("Main transaction rolled back due to error.");
            }
            report($e);
            // Ensure job status is updated to reflect failure
            $job->update(['status' => 0, 'message' => "Error during main processing: " . $e->getMessage()]);
            Log::error("$marketplace $jobType failed: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
        } finally {
            // Drop temporary tables regardless of success or failure
            DB::statement("DROP TABLE IF EXISTS {$tempProductTable}");
            DB::statement("DROP TABLE IF EXISTS {$tempImageTable}");
            DB::statement("DROP TABLE IF EXISTS {$tempIsdTable}");
            Log::info("Temporary tables {$tempProductTable}, {$tempImageTable} and {$tempIsdTable} dropped.");
        }
    }

    private function processProducts($tempProductTable, $tempImageTable, $tempIsdTable)
    {
        try {
            $activeItems = (new RetailEdgeService)->getAllActiveItems();
            $totalItems = count($activeItems);
            $processedCount = 0;
            $errorCount = 0;

            $this->info("Processing {$totalItems} items from RetailEdge");

            foreach ($activeItems as $item) {
                try {
                    $this->processItem($item, $tempProductTable, $tempImageTable, $tempIsdTable);
                    $processedCount++;

                    if ($processedCount % 100 === 0) {
                        $this->info("Processed {$processedCount}/{$totalItems} items into temporary tables");
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    report($e);
                    Log::warning("Failed to process item into temporary table: " . json_encode($item->SKU ?? 'Unknown SKU') . " Error: " . $e->getMessage());
                    // Continue with the next item, error for this one is logged
                }
            }

            $this->info("Completed processing {$processedCount}/{$totalItems} items into temporary tables with {$errorCount} errors.");
        } catch (\Exception $e) {
            Log::error("Error in processProducts (populating temporary tables): " . $e->getMessage());
            throw $e; // Re-throw to be caught by the handle method's main try-catch
        }
    }

    private function processItem($item, $tempProductTable, $tempImageTable, $tempIsdTable)
    {
        // Validate SKU format
        if (!isset($item->SKU) || !preg_match('/^\d{3}-\d{3}-\d{5}$/', $item->SKU)) {
            return;
        }

        $skuArray = array_map('trim', explode('-', $item->SKU));
        $sku = $skuArray[1] . "-" . $skuArray[2];

        $processedItem = $this->processItemAttributes($item, $skuArray);
        $this->createProduct($processedItem, $sku, $tempProductTable);

        // Process images if they exist
        if (isset($item->Images) && isset($item->Images->ItemImage)) {
            $this->processImages($item->Images->ItemImage ?? [], $sku, $tempImageTable);
        }

        // Process ISDs if they exist
        if (isset($item->ISDs) && isset($item->ISDs->ItemISD)) {
            $this->processIsds($item->ISDs->ItemISD ?? [], $sku, $tempIsdTable);
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

    private function createProduct($item, $sku, $tempProductTable)
    {
        DB::table($tempProductTable)->insert([
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
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function processImages($images, $sku, $tempImageTable)
    {
        if (empty($images)) {
            return;
        }

        $images = is_object($images) ? [$images] : $images;

        foreach ($images as $image) {
            DB::table($tempImageTable)->insert([
                'sku' => $sku,
                'e_web_index' => $image->Index,
                'width' => $image->Width,
                'height' => $image->Height,
                'url' => htmlspecialchars_decode($image->URL),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    private function processIsds($isds, $sku, $tempIsdTable)
    {
        if (empty($isds)) {
            return;
        }

        $isds = is_object($isds) ? [$isds] : $isds;
        $isdIndex = 0;

        foreach ($isds as $isd) {
            // $isdName = isset($isd->Name) ? trim($isd->Name) : null;
            $isdName = isset($isd->Name) ? preg_replace('/\s+/', ' ', preg_replace('/[^a-zA-Z0-9 ]/', ' ', trim($isd->Name))) : null;

            // $isdValue = isset($isd->Value) ? preg_replace('/\s+/', ' ', preg_replace('/[^a-zA-Z0-9 ]/', ' ', trim($isd->Value))) : null;
            $isdValue = isset($isd->Value) ? trim($isd->Value) : null;

            if (!empty($isdName) && !empty($isdValue) && $isdValue != 'N/A') {
                DB::table($tempIsdTable)->insert([
                    'sku' => $sku,
                    'isd_index' => $isdIndex,
                    'isd_name' => $isdName,
                    'isd_value' => $isdValue,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $isdIndex++;
            }
        }
    }

    private function updateShopifyProducts()
    {
        $shopifySkus = ShopifySku::pluck('sku')->toArray();
        $updatedCount1 = 0;
        $updatedCount2 = 0;
        $updatedCount3 = 0;

        if (!empty($shopifySkus)) {
            $updatedCount1 = RetailEdgeProduct::whereIn('sku', $shopifySkus)
                ->update(['uploaded_to_shopify' => 1]);
            Log::info("Marked {$updatedCount1} RetailEdgeProducts as 'uploaded_to_shopify' based on ShopifySku backup.");
        } else {
            Log::info("No SKUs found in ShopifySku backup to update uploaded_to_shopify flag.");
        }

        $sql2 = "UPDATE retail_edge_products
            SET uploaded_to_shopify = 1
            WHERE sku IN (SELECT sku FROM shopify_product_variants)";
        $updatedCount2 = DB::update($sql2);
        Log::info("Marked {$updatedCount2} RetailEdgeProducts as 'uploaded_to_shopify' based on existing shopify_product_variants.");

        $sql3 = "UPDATE shopify_product_variants spv
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
                END,
                spv.updated_at = CURRENT_TIMESTAMP
            WHERE
                spv.inventory_quantity <> rep.quantity
                OR spv.price <> rep.price
                OR spv.compare_at_price <> rep.compare_at_price
        ";
        $updatedCount3 = DB::update($sql3);
        Log::info("Updated {$updatedCount3} shopify_product_variants with new price/quantity from RetailEdgeProducts.");
    }
}
