<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use Shopify\Clients\Graphql;
use App\Models\Brand;
use App\Models\RetailEdgeProduct;
use App\Models\RetailEdgeProductIsd; // Added this line
use App\Services\ShopifyService;
use Illuminate\Support\Facades\DB;

class CreateProductBackup extends Command
{
    protected $signature = 'shopifyCreateProductBackup';
    protected $description = 'Create Shopify products using GraphQL';

    private $client;
    private $shopifyService;

    public function __construct()
    {
        parent::__construct();
        $this->shopifyService = new ShopifyService();
    }

    private function checkResponseForErrors($response)
    {
        $responseBody = $response->getDecodedBody();

        if (isset($responseBody['errors'])) {
            throw new \Exception(json_encode($responseBody['errors']));
        }

        if (
            isset($responseBody['data']['productCreate']['userErrors'])
            && !empty($responseBody['data']['productCreate']['userErrors'])
        ) {
            throw new \Exception(json_encode($responseBody['data']['productCreate']['userErrors']));
        }

        return $responseBody;
    }

    private function createProduct($productData)
    {
        $mutation = <<<'GRAPHQL'
        mutation productCreate($input: ProductInput!) {
            productCreate(input: $input) {
                product {
                    id
                    title
                    handle
                    status
                    metafields(first: 10) { # Added to retrieve metafields for confirmation if needed
                        edges {
                            node {
                                id
                                namespace
                                key
                                value
                            }
                        }
                    }
                    variants(first: 100) {
                        edges {
                            node {
                                id
                                title
                                sku
                                price
                                compareAtPrice
                                inventoryItem {
                                    id
                                }
                            }
                        }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $input = [
            'title' => $productData['product']['title'],
            'descriptionHtml' => $productData['product']['body_html'],
            'productType' => $productData['product']['product_type'],
            'vendor' => $productData['product']['vendor'],
            'options' => $productData['product']['options'] ?? [], // Ensure options is an array
            'variants' => array_map(function ($variant) {
                $variantInput = [
                    'sku' => $variant['sku'],
                    'price' => $variant['price'],
                    'compareAtPrice' => $variant['compare_at_price'],
                    'barcode' => $variant['barcode'],
                    'inventoryManagement' => 'SHOPIFY', // Corrected from 'shopify' to 'SHOPIFY' enum
                ];
                if (isset($variant['option1'])) $variantInput['options'][] = $variant['option1'];
                if (isset($variant['option2'])) $variantInput['options'][] = $variant['option2'];
                if (isset($variant['option3'])) $variantInput['options'][] = $variant['option3'];
                return $variantInput;
            }, $productData['product']['variants']),
            'tags' => explode(',', $productData['product']['tags']),
            'status' => 'ACTIVE', // Shopify expects 'ACTIVE', 'DRAFT', or 'ARCHIVED'
        ];

        // Add template suffix if it exists
        if (isset($productData['product']['template_suffix'])) {
            $input['templateSuffix'] = $productData['product']['template_suffix'];
        }

        // Add metafields if they exist
        if (!empty($productData['product']['metafields'])) {
            $input['metafields'] = array_map(function ($metafield) {
                return [
                    'namespace' => $metafield['namespace'],
                    'key' => $metafield['key'],
                    'value' => $metafield['value'],
                    'type' => $metafield['type'], // Assuming 'single_line_text_field' is a valid GraphQL type string
                ];
            }, $productData['product']['metafields']);
        }

        $variables = ['input' => $input];

        $response = $this->client->query(['query' => $mutation, 'variables' => $variables]);
        return $this->checkResponseForErrors($response);
    }

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

                $pendingProductIds = collect($pendingProducts)->pluck('id')->toArray();

                $session = $this->shopifyService->getSession();
                $this->client = new Graphql($session->getShop(), $session->getAccessToken());

                $variantTypes = ['vt1' => 'Size', 'vt2' => 'Color', 'vt3' => 'Material', 'vt4' => 'Style'];
                $brands = Brand::all()->keyBy('brand_id');

                $countQuery = RetailEdgeProduct::whereIn('id', $pendingProductIds)
                    ->whereHas('children', function ($children) {
                        $children->where('uploaded_to_shopify', 0);
                    })
                    ->where('quantity', '>', 0);

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
                                                $variantTypeValue = $child->ring_size;
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
                            $mktDescription .= " - Design number: " . $product->real_design_number;
                        }

                        $productData['product'] = [
                            'title' => $product->title,
                            'body_html' => $mktDescription,
                            'variants' => $variants,
                            'options' => $options,
                            'product_type' => $product->s_cat,
                            'vendor' => $product->brand?->name,
                        ];

                        $productTags = $this->calculateTags($product);

                        if ($product->brand?->name == 'Pandora') {
                            $productTags[] = 'Pandora';
                            $productData['product']['template_suffix'] = 'no-buy';
                        }

                        $productData['product']['tags'] = implode(",", $productTags);

                        // Fetch and add ISDs as metafields (Copied from CreateProduct.php and adapted)
                        $isds = RetailEdgeProductIsd::where('sku', $product->sku)->get();
                        $metafieldsData = [];
                        if ($isds->isNotEmpty()) {
                            foreach ($isds as $isd) {
                                $key = strtolower($isd->isd_name);
                                $key = preg_replace('/[^a-z0-9\s]/', ' ', $key);
                                $key = preg_replace('/\s+/', ' ', $key);
                                $key = trim($key);
                                $metafieldKey = str_replace(' ', '_', $key);

                                if (!empty($metafieldKey) && !empty($isd->isd_value)) {
                                    $metafieldsData[] = [
                                        'key' => $metafieldKey,
                                        'value' => $isd->isd_value,
                                        'type' => 'single_line_text_field', // This type needs to be valid for GraphQL MetafieldInput
                                        'namespace' => 'retail_edge_isd'
                                    ];
                                }
                            }
                        }

                        if (!empty($metafieldsData)) {
                            $productData['product']['metafields'] = $metafieldsData;
                        }
                        // End of metafields logic

                        try {
                            $this->info(json_encode($productData, JSON_PRETTY_PRINT)); // For debugging the payload
                            $response = $this->createProduct($productData);

                            if (isset($response['data']['productCreate']['product'])) {
                                $shopifyProduct = $response['data']['productCreate']['product'];
                                $this->shopifyService->saveProductToDb($shopifyProduct);
                                $this->info($shopifyProduct['title'] . ' - saved to database');
                                Log::debug('Shopify product ' . $product->sku . ' created successfully!');

                                foreach ($product->children as $child) {
                                    $child->update(['uploaded_to_shopify' => 1]);
                                }
                            } else {
                                foreach ($product->children as $child) {
                                    $child->update(['uploaded_to_shopify' => 2]);
                                }

                                $message = 'Error while creating product. Sku :' . $product->sku . ', title: ' . $product->title;
                                Log::debug($message);
                                Log::debug(json_encode($productData));
                                Log::debug(json_encode($response));
                                $this->info($message);
                            }
                        } catch (\Exception $e) {
                            foreach ($product->children as $child) {
                                $child->update(['uploaded_to_shopify' => 2]);
                            }
                            report($e);
                            Log::debug("Error creating product {$product->sku}: " . $e->getMessage());
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
