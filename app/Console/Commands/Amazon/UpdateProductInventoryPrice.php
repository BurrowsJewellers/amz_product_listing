<?php

namespace App\Console\Commands\Amazon;

use App\Http\Controllers\SyncJobController;
use App\Models\Product;
use App\Services\Amazon\AmazonSpApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateProductInventoryPrice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amazonUpdateInventoryPrice';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Amazon product inventory and prices using JSON-based SP-API';

    /**
     * The batch size for processing products
     */
    private const BATCH_SIZE = 50;

    /**
     * @var AmazonSpApiService
     */
    protected $amazonService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(AmazonSpApiService $amazonService)
    {
        parent::__construct();
        $this->amazonService = $amazonService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Amazon';
        $jobType = 'amazonUpdateInventoryPrice';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if ($job->isRunning()) {
            Log::info("$marketplace $jobType is already running.");

            return;
        }

        Log::info("$marketplace $jobType started!");
        $job->update(['status' => 1]);

        try {
            $count = $this->getProductsToUpdateCount();
            $this->info("Found $count products to update");

            while ($count > 0) {
                $products = $this->getProductsToUpdate(self::BATCH_SIZE);

                if ($products->count()) {
                    $this->processProducts($products);
                }

                // Check if there are more products to process
                $count = $this->getProductsToUpdateCount();

                // Add a small delay to avoid rate limiting
                if ($count > 0) {
                    sleep(2);
                }
            }

            $job->update(['status' => 0, 'message' => null]);
            Log::info("$marketplace $jobType finished!");
        } catch (\Exception $e) {
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
            Log::error('Error : '.$e->getFile().' : '.$e->getMessage().' Line : '.$e->getLine());
        }
    }

    /**
     * Get count of products that need inventory or price updates
     *
     * @return int
     */
    private function getProductsToUpdateCount()
    {
        return Product::where('exists_on_amazon', 1)
            ->where('submitted', 1)
            ->where(function ($query) {
                $query->where('inventory_feed_status', 0)
                    ->orWhere('price_feed_status', 0);
            })
            ->count();
    }

    /**
     * Get products that need inventory or price updates
     *
     * @param  int  $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getProductsToUpdate($limit)
    {
        return Product::where('exists_on_amazon', 1)
            ->where('submitted', 1)
            ->where(function ($query) {
                $query->where('inventory_feed_status', 0)
                    ->orWhere('price_feed_status', 0);
            })
            ->limit($limit)
            ->get();
    }

    /**
     * Process products using the Amazon SP-API Service
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $products
     * @return void
     */
    private function processProducts($products)
    {
        foreach ($products as $product) {
            try {
                $this->info('Updating SKU: '.$product->sku);
                Log::info('Updating SKU: '.$product->sku);

                $success = $this->amazonService->updateProduct($product);

                if ($success) {
                    $this->info('Successfully updated SKU: '.$product->sku);
                } else {
                    $this->error('Failed to update SKU: '.$product->sku);
                }
            } catch (\Exception $e) {
                Log::error("Failed to update product {$product->sku}: {$e->getMessage()}");
                $this->error("Failed to update product {$product->sku}: {$e->getMessage()}");
            }
        }
    }
}
