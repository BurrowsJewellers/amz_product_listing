<?php

namespace App\Console\Commands\Catch;

use App\Http\Controllers\SyncJobController;
use App\Models\Catch\CatchProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Catch\MiraklShopApiClient;
use Mirakl\MMP\Shop\Request\Offer\GetOffersRequest;
use Mirakl\MMP\Shop\Domain\Offer\ShopOffer;

class ListOffersOfShop extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catchListOffersOfShop';

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
        $jobType = 'catchListOffersOfShop';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                // important line
                CatchProduct::where('published', 1)->update([
                    'published' => 0,
                    'product_csv_generated' => 0,
                    'product_csv_submitted' => 0,
                    'offer_csv_generated' => 0,
                    'offer_csv_submitted' => 0,
                ]);

                $max = 50;
                $offset = 0;

                $result = $this->getOffers($max, $offset);
                $totalCount = $result->getTotalCount();

                while($totalCount > $offset){
                    try {
                        $shopOfferCollection = $this->getOffers($max, $offset);
                        // $totalCount = $result->getTotalCount();

                        foreach ($shopOfferCollection as $shopOffer) {
                            /** @var ShopOffer $shopOffer */

                            $this->info('sku: '. $shopOffer->getSku());
                            CatchProduct::where('sku', $shopOffer->getSku())->update([
                                'published' => 1,
                                'product_csv_generated' => 1,
                                'product_csv_submitted' => 1,
                                'offer_csv_generated' => 1,
                                'offer_csv_submitted' => 1,
                            ]);
                        }

                        $offset += $max;

                        sleep(60);
                    } catch (\Exception $e) {
                        $job->update(['status' => 0, 'message' => $e->getMessage()]);
                        throw new \Exception($e);
                    }
                }

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                report($e);
            }

            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }


    /**
     * @return \Mirakl\MMP\Shop\Domain\Collection\Offer\ShopOfferCollection
     * @throws \Exception
     */
    public function getOffers($max, $offset, $shopId = null) {
        try {
            $this->info("Max: $max");
            $this->info("Offset: $offset");

            if (!$shopId) {

                $shopId = config('catch.shop_id');

                if (!$shopId) {
                    throw new \Exception('Catch shop id is not set config file.');
                }

                $api = MiraklShopApiClient::getShopApiClient();        
                
                $request = new GetOffersRequest($shopId);
                $request->setMax($max);
                $request->setOffset($offset);
                return $api->getOffers($request);
            }

        } catch (\Exception $e) {
            throw new \Exception($e);
        }
    } 

}
