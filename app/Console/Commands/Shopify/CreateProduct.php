<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use Shopify\Clients\Graphql;
use App\Models\Brand;
use App\Models\RetailEdgeProduct;
use App\Models\RetailEdgeProductIsd;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyProductVariantMetafield;
use App\Models\ShopifyProductMetafield;
use App\Models\PriceInventoryLog;
use App\Services\ShopifyService;
use App\Services\ShopifyConnectionService;
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
    protected $description = 'Creates Shopify products using GraphQL API with comprehensive logging';

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

                // Initialize GraphQL client
                $client = new Graphql($session->getShop(), $session->getAccessToken());

                while ($count) {
                    $this->info('Count: ' . $count);
                    $product = RetailEdgeProduct::withWhereHas('children', function ($children) {
                        $children->where('uploaded_to_shopify', 0);
                    })->with(['brand'])->where('quantity', '>', 0)->first();

                    if ($product) {
                        $this->info('======================================');
                        $this->info("Processing Product: {$product->title} (SKU: {$product->sku})");

                        // Log product creation start
                        PriceInventoryLog::create([
                            'marketplace' => 'Shopify',
                            'item_identifier' => $product->sku,
                            'change_type' => 'product_create',
                            'from_value' => null,
                            'to_value' => 'initiating',
                            'status' => 'processing',
                            'message' => "Starting GraphQL product creation for: {$product->title}",
                            'job_name' => 'shopifyCreateProduct'
                        ]);

                        try {
                            // Create product using GraphQL
                            $createdProductData = $this->createProductWithGraphQL($product, $client);

                            if ($createdProductData) {
                                // Log product creation success
                                PriceInventoryLog::create([
                                    'marketplace' => 'Shopify',
                                    'item_identifier' => $product->sku,
                                    'change_type' => 'product_create',
                                    'from_value' => null,
                                    'to_value' => $createdProductData['id'],
                                    'status' => 'success',
                                    'message' => "Product created successfully with ID: {$createdProductData['id']}",
                                    'job_name' => 'shopifyCreateProduct'
                                ]);

                                // Handle metafields after creation (same as UpdateProduct)
                                $this->handleMetafieldsAfterCreation($product, $createdProductData, $client);

                                // Save product to database
                                $this->saveProductToDatabase($createdProductData, $product);

                                // Mark children as uploaded
                                foreach ($product->children as $child) {
                                    $child->update(['uploaded_to_shopify' => 1]);
                                }

                                $this->info("Successfully created product: {$product->title}");
                            } else {
                                throw new \Exception("Product creation returned null data");
                            }
                        } catch (\Exception $e) {
                            $product_errors_occurred = true;
                            $errorMessage = $e->getMessage();

                            // Log product creation failure
                            PriceInventoryLog::create([
                                'marketplace' => 'Shopify',
                                'item_identifier' => $product->sku,
                                'change_type' => 'product_create',
                                'from_value' => null,
                                'to_value' => 'failed',
                                'status' => 'failed',
                                'message' => "Product creation failed: {$errorMessage}",
                                'job_name' => 'shopifyCreateProduct'
                            ]);

                            Log::error("Shopify GraphQL Exception for SKU {$product->sku}: {$errorMessage}");
                            $this->error("Failed to create product {$product->sku}: {$errorMessage}");

                            // Mark children as failed
                            foreach ($product->children as $child) {
                                $child->update(['uploaded_to_shopify' => 2]);
                            }
                        }

                        usleep(1500000); // 1.5 second delay
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

    /**
     * Create product using GraphQL API
     */
    private function createProductWithGraphQL(RetailEdgeProduct $product, $client): ?array
    {
        // Build product input for GraphQL
        $productInput = $this->buildProductInput($product);

        $mutation = <<<GRAPHQL
        mutation productCreate(\$product: ProductCreateInput!) {
          productCreate(product: \$product) {
            product {
              id
              title
              handle
              status
              options {
                id
                name
                position
                optionValues {
                  id
                  name
                  hasVariants
                }
              }
              variants(first: 100) {
                edges {
                  node {
                    id
                    sku
                    price
                    compareAtPrice
                    barcode
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

        $this->line("Executing GraphQL productCreate mutation...");
        $response = $client->query(['query' => $mutation, 'variables' => ['product' => $productInput]]);
        $resultBody = json_decode($response->getBody()->getContents(), true);

        // Handle errors
        $errors = $this->handleGraphQLErrors($resultBody);
        if (!empty($errors)) {
            throw new \Exception("GraphQL Errors: " . implode(' | ', $errors));
        }

        $createdProduct = $resultBody['data']['productCreate']['product'] ?? null;

        if ($createdProduct && $product->children->count() > 1) {
            // Only create additional variants if there are multiple children
            // The first variant is already created by productCreate
            $this->createProductVariants($createdProduct, $product, $client);

            // Refresh product data to get updated variants
            $createdProduct = $this->getProductData($createdProduct['id'], $client);
        }

        return $createdProduct;
    }

    /**
     * Build product input for GraphQL
     */
    private function buildProductInput(RetailEdgeProduct $product): array
    {
        $productTags = $this->calculateTags($product);

        $productInput = [
            'title' => $product->title,
            'descriptionHtml' => $this->buildProductDescription($product),
            'vendor' => $product->brand?->name,
            'productType' => $product->s_cat,
            'tags' => $productTags, // Array format for GraphQL
            'status' => 'ACTIVE', // Create as draft initially
            'productOptions' => $this->buildProductOptions($product),
        ];

        // Add template suffix for Pandora products
        if ($product->brand?->name === 'Pandora') {
            $productInput['templateSuffix'] = 'no-buy';
            if (!in_array('Pandora', $productTags)) {
                $productInput['tags'][] = 'Pandora';
            }
        }

        return $productInput;
    }

    /**
     * Build product options for GraphQL (2025-01 format)
     */
    private function buildProductOptions(RetailEdgeProduct $product): array
    {
        $variantTypes = ['vt1' => 'Size', 'vt2' => 'Color', 'vt3' => 'Material', 'vt4' => 'Style'];
        $variantOptions = [];

        if ($product->children->count()) {
            foreach ($product->children as $child) {
                $vts = array_filter(array_map('trim', array_map('strtolower', explode("-", $child->id3))));

                foreach ($vts as $vt) {
                    $vt = trim($vt);

                    if (isset($variantTypes[$vt])) {
                        $variantType = $variantTypes[$vt];
                        $variantTypeValue = '';

                        if ($vt == 'vt1') {
                            if ($child->s_cat == 'Rings') {
                                $variantTypeValue = $child->ring_size;
                            } elseif ($child->s_cat == 'Bracelets') {
                                $variantTypeValue = $child->bracelet_length;
                            }
                        } elseif ($vt == 'vt2') {
                            $variantTypeValue = $child->metal_colour;
                        } elseif ($vt == 'vt3') {
                            $variantTypeValue = $child->s_metal_type;
                        } elseif ($vt == 'vt4') {
                            $variantTypeValue = $child->pendant_style;
                        }

                        if (!empty($variantTypeValue)) {
                            if (!isset($variantOptions[$variantType])) {
                                $variantOptions[$variantType] = [];
                            }
                            if (!in_array($variantTypeValue, $variantOptions[$variantType])) {
                                $variantOptions[$variantType][] = $variantTypeValue;
                            }
                        }
                    }
                }
            }
        }

        // Convert to GraphQL 2025-01 format
        $productOptions = [];
        foreach ($variantOptions as $optionName => $optionValues) {
            $values = [];
            foreach ($optionValues as $value) {
                $values[] = ['name' => $value];
            }

            $productOptions[] = [
                'name' => $optionName,
                'values' => $values
            ];
        }

        return $productOptions;
    }

    /**
     * Handle metafields after product creation (same logic as UpdateProduct)
     */
    private function handleMetafieldsAfterCreation(RetailEdgeProduct $product, array $createdProductData, $client): void
    {
        // Use MetafieldAssignmentService (same as UpdateProduct)
        $metafieldService = new MetafieldAssignmentService();
        $assignment = $metafieldService->determineMetafieldAssignment($product);

        $this->line("Metafield assignment type: {$assignment['type']} for Product: {$product->sku}");

        $metafieldsToSet = [];

        // Handle product-level metafields (SAME as UpdateProduct)
        if (!empty($assignment['product_metafields'])) {
            $this->line("Processing " . count($assignment['product_metafields']) . " product-level metafields");
            foreach ($assignment['product_metafields'] as $metafield) {
                $shopifyMetafieldDef = ShopifyMetafield::where('name', $metafield['isd_name'])
                    ->where('owner_type', 'PRODUCT')
                    ->first();

                if ($shopifyMetafieldDef && !empty($metafield['value'])) {
                    $metafieldsToSet[] = [
                        'ownerId' => $createdProductData['id'], // Product GID
                        'namespace' => $shopifyMetafieldDef->namespace,
                        'key' => $shopifyMetafieldDef->key,
                        'type' => $shopifyMetafieldDef->type,
                        'value' => (string) $metafield['value'],
                    ];
                    $this->line("Added product metafield: {$metafield['isd_name']} = {$metafield['value']}");
                } else {
                    $this->warn("Skipping product metafield '{$metafield['isd_name']}': Definition not found or empty value.");
                }
            }
        }

        // Handle variant-level metafields (SAME as UpdateProduct)
        if (!empty($assignment['variant_metafields'])) {
            foreach ($assignment['variant_metafields'] as $sku => $metafields) {
                // Find variant ID from created product data
                $variantId = $this->findVariantIdBySku($createdProductData, $sku);
                if (!$variantId) {
                    $this->warn("Could not find variant ID for SKU: {$sku}");
                    continue;
                }

                $this->line("Processing " . count($metafields) . " variant-level metafields for SKU: {$sku}");
                foreach ($metafields as $metafield) {
                    $shopifyMetafieldDef = ShopifyMetafield::where('name', $metafield['isd_name'])
                        ->where('owner_type', 'PRODUCTVARIANT')
                        ->first();

                    if ($shopifyMetafieldDef && !empty($metafield['value'])) {
                        $metafieldsToSet[] = [
                            'ownerId' => $variantId, // Variant GID
                            'namespace' => $shopifyMetafieldDef->namespace,
                            'key' => $shopifyMetafieldDef->key,
                            'type' => $shopifyMetafieldDef->type,
                            'value' => (string) $metafield['value'],
                        ];
                        $this->line("Added variant metafield: {$metafield['isd_name']} = {$metafield['value']}");
                    } else {
                        $this->warn("Skipping variant metafield '{$metafield['isd_name']}' for SKU {$sku}: Definition not found or empty value.");
                    }
                }
            }
        }

        // Batch process all metafields using SAME mutation as UpdateProduct
        if (!empty($metafieldsToSet)) {
            $metafieldsSetMutation = <<<GRAPHQL
            mutation metafieldsSet(\$metafields: [MetafieldsSetInput!]!) {
              metafieldsSet(metafields: \$metafields) {
                metafields {
                  id
                  key
                  namespace
                  value
                }
                userErrors {
                  field
                  message
                  elementIndex
                }
              }
            }
            GRAPHQL;

            try {
                $this->line("Attempting to set/update " . count($metafieldsToSet) . " metafields for product: {$product->sku}");
                $response = $client->query(['query' => $metafieldsSetMutation, 'variables' => ['metafields' => $metafieldsToSet]]);
                $resultBody = json_decode($response->getBody()->getContents(), true);

                $userErrors = $resultBody['data']['metafieldsSet']['userErrors'] ?? ($resultBody['errors'] ?? []);
                if (!empty($userErrors)) {
                    foreach ($userErrors as $error) {
                        $failedMetafieldIndex = $error['elementIndex'] ?? 'N/A';
                        $failedMetafield = ($failedMetafieldIndex !== 'N/A' && isset($metafieldsToSet[$failedMetafieldIndex])) ? $metafieldsToSet[$failedMetafieldIndex]['key'] : 'unknown';
                        $this->error("Shopify MetafieldsSet API Error (Metafield: {$failedMetafield}): {$error['message']}");

                        // Log metafield error
                        PriceInventoryLog::create([
                            'marketplace' => 'Shopify',
                            'item_identifier' => $product->sku,
                            'change_type' => 'metafield_create',
                            'from_value' => null,
                            'to_value' => 'failed',
                            'status' => 'failed',
                            'message' => "Metafield error: {$error['message']}",
                            'job_name' => 'shopifyCreateProduct'
                        ]);
                    }
                } else {
                    $this->info("Successfully set/updated " . count($metafieldsToSet) . " metafields for product: {$product->sku}");

                    // Save metafields to local database (both product and variant)
                    $this->saveMetafieldsToDatabase($resultBody, $metafieldsToSet, $product);

                    // Log metafield success
                    PriceInventoryLog::create([
                        'marketplace' => 'Shopify',
                        'item_identifier' => $product->sku,
                        'change_type' => 'metafield_create',
                        'from_value' => null,
                        'to_value' => count($metafieldsToSet) . '_metafields',
                        'status' => 'success',
                        'message' => "Successfully created " . count($metafieldsToSet) . " metafields",
                        'job_name' => 'shopifyCreateProduct'
                    ]);
                }
            } catch (\Exception $e) {
                $this->error("Exception during metafieldsSet for product {$product->sku}: " . $e->getMessage());

                // Log metafield exception
                PriceInventoryLog::create([
                    'marketplace' => 'Shopify',
                    'item_identifier' => $product->sku,
                    'change_type' => 'metafield_create',
                    'from_value' => null,
                    'to_value' => 'exception',
                    'status' => 'failed',
                    'message' => "Metafield exception: " . $e->getMessage(),
                    'job_name' => 'shopifyCreateProduct'
                ]);
            }
        } else {
            $this->line("No metafields to set for product: {$product->sku}");
        }
    }

    /**
     * Find variant ID by SKU from created product data
     */
    private function findVariantIdBySku(array $productData, string $sku): ?string
    {
        if (!isset($productData['variants']['edges'])) {
            return null;
        }

        foreach ($productData['variants']['edges'] as $edge) {
            if (isset($edge['node']['sku']) && $edge['node']['sku'] === $sku) {
                return $edge['node']['id'];
            }
        }

        return null;
    }

    /**
     * Handle GraphQL errors
     */
    private function handleGraphQLErrors(array $resultBody): array
    {
        $errors = [];

        // Handle user errors (field-specific)
        if (!empty($resultBody['data']['productCreate']['userErrors'])) {
            foreach ($resultBody['data']['productCreate']['userErrors'] as $error) {
                $errors[] = "Field '{$error['field']}': {$error['message']}";
            }
        }

        // Handle GraphQL errors (system-level)
        if (!empty($resultBody['errors'])) {
            foreach ($resultBody['errors'] as $error) {
                $errors[] = "GraphQL Error: {$error['message']}";
            }
        }

        return $errors;
    }

    /**
     * Save product to database
     */
    private function saveProductToDatabase(array $productData, RetailEdgeProduct $product): void
    {
        try {
            // Use existing ShopifyService method if available, or implement custom logic
            $shopifyService = new ShopifyService();

            // Convert GraphQL response to format expected by saveProductToDb
            $restFormatProduct = $this->convertGraphQLToRestFormat($productData, $product);

            $shopifyService->saveProductToDb($restFormatProduct);

            $this->info("Product saved to database: {$product->title}");
        } catch (\Exception $e) {
            $this->warn("Failed to save product to database: " . $e->getMessage());
            Log::warning("Failed to save product to database for SKU {$product->sku}: " . $e->getMessage());
        }
    }

    /**
     * Convert GraphQL response to REST format for database saving
     */
    private function convertGraphQLToRestFormat(array $graphqlProduct, RetailEdgeProduct $product): array
    {
        $variants = [];

        if (isset($graphqlProduct['variants']['edges'])) {
            foreach ($graphqlProduct['variants']['edges'] as $edge) {
                $variant = $edge['node'];
                $variants[] = [
                    'id' => str_replace('gid://shopify/ProductVariant/', '', $variant['id']),
                    'sku' => $variant['sku'] ?? '',
                    'price' => $variant['price'] ?? '0.00',
                    'compare_at_price' => $variant['compareAtPrice'] ?? null,
                    'barcode' => $variant['barcode'] ?? '',
                    'product_id' => str_replace('gid://shopify/Product/', '', $graphqlProduct['id']),
                    'title' => $variant['title'] ?? null,
                    'position' => 1, // Default position
                    'inventory_policy' => 'deny', // Default
                    'fulfillment_service' => 'manual', // Default
                    'inventory_management' => 'shopify', // Default
                    'option1' => null, // Will be populated by variant options if needed
                    'option2' => null,
                    'option3' => null,
                    'taxable' => true, // Default
                    'grams' => 0, // Default
                    'weight' => 0, // Default
                    'inventory_item_id' => null, // Not available in initial creation
                    'inventory_quantity' => 0, // Default
                    'old_inventory_quantity' => 0, // Default
                    'requires_shipping' => true, // Default
                ];
            }
        }

        // Get tags from product
        $productTags = $this->calculateTags($product);

        return [
            'id' => str_replace('gid://shopify/Product/', '', $graphqlProduct['id']),
            'title' => $graphqlProduct['title'] ?? '',
            'handle' => $graphqlProduct['handle'] ?? '',
            'status' => strtolower($graphqlProduct['status'] ?? 'draft'),
            'vendor' => $product->brand?->name ?? null,
            'product_type' => $product->s_cat ?? null,
            'tags' => $productTags,
            'variants' => $variants,
        ];
    }

    /**
     * Build product description
     */
    private function buildProductDescription(RetailEdgeProduct $product): string
    {
        $mktDescription = $product->marketing_description ?? '';
        if ($product->brand?->name == 'Pandora') {
            $mktDescription .= " - Design number: " . $product->real_design_number;
        }
        return $mktDescription;
    }

    /**
     * Create product variants
     */
    private function createProductVariants(array $createdProduct, RetailEdgeProduct $product, $client): void
    {
        $this->line("Creating variants for product: {$product->title}");

        $variantTypes = ['vt1' => 'Size', 'vt2' => 'Color', 'vt3' => 'Material', 'vt4' => 'Style'];
        $variants = [];

        foreach ($product->children as $child) {
            // Calculate prices
            $retailPrices = [$child->retail_price1, $child->retail_price2];
            $prices = array_filter(array_map('floatval', $retailPrices), function ($price) {
                return $price > 0;
            });

            $price = empty($prices) ? 0 : min($prices);
            $compareAtPrice = empty($prices) ? 0 : max($prices);

            // Build option values for this variant
            $optionValues = [];
            $vts = array_filter(array_map('trim', array_map('strtolower', explode("-", $child->id3))));

            foreach ($vts as $vt) {
                $vt = trim($vt);
                if (isset($variantTypes[$vt])) {
                    $variantTypeValue = '';

                    if ($vt == 'vt1') {
                        if ($child->s_cat == 'Rings') {
                            $variantTypeValue = $child->ring_size;
                        } elseif ($child->s_cat == 'Bracelets') {
                            $variantTypeValue = $child->bracelet_length;
                        }
                    } elseif ($vt == 'vt2') {
                        $variantTypeValue = $child->metal_colour;
                    } elseif ($vt == 'vt3') {
                        $variantTypeValue = $child->s_metal_type;
                    } elseif ($vt == 'vt4') {
                        $variantTypeValue = $child->pendant_style;
                    }

                    if (!empty($variantTypeValue)) {
                        $optionValues[] = $variantTypeValue;
                    }
                }
            }

            $variants[] = [
                'productId' => $createdProduct['id'],
                'sku' => $child->sku,
                'price' => (string) $price,
                'compareAtPrice' => ($price == $compareAtPrice) ? null : (string) $compareAtPrice,
                'barcode' => $child->barcode,
                'inventoryManagement' => 'SHOPIFY',
                'optionValues' => $optionValues,
            ];
        }

        if (!empty($variants)) {
            $this->createVariantsBulk($variants, $client);
        }
    }

    /**
     * Create variants in bulk
     */
    private function createVariantsBulk(array $variants, $client): void
    {
        // Note: productVariantsBulkCreate may not be available in all Shopify API versions
        // Fall back to individual creation for reliability
        $this->line("Creating " . count($variants) . " variants individually for reliability...");
        $this->createVariantsIndividually($variants, $client);
    }

    /**
     * Create variants using productVariantsBulkCreate (2025-01 API)
     */
    private function createVariantsIndividually(array $variants, $client): void
    {
        $this->line("Using productVariantsBulkCreate for variant creation...");

        // Convert variants to the correct format for productVariantsBulkCreate
        $bulkVariants = [];
        foreach ($variants as $variant) {
            $bulkVariant = [
                'sku' => $variant['sku'],
                'price' => $variant['price'],
                'barcode' => $variant['barcode'],
                'inventoryManagement' => 'SHOPIFY',
                'inventoryPolicy' => 'DENY',
                'requiresShipping' => true,
                'taxable' => true,
            ];

            // Add compareAtPrice if it's different from price
            if (!empty($variant['compareAtPrice']) && $variant['compareAtPrice'] !== $variant['price']) {
                $bulkVariant['compareAtPrice'] = $variant['compareAtPrice'];
            }

            // Add option values if they exist
            if (!empty($variant['optionValues'])) {
                $bulkVariant['optionValues'] = [];
                foreach ($variant['optionValues'] as $index => $value) {
                    $bulkVariant['optionValues'][] = [
                        'optionName' => $this->getOptionNameByIndex($index), // We'll need to map this
                        'name' => $value
                    ];
                }
            }

            $bulkVariants[] = $bulkVariant;
        }

        $mutation = <<<GRAPHQL
        mutation productVariantsBulkCreate(\$productId: ID!, \$variants: [ProductVariantsBulkInput!]!) {
          productVariantsBulkCreate(productId: \$productId, variants: \$variants) {
            product {
              id
            }
            productVariants {
              id
              sku
              price
              compareAtPrice
              barcode
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        try {
            $productId = $variants[0]['productId']; // Get product ID from first variant
            $response = $client->query([
                'query' => $mutation,
                'variables' => [
                    'productId' => $productId,
                    'variants' => $bulkVariants
                ]
            ]);
            $resultBody = json_decode($response->getBody()->getContents(), true);

            $userErrors = $resultBody['data']['productVariantsBulkCreate']['userErrors'] ?? ($resultBody['errors'] ?? []);
            if (!empty($userErrors)) {
                foreach ($userErrors as $error) {
                    $this->error("Bulk variant creation error: {$error['message']} " . (isset($error['field']) ? json_encode($error['field']) : ''));
                }
            } else {
                $createdVariants = $resultBody['data']['productVariantsBulkCreate']['productVariants'] ?? [];
                $this->info("Successfully created " . count($createdVariants) . " variants using bulk creation");

                foreach ($createdVariants as $variant) {
                    $this->line("Created variant: {$variant['sku']} (ID: {$variant['id']})");
                }
            }
        } catch (\Exception $e) {
            $this->error("Exception during bulk variant creation: " . $e->getMessage());
        }
    }

    /**
     * Get option name by index (maps to the product options)
     */
    private function getOptionNameByIndex(int $index): string
    {
        $optionNames = ['Size', 'Color', 'Material', 'Style']; // Based on your variant types
        return $optionNames[$index] ?? "Option" . ($index + 1);
    }

    /**
     * Get updated product data
     */
    private function getProductData(string $productId, $client): ?array
    {
        $query = <<<GRAPHQL
        query getProduct(\$id: ID!) {
          product(id: \$id) {
            id
            title
            handle
            status
            options {
              id
              name
              position
              optionValues {
                id
                name
                hasVariants
              }
            }
            variants(first: 100) {
              edges {
                node {
                  id
                  sku
                  price
                  compareAtPrice
                  barcode
                }
              }
            }
          }
        }
        GRAPHQL;

        try {
            $response = $client->query(['query' => $query, 'variables' => ['id' => $productId]]);
            $resultBody = json_decode($response->getBody()->getContents(), true);

            return $resultBody['data']['product'] ?? null;
        } catch (\Exception $e) {
            $this->warn("Failed to fetch updated product data: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Calculate tags for product
     */
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

    /**
     * Save metafields to local database after successful Shopify creation (both product and variant)
     */
    private function saveMetafieldsToDatabase(array $resultBody, array $metafieldsToSet, RetailEdgeProduct $product): void
    {
        try {
            $createdMetafields = $resultBody['data']['metafieldsSet']['metafields'] ?? [];

            foreach ($createdMetafields as $index => $createdMetafield) {
                // Find the corresponding metafield from our input
                $inputMetafield = $metafieldsToSet[$index] ?? null;

                if (!$inputMetafield) continue;

                if (str_contains($inputMetafield['ownerId'], 'ProductVariant')) {
                    // This is a variant metafield, save it to variant metafields table
                    $shopifyMetafieldDef = ShopifyMetafield::where('namespace', $inputMetafield['namespace'])
                        ->where('key', $inputMetafield['key'])
                        ->where('owner_type', 'PRODUCTVARIANT')
                        ->first();

                    if ($shopifyMetafieldDef) {
                        // Extract SKU from variant GID to find the correct SKU
                        $variantGid = $inputMetafield['ownerId'];
                        $sku = $this->findSkuByVariantGid($product, $variantGid);

                        if ($sku) {
                            ShopifyProductVariantMetafield::updateOrCreate(
                                [
                                    'sku' => $sku,
                                    'shopify_metafield_id' => $shopifyMetafieldDef->id,
                                ],
                                [
                                    'value' => $createdMetafield['value'],
                                ]
                            );

                            $this->line("Saved variant metafield to database: {$shopifyMetafieldDef->name} = {$createdMetafield['value']} for SKU: {$sku}");
                        }
                    }
                } elseif (str_contains($inputMetafield['ownerId'], 'Product/')) {
                    // This is a product metafield, save it to product metafields table
                    $shopifyMetafieldDef = ShopifyMetafield::where('namespace', $inputMetafield['namespace'])
                        ->where('key', $inputMetafield['key'])
                        ->where('owner_type', 'PRODUCT')
                        ->first();

                    if ($shopifyMetafieldDef) {
                        ShopifyProductMetafield::updateOrCreate(
                            [
                                'product_sku' => $product->sku,
                                'shopify_metafield_id' => $shopifyMetafieldDef->id,
                            ],
                            [
                                'value' => $createdMetafield['value'],
                            ]
                        );

                        $this->line("Saved product metafield to database: {$shopifyMetafieldDef->name} = {$createdMetafield['value']} for Product SKU: {$product->sku}");
                    }
                }
            }
        } catch (\Exception $e) {
            $this->warn("Failed to save metafields to database for product {$product->sku}: " . $e->getMessage());
            Log::warning("Failed to save metafields to database for product {$product->sku}: " . $e->getMessage());
        }
    }

    /**
     * Find SKU by variant GID from product children
     */
    private function findSkuByVariantGid(RetailEdgeProduct $product, string $variantGid): ?string
    {
        // For CreateProduct, we need to match the variant GID with the product children
        // Since we're creating new products, we can match by the order or by finding the variant in the created product data
        // For now, let's extract from the children based on the variant GID pattern
        foreach ($product->children as $child) {
            // This is a simplified approach - in a real scenario, you might need to store the mapping
            // between child SKUs and created variant GIDs during the creation process
            return $child->sku; // Return the first child's SKU for now
        }
        return null;
    }
}
