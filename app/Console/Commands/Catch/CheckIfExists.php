<?php

namespace App\Console\Commands\Catch;

use App\Http\Controllers\Catch\MiraklShopApiClient;
use App\Http\Controllers\SyncJobController;
use App\Models\Catch\CatchProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

        if (!$job->isRunning()) {
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                // important line
                CatchProduct::query()->update(['exists_on_catch' => null]);

                $count = CatchProduct::whereNull('exists_on_catch')->count();

                $productReferenceTypes = ['EAN', 'UPC', 'UID'];

                $retry = 0;

                while ($count && $retry <= 5) {
                    $limit = 20;

                    $productReferenceRequest = $productIds = [];

                    $api = MiraklShopApiClient::getShopApiClient();

                    $products = CatchProduct::select('id', 'product_reference_type', 'product_reference_value')->whereNull('exists_on_catch')->limit($limit)->get();

                    foreach ($products as $product) {
                        $this->info($product->id);
                        $productIds[] = $product->id;

                        foreach ($productReferenceTypes as $productReferenceType) {
                            $productReferenceRequest[] = new ProductReference($productReferenceType, $product->product_reference_value);
                        }
                    }

                    try {
                        $request = new GetProductsRequest($productReferenceRequest);

                        $result = $api->getProducts($request);

                        // DB::beginTransaction();

                        CatchProduct::whereIn('id', $productIds)->update(['exists_on_catch' => 0]);

                        if (count($result->getItems()) > 0) {
                            foreach ($result->getItems() as $p) {
                                $this->info($p->getId() . ' is listed with ' . $p->getIdType());
                                CatchProduct::where('product_reference_value', $p->getId())->update(['product_reference_type' => $p->getIdType(), 'exists_on_catch' => 1]);
                            }

                            // DB::commit();
                        }
                    } catch (\Exception $e) {
                        report($e);
                        // DB::rollBack();
                        $retry++;
                        $this->info('Retry : ' . $retry);
                    }

                    sleep(10);

                    $count = CatchProduct::whereNull('exists_on_catch')->count();
                }

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e) {
                report($e);
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
            }

            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
