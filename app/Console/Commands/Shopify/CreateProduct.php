<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use Shopify\Clients\Rest;
use App\Models\Brand;
use App\Models\RetailEdgeProduct;
use App\Services\ShopifyService;
use Illuminate\Support\Facades\DB;

class CreateProduct extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyCreateProduct';

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
        $jobType = 'shopifyCreateProduct';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $pendingProducts = DB::select("SELECT rep.id, rep.sku
                    FROM retail_edge_products rep
                    LEFT JOIN shopify_product_variants spv ON rep.sku = spv.sku
                    WHERE spv.id IS NULL;
                ");

                $pendingProductIds = [];

                foreach ($pendingProducts as $p) {
                    $pendingProductIds[] = $p->id;
                }

                $session = (new ShopifyService)->getSession();
                $variantTypes = ['vt1' => 'Size', 'vt2' => 'Color', 'vt3' => 'Material', 'vt4' => 'Style'];

                $brands = Brand::all();

                $brandsArray = [];

                foreach ($brands as $brand) {
                    $brandsArray[$brand->brand_id]['id'] = $brand->id;
                    $brandsArray[$brand->brand_id]['name'] = $brand->name;
                }

                $countQuery = RetailEdgeProduct::whereIn('id', $pendingProductIds)->whereHas('children', function ($children) {
                    $children->where('uploaded_to_shopify', 0);
                });

                $count = $countQuery->count();

                while ($count) {
                    $product = RetailEdgeProduct::withWhereHas('children', function ($children) {
                        $children->where('uploaded_to_shopify', 0);
                    })->first();

                    if ($product) {
                        $variants = [];
                        $variantOptions = [];
                        if ($product->children->count()) {
                            $optionIndex = 1;
                            foreach ($product->children as $child) {
                                $variant = [];
                                $variant['sku'] = $child->sku;

                                $retailPrices = [$child->retail_price1, $child->retail_price2];

                                // Convert all prices to float and filter out non-positive values
                                $prices = array_filter(array_map('floatval', $retailPrices), function ($price) {
                                    return $price > 0;
                                });

                                // Set default values
                                $price = 0;
                                $compareAtPrice = 0;

                                // Find the lower price and higher compare_at_price
                                if (!empty($prices)) {
                                    $price = min($prices);
                                    $compareAtPrice = max($prices);
                                }

                                $variant['price'] = $price;
                                $variant['barcode'] = $child->barcode;
                                $variant['compare_at_price'] = ($price == $compareAtPrice) ? 0 : $compareAtPrice;
                                $variant['inventory_management'] = 'shopify';

                                $vts = array_filter(array_map('trim', array_map('strtolower', explode("-", $child->id3))));

                                foreach ($vts as $vt) {
                                    $vt = trim($vt);

                                    if (isset($variantTypes[$vt])) {
                                        $variantType = $variantTypes[$vt];
                                        $variantTypeValue = '';

                                        if ($vt == 'vt3') {
                                            $optionIndex = array_search($vt, $vts) + 1;
                                            $variant["option{$optionIndex}"] = $child->s_metal_type;
                                            $variantTypeValue = $child->s_metal_type;
                                        }

                                        if ($vt == 'vt4') {
                                            $optionIndex = array_search($vt, $vts) + 1;
                                            $variant["option{$optionIndex}"] = $child->pendant_style;
                                            $variantTypeValue = $child->pendant_style;
                                        }

                                        if (!isset($variantOptions[$variantType])) {
                                            $variantOptions[$variantType][] = $variantTypeValue;
                                        } else {
                                            if (!in_array($variantTypeValue, $variantOptions[$variantType])) {
                                                $variantOptions[$variantType][] = $variantTypeValue;
                                            }
                                        }
                                    }
                                }
                                $variants[] = $variant;
                            }
                        }

                        $options = [];

                        foreach ($variantOptions as $variantType => $variantValues) {
                            $option = [];
                            $option['name'] = ucfirst($variantType);

                            if (is_array($variantValues)) {
                                $option['values'] = array_unique($variantValues);
                            } else {
                                $option['values'] = $variantValues;
                            }

                            $options[] = $option;
                        }

                        $productData['product'] = [
                            'title' => $product->title,
                            'body_html' => $product->marketing_description,
                            'variants' => $variants,
                            'options' => $options,
                        ];

                        $data = json_encode($productData);

                        echo $data;
                        try {
                            $client = new Rest($session->getShop(), $session->getAccessToken());

                            /** @var RestResponse */
                            $response = $client->post(path: 'products', body: $data);
                            $body = $response->getDecodedBody();

                            if (isset($body['product'])) {
                                (new ShopifyService)->saveProductToDb($body['product']);
                                $this->info($body['product']['title'] . ' - saved to database');
                                Log::debug('Shopify product ' . $product->sku . ' created successfully!');

                                foreach ($product->children as $child) {
                                    $child->update(['uploaded_to_shopify' => 1]);
                                }
                            }
                        } catch (\Exception $e) {
                            report($e);
                        }
                        usleep(1500000);
                    }

                    $count = $countQuery->count();
                }

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
}
