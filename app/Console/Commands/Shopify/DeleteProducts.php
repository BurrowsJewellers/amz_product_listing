<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2024_01\Image;
use App\Services\ShopifyService;
use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use Shopify\Rest\Admin2024_01\Product;

class DeleteProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyDeleteProducts';

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
        $jobType = 'shopifyDeleteProducts';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $this->deleteProducts();

                $job->update(['status' => 0, 'message' => null]);

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

    public function deleteProducts()
    {
        try {
            $session = (new ShopifyService)->getSession();

            $products = ShopifyProduct::where('id', '>', 12291)->pluck('product_id')->toArray();

            foreach ($products as $product) {
                try {
                    $this->info("Deleting product: {$product->product_id}");

                    Product::delete(
                        $session,
                        $product->product_id,
                    );
                } catch (\Exception $e) {
                    report($e);
                    $this->error($e->getMessage());
                }
                sleep(1);
            }
        } catch (\Exception $e) {
            report($e);
            $this->error($e->getMessage());
        }
    }
}
