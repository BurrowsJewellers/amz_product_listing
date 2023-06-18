<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\EWebController;
use App\Models\Product;
use App\Models\ProductImage;

class GetImagesFromEWeb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'getImagesFromEWeb {sku?}';

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
        $marketplace = 'EWeb';
        $jobType = 'getImagesFromEWeb';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $products = Product::select('id', 'sku');

                if ($this->argument('sku')) {
                    $products = $products->where('sku', $this->argument('sku'));
                }

                $products = $products->get();

                $this->info($products->count());

                $eWeb = new EWebController;

                foreach ($products as $product) {
                    try {
                        $this->info("SKU : $product->sku");
                        $params = ["SKU" => $product->sku];
                        $resp = $eWeb->call('GetItemImagesBySKU', $params);
                        $itemImage = $resp->GetItemImagesBySKUResult->ItemImage;

                        $productImages = [];

                        if (is_object($itemImage)) {
                            $productImages[] = [
                                'e_web_index' => $itemImage->Index,
                                'width' => $itemImage->Width,
                                'height' => $itemImage->Height,
                                'url' => htmlspecialchars_decode($itemImage->URL),
                            ];
                        } elseif (is_array($itemImage)) {
                            foreach ($itemImage as $image) {
                                $productImages[] = [
                                    'e_web_index' => $image->Index,
                                    'width' => $image->Width,
                                    'height' => $image->Height,
                                    'url' => htmlspecialchars_decode($image->URL),
                                ];
                            }
                        }

                        if (!empty($productImages)) {
                            foreach ($productImages as $productImage) {
                                ProductImage::updateOrCreate(
                                    [
                                        'product_id' => $product->id,
                                        'e_web_index' => $productImage['e_web_index']
                                    ],
                                    [
                                        'width' => $productImage['width'],
                                        'height' => $productImage['height'],
                                        'url' => $productImage['url'],
                                    ]
                                );
                            }
                        }

                    } catch (\Exception $e) {
                        Log::error("SKU : $product->sku Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
                    }
                }

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e){
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
            }

            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
