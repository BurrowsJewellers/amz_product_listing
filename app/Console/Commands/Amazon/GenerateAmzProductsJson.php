<?php

namespace App\Console\Commands\Amazon;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Models\Product;
use App\Services\Amazon\CatalogService;

class GenerateAmzProductsJson extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generateAmzProductsJson';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Amazon product listings using JSON-based SP-API';

    /**
     * @var CatalogService
     */
    protected $catalogService;

    /**
     * Create a new command instance.
     *
     * @param CatalogService $catalogService
     * @return void
     */
    public function __construct(CatalogService $catalogService)
    {
        parent::__construct();
        $this->catalogService = $catalogService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Amazon';
        $jobType = 'generateAmzProductsJson';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if ($job->isRunning()) {
            Log::info("$marketplace $jobType is already running.");
            return;
        }

        Log::info("$marketplace $jobType started!");
        $job->update(['status' => 1]);

        try {
            $count = $this->getUnprocessedProductsCount();
            $this->info("Found $count products to process");

            while ($count > 0) {
                $limit = 50; // Process in smaller batches to avoid rate limiting
                $products = $this->getUnprocessedProducts($limit);

                if ($products->count()) {
                    $this->processProducts($products);
                }

                // Check if there are more products to process
                $count = $this->getUnprocessedProductsCount();

                // Add a small delay to avoid rate limiting
                if ($count > 0) {
                    sleep(2);
                }
            }

            $job->update(['status' => 0, 'message' => null]);
            Log::info("$marketplace $jobType finished!");
        } catch (\Exception $e) {
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
            Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() . ' Line : ' . $e->getLine());
        }
    }

    /**
     * Get count of unprocessed products
     * 
     * @return int
     */
    private function getUnprocessedProductsCount()
    {
        return Product::where(['json_generated' => 0, 'exists_on_amazon' => 0])
            ->whereNotNull('brand_id')
            ->where(function ($query) {
                $query->whereNotNull('ean');
                $query->orWhereNotNull('upc');
                $query->orWhereNotNull('asin');
            })
            ->count();
    }

    /**
     * Get unprocessed products
     * 
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getUnprocessedProducts($limit)
    {
        return Product::with([
            'fields' => function ($query) {
                $query->with(['category', 'productType', 'categoryField', 'productTypeField']);
            },
            'brand',
            'category',
            'productType',
            'eWebCode',
            'images'
        ])
            ->where(['json_generated' => 0, 'exists_on_amazon' => 0])
            ->whereNotNull('brand_id')
            ->where(function ($query) {
                $query->whereNotNull('ean');
                $query->orWhereNotNull('upc');
                $query->orWhereNotNull('asin');
            })
            ->limit($limit)
            ->get();
    }

    /**
     * Process products using the Catalog Service
     * 
     * @param \Illuminate\Database\Eloquent\Collection $products
     * @return void
     */
    private function processProducts($products)
    {
        foreach ($products as $product) {
            try {
                $this->info('Processing SKU: ' . $product->sku);
                Log::info('Processing SKU: ' . $product->sku);

                // Mark the product as processed regardless of the outcome
                // This prevents the same product from being processed repeatedly if there's an issue
                $product->update(['json_generated' => 1]);

                // Use the catalog service to search for the product in Amazon's catalog
                // and either submit a new listing or an offer for an existing product
                $this->catalogService->processProduct($product);
            } catch (\Exception $e) {
                Log::error("Failed to process product {$product->sku}: {$e->getMessage()}");
                $this->error("Failed to process product {$product->sku}: {$e->getMessage()}");
            }
        }
    }
}
