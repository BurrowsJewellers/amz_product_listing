<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\Brand;
use App\Models\RetailEdgeProduct;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Shopify\Clients\Rest;
use Shopify\Rest\Admin2024_01\Product;

class UploadImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:uploadImages';

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
        $jobType = 'uploadImages';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                // $job->update(['status' => 1]);
                $session = (new ShopifyService)->getSession();
                $client = new Rest($session->getShop(), $session->getAccessToken());

                $variants = ShopifyProductVariant::with('images')->get();

                $images = [];
                foreach ($variants as $variant) {
                    if ($variant->images) {
                        foreach ($variant->images as $i) {
                            $image = [];

                            // $image['product_id'] = $variant->product_id;
                            $image['src'] = $i->url;
                            $image['variant_ids'] = [$variant->variant_id];

                            $images[] = $image;
                        }
                        // dd($variant->images);

                    }

                    // dd($images);
                    // echo (json_encode($images));
                    // exit;
                }

                $productData['product'] = [
                    'id' => $variant->product_id,
                    'images' => $images
                ];

                // $images['product_id'] = $variant->product_id;
                // dd($images);
                echo (json_encode($productData));



                exit;
                $client = new Rest($session->getShop(), $session->getAccessToken());

                /** @var RestResponse */
                $response = $client->post(path: 'products', body: $data);

                $body = $response->getDecodedBody();

                if (isset($body['product'])) {
                    (new ShopifyService)->saveProductToDb($body['product']);
                }

                Log::info("$marketplace $jobType finished!");
            } catch (\Exception $e) {
                report($e);
                dd($e);
            }
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
