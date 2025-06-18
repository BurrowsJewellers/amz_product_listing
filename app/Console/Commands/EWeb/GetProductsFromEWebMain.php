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
    protected $signature = 'getProductsFromEWebMain {--memory-limit=512M : Memory limit for this command}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * The current job instance
     *
     * @var mixed
     */
    private $currentJob;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Set memory limit
        $memoryLimit = $this->option('memory-limit');
        if ($memoryLimit) {
            ini_set('memory_limit', $memoryLimit);
            $this->info("Memory limit set to: {$memoryLimit}");
        }
        
        // Enable garbage collection
        gc_enable();
        
        $marketplace = 'EWeb';
        $jobType = 'getProductsFromEWebMain';

        $job = SyncJobController::getJob($jobType, $marketplace);
        $this->currentJob = $job;

        // Set up signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGHUP, [$this, 'handleSignal']);
        }

        try {
            Log::info("$marketplace $jobType started!");
            
            // Mark job as running
            $job->update(['status' => 1]);

            $tempProductTable = 'retail_edge_products_temp';
            $tempImageTable = 'retail_edge_product_images_temp';
            $tempIsdTable = 'retail_edge_product_isds_temp';

            try {
            // Backup and restore ShopifySku logic (kept outside main product data transaction as per original structure)
            try {
                // Get SKUs that are actually in Shopify from shopify_product_variants table
                // This is more reliable than using uploaded_to_shopify flag which might be incorrect
                $shopifySkus = DB::table('shopify_product_variants')
                    ->whereNotNull('sku')
                    ->where('sku', '!=', '')
                    ->pluck('sku')
                    ->toArray();
                Log::info("Found " . count($shopifySkus) . " Shopify SKUs from shopify_product_variants table.");

                ShopifySku::truncate();
                Log::info("Truncated ShopifySku table successfully.");

                if (!empty($shopifySkus)) {
                    $chunks = array_chunk($shopifySkus, 1000); // Insert in chunks for better performance
                    foreach ($chunks as $chunk) {
                        // Process signals if available
                        if (function_exists('pcntl_signal_dispatch')) {
                            pcntl_signal_dispatch();
                        }
                        
                        $data = array_map(function($sku) {
                            return ['sku' => $sku, 'created_at' => now(), 'updated_at' => now()];
                        }, $chunk);
                        ShopifySku::insert($data);
                    }
                    Log::info("Restored " . count($shopifySkus) . " Shopify SKUs to ShopifySku table.");
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
        } catch (\Throwable $e) {
            // Global exception handler to ensure job status is always reset
            report($e);
            $job->update(['status' => 0, 'message' => "Unexpected error: " . $e->getMessage()]);
            Log::error("$marketplace $jobType failed with unexpected error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            throw $e; // Re-throw to maintain original behavior
        }
    }

    /**
     * Handle system signals for graceful shutdown
     */
    public function handleSignal($signo)
    {
        Log::warning("GetProductsFromEWebMain received signal {$signo}. Shutting down gracefully...");
        
        if ($this->currentJob) {
            $this->currentJob->update([
                'status' => 0, 
                'message' => "Process terminated by signal {$signo}"
            ]);
        }
        
        // Clean up temporary tables if they exist
        DB::statement("DROP TABLE IF EXISTS retail_edge_products_temp");
        DB::statement("DROP TABLE IF EXISTS retail_edge_product_images_temp");
        DB::statement("DROP TABLE IF EXISTS retail_edge_product_isds_temp");
        
        Log::info("GetProductsFromEWebMain shut down gracefully after receiving signal {$signo}");
        exit(1);
    }

    private function processProducts($tempProductTable, $tempImageTable, $tempIsdTable)
    {
        try {
            $retailEdgeService = new RetailEdgeService();
            
            // First, get total count if possible
            $activeItems = $retailEdgeService->getAllActiveItems();
            $totalItems = count($activeItems);
            $processedCount = 0;
            $errorCount = 0;
            
            $this->info("Processing {$totalItems} items from RetailEdge");
            $this->info("Memory usage at start: " . $this->formatBytes(memory_get_usage(true)));
            
            // Process in chunks to manage memory
            $chunkSize = 500; // Process 500 items at a time
            $chunks = array_chunk($activeItems, $chunkSize);
            
            // Clear the full array from memory
            unset($activeItems);
            
            foreach ($chunks as $chunkIndex => $chunk) {
                $batchProducts = [];
                $batchImages = [];
                $batchIsds = [];
                
                $this->info("Processing chunk " . ($chunkIndex + 1) . "/" . count($chunks) . " (Memory: " . $this->formatBytes(memory_get_usage(true)) . ")");
                
                foreach ($chunk as $item) {
                    // Process signals if available
                    if (function_exists('pcntl_signal_dispatch')) {
                        pcntl_signal_dispatch();
                    }
                    
                    try {
                        $result = $this->processItemForBatch($item);
                        
                        if ($result) {
                            if (isset($result['product'])) {
                                $batchProducts[] = $result['product'];
                            }
                            if (isset($result['images'])) {
                                $batchImages = array_merge($batchImages, $result['images']);
                            }
                            if (isset($result['isds'])) {
                                $batchIsds = array_merge($batchIsds, $result['isds']);
                            }
                        }
                        
                        $processedCount++;
                        
                        if ($processedCount % 100 === 0) {
                            $this->info("Processed {$processedCount}/{$totalItems} items (Memory: " . $this->formatBytes(memory_get_usage(true)) . ")");
                        }
                    } catch (\Exception $e) {
                        $errorCount++;
                        report($e);
                        Log::warning("Failed to process item: " . json_encode($item->SKU ?? 'Unknown SKU') . " Error: " . $e->getMessage());
                    }
                    
                    // Clear item from memory
                    unset($item);
                }
                
                // Batch insert for this chunk
                if (!empty($batchProducts)) {
                    DB::table($tempProductTable)->insert($batchProducts);
                    $this->info("Inserted " . count($batchProducts) . " products");
                }
                if (!empty($batchImages)) {
                    DB::table($tempImageTable)->insert($batchImages);
                    $this->info("Inserted " . count($batchImages) . " images");
                }
                if (!empty($batchIsds)) {
                    DB::table($tempIsdTable)->insert($batchIsds);
                    $this->info("Inserted " . count($batchIsds) . " ISDs");
                }
                
                // Clear batch arrays
                unset($batchProducts, $batchImages, $batchIsds, $chunk);
                
                // Force garbage collection after each chunk
                gc_collect_cycles();
                
                // Add a small delay to reduce server load
                usleep(100000); // 0.1 second
            }
            
            $this->info("Completed processing {$processedCount}/{$totalItems} items with {$errorCount} errors.");
            $this->info("Final memory usage: " . $this->formatBytes(memory_get_usage(true)));
            
        } catch (\Exception $e) {
            Log::error("Error in processProducts: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Format bytes into human readable format
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Process item for batch insertion (returns data instead of inserting directly)
     */
    private function processItemForBatch($item)
    {
        // Validate SKU format
        if (!isset($item->SKU) || !preg_match('/^\d{3}-\d{3}-\d{5}$/', $item->SKU)) {
            return null;
        }

        $skuArray = array_map('trim', explode('-', $item->SKU));
        $sku = $skuArray[1] . "-" . $skuArray[2];

        $processedItem = $this->processItemAttributes($item, $skuArray);
        $result = [
            'product' => $this->createProductData($processedItem, $sku),
            'images' => [],
            'isds' => []
        ];

        // Process images if they exist
        if (isset($item->Images) && isset($item->Images->ItemImage)) {
            $result['images'] = $this->processImagesForBatch($item->Images->ItemImage ?? [], $sku);
        }

        // Process ISDs if they exist
        if (isset($item->ISDs) && isset($item->ISDs->ItemISD)) {
            $result['isds'] = $this->processIsdsForBatch($item->ISDs->ItemISD ?? [], $sku);
        }

        return $result;
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

    /**
     * Create product data for batch insertion
     */
    private function createProductData($item, $sku)
    {
        return [
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
        ];
    }

    private function createProduct($item, $sku, $tempProductTable)
    {
        DB::table($tempProductTable)->insert($this->createProductData($item, $sku));
    }

    /**
     * Process images for batch insertion
     */
    private function processImagesForBatch($images, $sku)
    {
        if (empty($images)) {
            return [];
        }

        $images = is_object($images) ? [$images] : $images;
        $imageData = [];

        foreach ($images as $image) {
            $imageData[] = [
                'sku' => $sku,
                'e_web_index' => $image->Index,
                'width' => $image->Width,
                'height' => $image->Height,
                'url' => htmlspecialchars_decode($image->URL),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }

        return $imageData;
    }

    private function processImages($images, $sku, $tempImageTable)
    {
        if (empty($images)) {
            return;
        }

        $imageData = $this->processImagesForBatch($images, $sku);
        
        if (!empty($imageData)) {
            DB::table($tempImageTable)->insert($imageData);
        }
    }

    /**
     * Process ISDs for batch insertion
     */
    private function processIsdsForBatch($isds, $sku)
    {
        if (empty($isds)) {
            return [];
        }

        $isds = is_object($isds) ? [$isds] : $isds;
        $isdData = [];
        $isdIndex = 0;

        foreach ($isds as $isd) {
            $isdName = isset($isd->Name) ? preg_replace('/\s+/', ' ', preg_replace('/[^a-zA-Z0-9 ]/', ' ', trim($isd->Name))) : null;
            $isdValue = isset($isd->Value) ? trim($isd->Value) : null;

            if (!empty($isdName) && !empty($isdValue) && $isdValue != 'N/A') {
                $isdData[] = [
                    'sku' => $sku,
                    'isd_index' => $isdIndex,
                    'isd_name' => $isdName,
                    'isd_value' => $isdValue,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];
                $isdIndex++;
            }
        }

        return $isdData;
    }

    private function processIsds($isds, $sku, $tempIsdTable)
    {
        if (empty($isds)) {
            return;
        }

        $isdData = $this->processIsdsForBatch($isds, $sku);
        
        if (!empty($isdData)) {
            DB::table($tempIsdTable)->insert($isdData);
        }
    }

    private function updateShopifyProducts()
    {
        // First, reset all uploaded_to_shopify flags to 0
        $resetCount = RetailEdgeProduct::where('uploaded_to_shopify', 1)
            ->update(['uploaded_to_shopify' => 0]);
        Log::info("Reset {$resetCount} RetailEdgeProducts uploaded_to_shopify flag to 0.");

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

        // Only mark products as uploaded if they actually exist in shopify_product_variants
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
