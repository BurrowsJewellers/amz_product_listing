<?php

namespace App\Console\Commands\Catch;

use App\Http\Controllers\Catch\MiraklShopApiClient;
use App\Http\Controllers\SyncJobController;
use App\Models\Catch\CatchProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Mirakl\MMP\Common\Domain\Product\Offer\ProductReference;
use Mirakl\MMP\Shop\Request\Product\GetProductsRequest;

class CheckIfExists extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catchCheckIfExists';

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
        $marketplace = 'Catch';
        $jobType = 'catchCheckIfExists';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                // important line
                CatchProduct::where('published', 0)->update(['exists_on_catch' => null]);

                $count = CatchProduct::whereNull('exists_on_catch')->count();

                $productReferenceTypes = ['EAN', 'UPC'];

                while($count){
                    $limit = 10;

                    $api = MiraklShopApiClient::getShopApiClient();        

                    $products = CatchProduct::select('id', 'product_reference_type', 'product_reference_value')->whereNull('exists_on_catch')->limit($limit)->get();

                    foreach($products as $product){
                        try {
                            $this->info($product->id);

                            $existsOnCatch = 0;
                            $referenceType = $product->product_reference_type;

                            foreach ($productReferenceTypes as $productReferenceType) {
                                $request = new GetProductsRequest([new ProductReference($productReferenceType, $product->product_reference_value)]);
                                $result = $api->getProducts($request);
    
                                if (count($result->getItems()) > 0) {
                                    $existsOnCatch = 1;
                                    $referenceType = $productReferenceType;
                                    $this->info("Found with $productReferenceType");
                                }
                            }

                            $product->update(['product_reference_type' => $referenceType, 'exists_on_catch' => $existsOnCatch]);
                        } catch (\Exception $e) {
                            report($e);
                        }
                    }

                    sleep(10);

                    $count = CatchProduct::whereNull('exists_on_catch')->count();
                }

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e){
                report($e);
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
            }

            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }

}
