<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use Shopify\Clients\Rest;
use App\Models\Brand;
use App\Models\RetailEdgeProduct;
use App\Models\RetailEdgeProductIsd;
use App\Models\ShopifyMetafield;
use App\Services\ShopifyService;
use App\Services\MetafieldAssignmentService;
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
                $product_errors_occurred = false;

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
                })->where('quantity', '>', 0);

                $count = $countQuery->count();

                while ($count) {
                    $this->info('Count: ' . $count);
                    $product = RetailEdgeProduct::withWhereHas('children', function ($children) {
                        $children->where('uploaded_to_shopify', 0);
                    })->with(['brand'])->where('quantity', '>', 0)->first();

                    if ($product) {
                        $this->info('======================================');
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

                                        if ($vt == 'vt1') {
                                            if ($child->s_cat == 'Rings') {
                                                $optionIndex = array_search($vt, $vts) + 1;
                                                $variant["option{$optionIndex}"] = $child->ring_size;
                                                $variantTypeValue = $child->ring_size;
                                            }

                                            if ($child->s_cat == 'Bracelets') {
                                                $optionIndex = array_search($vt, $vts) + 1;
                                                $variant["option{$optionIndex}"] = $child->bracelet_length;
                                                $variantTypeValue = $child->bracelet_length;
                                            }
                                        }

                                        if ($vt == 'vt2') {
                                            $optionIndex = array_search($vt, $vts) + 1;
                                            $variant["option{$optionIndex}"] = $child->metal_colour;
                                            $variantTypeValue = $child->metal_colour;
                                        }

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

                        $mktDescription = $product->marketing_description;

                        if ($product->brand?->name == 'Pandora') {
                            // $mktDescription .= "Brand: " . $product->brand?->name;
                            $mktDescription .= " - Design number: " . $product->real_design_number;
                        }

                        $productData['product'] = [
                            'title' => $product->title,
                            'body_html' => $mktDescription,
                            'variants' => $variants,
                            'options' => $options,
                            'product_type' => $product->s_cat,
                        ];

                        $productData['product']['vendor'] = $product->brand?->name;

                        $productTags = $this->calculateTags($product);

                        if ($product->brand?->name == 'Pandora') {
                            $productTags[] = 'Pandora';
                            $productData['product']['template_suffix'] = 'no-buy';
                        }

                        $productData['product']['tags'] = implode(",", $productTags);

                        // Dynamic metafield assignment using MetafieldAssignmentService
                        $metafieldService = new MetafieldAssignmentService();
                        $assignment = $metafieldService->determineMetafieldAssignment($product);

                        $this->line("Metafield assignment type: {$assignment['type']} for Product: {$product->sku}");

                        $metafields = [];

                        // Handle product-level metafields for REST API
                        if (!empty($assignment['product_metafields'])) {
                            $this->line("Adding " . count($assignment['product_metafields']) . " product-level metafields");
                            foreach ($assignment['product_metafields'] as $metafield) {
                                $shopifyMetafieldDef = ShopifyMetafield::where('name', $metafield['isd_name'])
                                    ->where('owner_type', 'PRODUCT')
                                    ->first();

                                if ($shopifyMetafieldDef && !empty($metafield['value'])) {
                                    $metafields[] = [
                                        'key' => $shopifyMetafieldDef->key,
                                        'value' => $metafield['value'],
                                        'type' => $shopifyMetafieldDef->type,
                                        'namespace' => $shopifyMetafieldDef->namespace
                                    ];
                                    $this->line("Added product metafield: {$metafield['isd_name']} = {$metafield['value']}");
                                } else {
                                    $this->warn("Skipping product metafield '{$metafield['isd_name']}': Definition not found or empty value.");
                                }
                            }
                        }

                        // Note: Variant-level metafields will be handled after product creation via GraphQL
                        // since REST API doesn't support variant metafields during product creation

                        if (!empty($metafields)) {
                            $productData['product']['metafields'] = $metafields;
                        }

                        $data = json_encode($productData);

                        $this->info($data);

                        try {
                            $client = new Rest($session->getShop(), $session->getAccessToken());

                            /** @var RestResponse */
                            $response = $client->post(path: 'products', body: $data);
                            $body = $response->getDecodedBody();

                            if (isset($body['product'])) {
                                (new ShopifyService)->saveProductToDb($body['product']);
                                $this->info($body['product']['title'] . ' - saved to database');
                                Log::info('Shopify product ' . $product->sku . ' created successfully!');

                                foreach ($product->children as $child) {
                                    $child->update(['uploaded_to_shopify' => 1]);
                                }
                            } else {
                                foreach ($product->children as $child) {
                                    $child->update(['uploaded_to_shopify' => 2]);
                                }

                                $message = 'Shopify error while creating product. Sku :' . $product->sku . ', title: '  . $product->title;
                                Log::debug($message);
                                Log::debug($data);

                                $logMessage = '';

                                if (isset($body['errors']['base'][0])) {
                                    $logMessage = $message . " - " . $body['errors']['base'][0];
                                }

                                Log::debug($body);
                                Log::error($logMessage);
                                $this->info($logMessage);
                            }
                        } catch (\Exception $e) {
                            $product_errors_occurred = true;
                            Log::error("Shopify API Exception for SKU " . ($product ? $product->sku : 'N/A') . ": " . $e->getMessage());
                            // Mark children as failed
                            if ($product && $product->children) {
                                foreach ($product->children as $child_item) {
                                    try {
                                        $child_item->update(['uploaded_to_shopify' => 2]);
                                    } catch (\Exception $childUpdateException) {
                                        Log::error("Failed to update child status for SKU " . ($child_item && isset($child_item->sku) ? $child_item->sku : 'N/A') . " after API error: " . $childUpdateException->getMessage());
                                    }
                                }
                            }
                            report($e); // Report the original exception
                            // Do NOT rethrow, allow loop to continue
                        }
                        usleep(1500000);
                    }

                    $count = $countQuery->count();
                }

                if ($product_errors_occurred) {
                    $job->update(['status' => 0, 'message' => 'Completed with one or more product creation errors.']);
                } else {
                    $job->update(['status' => 0, 'message' => null]);
                }

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

    private function calculateTags(RetailEdgeProduct $product): array
    {
        $tags = [];

        try {
            $types = [
                's_web_menu' => 'S.WebMenu',
                's_metal_type' => 'S.Metal Type',
                's_stone_type' => 'S.Stone Type',
                's_cat' => 'S.Cat',
                's_sub_cat' => 'S.Sub Cat',
            ];

            foreach ($types as $type => $value) {
                $propValue = $product->{$type} ?? '';
                if ($propValue !== '' && $propValue !== 'N/A') {
                    foreach (explode(",", $propValue) as $tempTag) {
                        $tags[] = $value . "_" . trim($tempTag);
                    }
                }
            }
        } catch (\Exception $e) {
            report($e);
            return [];
        }

        return $tags;
    }
}
