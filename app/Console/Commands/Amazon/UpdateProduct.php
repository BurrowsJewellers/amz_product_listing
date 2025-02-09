<?php

namespace App\Console\Commands\Amazon;

use App\Http\Controllers\SyncJobController;
use App\Models\Product;
use App\Services\Amazon\AmazonSpApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exception;

class UpdateProduct extends Command
{
    protected $signature = 'amazonUpdateProduct';
    protected $description = 'Update product inventory and prices on Amazon Marketplace';

    private const BATCH_SIZE = 1000;
    private const MARKETPLACE = 'Amazon';
    private const JOB_TYPE = 'amazonUpdateProduct';

    protected AmazonSpApiService $amazonService;

    public function __construct(AmazonSpApiService $amazonService)
    {
        parent::__construct();
        $this->amazonService = $amazonService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $job = $this->initializeJob();

            if ($job->isRunning()) {
                Log::info(self::MARKETPLACE . " " . self::JOB_TYPE . " is already running.");
                return Command::FAILURE;
            }

            Log::info(self::MARKETPLACE . " " . self::JOB_TYPE . " started!");
            $job->update(['status' => 1]);

            $this->processProducts($job);

            $job->update(['status' => 0, 'message' => null]);
            Log::info(self::MARKETPLACE . " " . self::JOB_TYPE . " finished!");

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->handleFatalError($e, $job ?? null);
            return Command::FAILURE;
        }
    }

    /**
     * Initialize the sync job
     */
    private function initializeJob()
    {
        return SyncJobController::getJob(self::JOB_TYPE, self::MARKETPLACE);
    }

    /**
     * Get products that need updating
     */
    private function getProductsQuery()
    {
        return Product::where(['exists_on_amazon' => 1])
            ->where(function ($query) {
                $query->where('inventory_feed_status', 0)
                    ->orWhere('price_feed_status', 0);
            });
    }

    /**
     * Process all products in batches
     */
    private function processProducts($job): void
    {
        $query = $this->getProductsQuery();
        $totalCount = $query->count();
        $this->info("Total products to process: {$totalCount}");

        while ($totalCount > 0) {
            $products = $query->limit(self::BATCH_SIZE)->get();

            if ($products->isNotEmpty()) {
                foreach ($products as $product) {
                    try {
                        $success = $this->amazonService->updateProduct($product);

                        if (!$success) {
                            $job->update([
                                'status' => 0,
                                'message' => "Failed to update product {$product->sku}"
                            ]);
                        }
                    } catch (Exception $e) {
                        $this->handleProductError($product, $e, $job);
                    }
                }
            }

            $totalCount = $query->count();
        }
    }

    /**
     * Handle individual product errors
     */
    private function handleProductError(Product $product, Exception $e, $job): void
    {
        $errorMessage = "Error updating product {$product->sku}: {$e->getMessage()}";
        Log::error($errorMessage);
        report($e);

        $job->update([
            'status' => 0,
            'message' => $errorMessage
        ]);

        $this->error($errorMessage);
    }

    /**
     * Handle fatal errors that stop the entire process
     */
    private function handleFatalError(Exception $e, $job = null): void
    {
        $errorMessage = "Fatal error in " . self::JOB_TYPE . ": " . $e->getMessage();
        Log::error($errorMessage);
        report($e);

        if ($job) {
            $job->update([
                'status' => 0,
                'message' => $errorMessage
            ]);
        }

        $this->error($errorMessage);
    }
}
