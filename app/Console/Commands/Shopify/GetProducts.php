<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Services\ShopifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Rest;

class GetProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'getProducts';

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
        $jobType = 'getProducts';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            Log::info("$marketplace $jobType started!");
            // $job->update(['status' => 1]);

            try {
                $nextPage = true;
                $getNextPageQuery = [];

                while ($nextPage) {
                    $session = (new ShopifyService)->getSession();
                    $client = new Rest($session->getShop(), $session->getAccessToken());

                    /** @var RestResponse */
                    $response = $client->get(path: 'products', query: $getNextPageQuery);

                    $serializedPageInfo = serialize($response->getPageInfo());

                    /** @var \Shopify\Clients\PageInfo */
                    $pageInfo = unserialize($serializedPageInfo);

                    if ($pageInfo->hasNextPage()) {
                        $getNextPageQuery = $pageInfo->getNextPageQuery();
                    } else {
                        $nextPage = false;
                    }

                    $body = $response->getDecodedBody();

                    if (!empty($body) && isset($body['products']) && count($body['products']) > 0) {
                        foreach ($body['products'] as $productData) {
                            $this->saveProductToDb($productData);
                        }
                    }
                    sleep(1);
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


    public function saveProductToDb($productData)
    {
    }
}
