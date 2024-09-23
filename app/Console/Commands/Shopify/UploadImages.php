<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2024_01\Image;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use App\Http\Controllers\SyncJobController;
use App\Models\PandoraList;
use App\Models\RetailEdgeProduct;
use App\Services\ShopifyService;
use App\Models\ShopifyProductVariant;

class UploadImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyUploadImages';

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
                $job->update(['status' => 1]);
                $session = (new ShopifyService)->getSession();

                $count = ShopifyProductVariant::where('images_requires_update', 1)->count();
                $this->info("Remaining {$count}");

                while ($count) {
                    $variant = ShopifyProductVariant::where('images_requires_update', 1)->with(['images', 'product'])->first();

                    if ($variant->product->vendor == 'Pandora') {
                        $retailEdgeProduct = RetailEdgeProduct::where('sku', $variant->sku)->first();

                        if (!$retailEdgeProduct) {
                            $variant->update(['images_requires_update' => 2]);
                            continue;
                        }

                        try {
                            $process = new Process(['python3', '/opt/bitnami/projects/pandora-scraping/scrape_v2.py', $retailEdgeProduct->real_design_number]);
                            $process->run();

                            if ($process->isSuccessful()) {
                                $pandoraProduct = PandoraList::where('design_no', $retailEdgeProduct->real_design_number)->whereNotNull('images')->first();

                                if (!$pandoraProduct) {
                                    $variant->update(['images_requires_update' => 2]);
                                    continue;
                                }

                                foreach (json_decode($pandoraProduct->images) as $i) {
                                    try {
                                        $image = new Image($session);
                                        $image->product_id = $variant->product_id;
                                        $image->src = $i;
                                        $image->variant_ids = [
                                            $variant->variant_id
                                        ];

                                        $image->save(
                                            true, // Update Object
                                        );
                                        $this->info("Image uploaded for sku {$variant->sku}, variant id  {$variant->variant_id}");
                                        $variant->update(['images_requires_update' => 0]);
                                    } catch (\Exception $e) {
                                        Log::debug("There was an error while uploading the images for {$variant->sku}. Error message : {$e->getMessage()}");
                                        $variant->update(['images_requires_update' => 2]);
                                    }
                                }
                            } else {
                                throw new ProcessFailedException($process);
                            }
                        } catch (\Exception $e) {
                            report($e);
                            $variant->update(['images_requires_update' => 2]);
                        }
                    } else {
                        if ($variant->images) {
                            foreach ($variant->images as $i) {
                                try {
                                    $image = new Image($session);
                                    $image->product_id = $variant->product_id;
                                    $image->src = $i->url;
                                    $image->variant_ids = [
                                        $variant->variant_id
                                    ];

                                    $image->save(
                                        true, // Update Object
                                    );
                                    $this->info("Image uploaded for sku {$variant->sku}, variant id  {$variant->variant_id}");
                                    $variant->update(['images_requires_update' => 0]);
                                } catch (\Exception $e) {
                                    Log::debug("There was an error while uploading the images for {$variant->sku}. Error message : {$e->getMessage()}");
                                    $variant->update(['images_requires_update' => 2]);
                                }
                            }
                        }
                    }
                    $variant->update(['images_requires_update' => 0]);
                    $count = ShopifyProductVariant::where('images_requires_update', 1)->count();
                    $this->info("Remaining {$count}");
                }

                $job->update(['status' => 0]);

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
}
