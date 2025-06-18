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
                                    $updated = $child->update(['uploaded_to_shopify' => 1]);
                                    if ($updated) {
                                        $this->line("Marked child SKU {$child->sku} as uploaded_to_shopify");
                                    } else {
                                        $this->warn("Failed to mark child SKU {$child->sku} as uploaded_to_shopify");
                                    }
                                }
                                
                                // Also mark the parent as uploaded
                                $parentUpdated = $product->update(['uploaded_to_shopify' => 1]);
                                if ($parentUpdated) {
                                    $this->line("Marked parent SKU {$product->sku} as uploaded_to_shopify");
                                } else {
                                    $this->warn("Failed to mark parent SKU {$product->sku} as uploaded_to_shopify");
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
                    selectedOptions {
                      name
                      value
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

        $this->line("Executing GraphQL productCreate mutation...");
        $response = $client->query(['query' => $mutation, 'variables' => ['product' => $productInput]]);
        $resultBody = json_decode($response->getBody()->getContents(), true);

        // Handle errors
        $errors = $this->handleGraphQLErrors($resultBody);
        if (!empty($errors)) {
            throw new \Exception("GraphQL Errors: " . implode(' | ', $errors));
        }

        $createdProduct = $resultBody['data']['productCreate']['product'] ?? null;

        if ($createdProduct) {
            // Update the first variant's SKU if it's empty
            $this->updateFirstVariantSku($createdProduct, $product, $client);

            if ($product->children->count() > 1) {
                // Only create additional variants if there are multiple children
                // The first variant is already created by productCreate
                $this->createProductVariants($createdProduct, $product, $client);
            }

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
            'status' => 'ACTIVE', // Create as active
            'productOptions' => $this->buildProductOptions($product),
        ];

        // Note: ProductCreateInput doesn't support variants field
        // We'll update the first variant after product creation

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
     * Create product variants (with duplicate detection)
     */
    private function createProductVariants(array $createdProduct, RetailEdgeProduct $product, $client): void
    {
        $this->line("Creating variants for product: {$product->title}");

        $variantTypes = ['vt1' => 'Size', 'vt2' => 'Color', 'vt3' => 'Material', 'vt4' => 'Style'];
        $variants = [];
        $existingVariants = $this->getExistingVariantOptions($createdProduct);

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

            // Check if this variant combination already exists
            $optionKey = implode(' / ', $optionValues);
            if (in_array($optionKey, $existingVariants)) {
                $this->line("Skipping variant {$child->sku} - option combination '{$optionKey}' already exists");
                continue;
            }

            $variants[] = [
                'productId' => $createdProduct['id'],
                'sku' => $child->sku,
                'price' => (string) $price,
                'compareAtPrice' => ($price == $compareAtPrice) ? null : (string) $compareAtPrice,
                'barcode' => $child->barcode,
                'optionValues' => $optionValues,
            ];
        }

        if (!empty($variants)) {
            $this->createVariantsBulk($variants, $client, $createdProduct);
        } else {
            $this->line("No new variants to create - all option combinations already exist");
        }
    }

    /**
     * Get existing variant option combinations from created product
     */
    private function getExistingVariantOptions(array $createdProduct): array
    {
        $existingVariants = [];

        if (isset($createdProduct['variants']['edges'])) {
            foreach ($createdProduct['variants']['edges'] as $edge) {
                $variant = $edge['node'];
                if (isset($variant['selectedOptions'])) {
                    $optionValues = [];
                    foreach ($variant['selectedOptions'] as $option) {
                        $optionValues[] = $option['value'];
                    }
                    $existingVariants[] = implode(' / ', $optionValues);
                }
            }
        }

        return $existingVariants;
    }

    /**
     * Create variants in bulk
     */
    private function createVariantsBulk(array $variants, $client, array $createdProduct): void
    {
        $this->line("Creating " . count($variants) . " variants using bulk creation...");
        $this->createVariantsIndividually($variants, $client, $createdProduct);
    }

    /**
     * Create variants using productVariantsBulkCreate (2025-01 API)
     */
    private function createVariantsIndividually(array $variants, $client, array $createdProduct): void
    {
        $this->line("Using productVariantsBulkCreate for variant creation...");

        // Convert variants to the correct format for productVariantsBulkCreate
        $bulkVariants = [];
        foreach ($variants as $variant) {
            $bulkVariant = [
                'price' => $variant['price'],
                'barcode' => $variant['barcode'],
                'inventoryPolicy' => 'DENY',
                'taxable' => true,
            ];

            // Add compareAtPrice if it's different from price
            if (!empty($variant['compareAtPrice']) && $variant['compareAtPrice'] !== $variant['price']) {
                $bulkVariant['compareAtPrice'] = $variant['compareAtPrice'];
            }

            // Add inventory item with SKU (correct field structure)
            $bulkVariant['inventoryItem'] = [
                'sku' => $variant['sku'],
                'tracked' => true,
            ];

            // Add option values if they exist (using optionId from created product)
            if (!empty($variant['optionValues'])) {
                $bulkVariant['optionValues'] = [];
                foreach ($variant['optionValues'] as $index => $value) {
                    $optionId = $this->getOptionIdByIndex($createdProduct, $index);
                    if ($optionId) {
                        $bulkVariant['optionValues'][] = [
                            'name' => $value,
                            'optionId' => $optionId,
                        ];
                    }
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
     * Get option ID by index from created product
     */
    private function getOptionIdByIndex(array $createdProduct, int $index): ?string
    {
        if (!isset($createdProduct['options'])) {
            return null;
        }

        // Sort options by position to ensure correct mapping
        $options = $createdProduct['options'];
        usort($options, function ($a, $b) {
            return ($a['position'] ?? 0) <=> ($b['position'] ?? 0);
        });

        return $options[$index]['id'] ?? null;
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

        // Batch process metafields in chunks of 250 (Shopify's limit)
        if (!empty($metafieldsToSet)) {
            $this->processMetafieldsInBatches($metafieldsToSet, $product, $client);
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
                    'inventory_item_id' => $this->extractIdFromGid($variant['inventoryItem']['id'] ?? null),
                    'inventory_item_gid' => $variant['inventoryItem']['id'] ?? null,
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
                  inventoryItem {
                    id
                  }
                  selectedOptions {
                    name
                    value
                  }
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
     * Process metafields in batches of 250 (Shopify's limit)
     */
    private function processMetafieldsInBatches(array $metafieldsToSet, RetailEdgeProduct $product, $client): void
    {
        $batchSize = 25; // Shopify's actual limit for metafields
        $totalMetafields = count($metafieldsToSet);
        $batches = array_chunk($metafieldsToSet, $batchSize);

        $this->line("Processing {$totalMetafields} metafields in " . count($batches) . " batches of {$batchSize} for product: {$product->sku}");

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

        $totalSuccessful = 0;
        $totalFailed = 0;
        $allResultBodies = [];

        foreach ($batches as $batchIndex => $batch) {
            $batchNumber = $batchIndex + 1;
            $this->line("Processing batch {$batchNumber}/" . count($batches) . " (" . count($batch) . " metafields)");

            try {
                $response = $client->query(['query' => $metafieldsSetMutation, 'variables' => ['metafields' => $batch]]);
                $resultBody = json_decode($response->getBody()->getContents(), true);

                $userErrors = $resultBody['data']['metafieldsSet']['userErrors'] ?? ($resultBody['errors'] ?? []);
                if (!empty($userErrors)) {
                    foreach ($userErrors as $error) {
                        $failedMetafieldIndex = $error['elementIndex'] ?? 'N/A';
                        $failedMetafield = ($failedMetafieldIndex !== 'N/A' && isset($batch[$failedMetafieldIndex])) ? $batch[$failedMetafieldIndex]['key'] : 'unknown';
                        $this->error("Shopify MetafieldsSet API Error in batch {$batchNumber} (Metafield: {$failedMetafield}): {$error['message']}");
                        $totalFailed++;
                    }

                    // Log batch error
                    PriceInventoryLog::create([
                        'marketplace' => 'Shopify',
                        'item_identifier' => $product->sku,
                        'change_type' => 'metafield_create',
                        'from_value' => null,
                        'to_value' => 'batch_failed',
                        'status' => 'failed',
                        'message' => "Batch {$batchNumber} failed with " . count($userErrors) . " errors",
                        'job_name' => 'shopifyCreateProduct'
                    ]);
                } else {
                    $createdMetafields = $resultBody['data']['metafieldsSet']['metafields'] ?? [];
                    $batchSuccessful = count($createdMetafields);
                    $totalSuccessful += $batchSuccessful;
                    $this->info("Batch {$batchNumber} successful: {$batchSuccessful} metafields created");

                    // Store result body for database saving with correct batch offset
                    $allResultBodies[] = [
                        'resultBody' => $resultBody,
                        'batch' => $batch,
                        'batchOffset' => $batchIndex * $batchSize
                    ];
                }

                // Small delay between batches to avoid rate limiting
                if ($batchNumber < count($batches)) {
                    usleep(500000); // 0.5 second delay
                }
            } catch (\Exception $e) {
                $this->error("Exception during metafieldsSet batch {$batchNumber} for product {$product->sku}: " . $e->getMessage());
                $totalFailed += count($batch);

                // Log batch exception
                PriceInventoryLog::create([
                    'marketplace' => 'Shopify',
                    'item_identifier' => $product->sku,
                    'change_type' => 'metafield_create',
                    'from_value' => null,
                    'to_value' => 'batch_exception',
                    'status' => 'failed',
                    'message' => "Batch {$batchNumber} exception: " . $e->getMessage(),
                    'job_name' => 'shopifyCreateProduct'
                ]);
            }
        }

        // Save all successful metafields to local database
        if (!empty($allResultBodies)) {
            foreach ($allResultBodies as $batchData) {
                $this->saveMetafieldsToDatabase($batchData['resultBody'], $batchData['batch'], $product);
            }
        }

        // Final summary
        $this->info("Metafield processing complete: {$totalSuccessful} successful, {$totalFailed} failed out of {$totalMetafields} total");

        // Log final summary
        PriceInventoryLog::create([
            'marketplace' => 'Shopify',
            'item_identifier' => $product->sku,
            'change_type' => 'metafield_create',
            'from_value' => null,
            'to_value' => "{$totalSuccessful}_of_{$totalMetafields}",
            'status' => $totalFailed > 0 ? 'partial' : 'success',
            'message' => "Metafield batch processing complete: {$totalSuccessful} successful, {$totalFailed} failed",
            'job_name' => 'shopifyCreateProduct'
        ]);
    }

    /**
     * Update the first variant's SKU if it's empty
     */
    private function updateFirstVariantSku(array $createdProduct, RetailEdgeProduct $product, $client): void
    {
        if (!isset($createdProduct['variants']['edges'][0])) {
            return;
        }

        $firstVariant = $createdProduct['variants']['edges'][0]['node'];
        $firstChild = $product->children->first();

        // Check if the first variant has an empty SKU
        if (empty($firstVariant['sku']) && $firstChild) {
            $this->line("Updating first variant SKU from empty to: {$firstChild->sku}");

            // Calculate prices for the first variant
            $retailPrices = [$firstChild->retail_price1, $firstChild->retail_price2];
            $prices = array_filter(array_map('floatval', $retailPrices), function ($price) {
                return $price > 0;
            });

            $price = empty($prices) ? 0 : min($prices);
            $compareAtPrice = empty($prices) ? 0 : max($prices);

            $variantInput = [
                'id' => $firstVariant['id'],
                'price' => (string) $price,
                'compareAtPrice' => ($price == $compareAtPrice) ? null : (string) $compareAtPrice,
                'barcode' => $firstChild->barcode,
                'inventoryItem' => [
                    'sku' => $firstChild->sku,
                    'tracked' => true,
                ],
                'inventoryPolicy' => 'DENY',
                'taxable' => true,
            ];

            $mutation = <<<GRAPHQL
            mutation productVariantsBulkUpdate(\$productId: ID!, \$variants: [ProductVariantsBulkInput!]!) {
              productVariantsBulkUpdate(productId: \$productId, variants: \$variants) {
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
                $response = $client->query([
                    'query' => $mutation,
                    'variables' => [
                        'productId' => $createdProduct['id'],
                        'variants' => [$variantInput]
                    ]
                ]);
                $resultBody = json_decode($response->getBody()->getContents(), true);

                $userErrors = $resultBody['data']['productVariantsBulkUpdate']['userErrors'] ?? ($resultBody['errors'] ?? []);
                if (!empty($userErrors)) {
                    foreach ($userErrors as $error) {
                        $this->error("First variant SKU update error: {$error['message']}");
                    }
                } else {
                    $this->info("Successfully updated first variant SKU to: {$firstChild->sku}");
                }
            } catch (\Exception $e) {
                $this->error("Exception updating first variant SKU: " . $e->getMessage());
            }
        }
    }

    /**
     * Find SKU by variant GID from product children
     */
    private function findSkuByVariantGid(RetailEdgeProduct $product, string $variantGid): ?string
    {
        // Extract the variant ID from the GID
        $variantId = str_replace('gid://shopify/ProductVariant/', '', $variantGid);

        // Get the latest product data to find the SKU for this variant GID
        $productId = null;

        // Try to extract product ID from the current context or use a fresh query
        // We need to query Shopify to get the current variant data
        try {
            $session = (new \App\Services\ShopifyService)->getSession();
            $client = new \Shopify\Clients\Graphql($session->getShop(), $session->getAccessToken());

            $query = <<<GRAPHQL
            query getVariant(\$id: ID!) {
              productVariant(id: \$id) {
                id
                sku
              }
            }
            GRAPHQL;

            $response = $client->query(['query' => $query, 'variables' => ['id' => $variantGid]]);
            $resultBody = json_decode($response->getBody()->getContents(), true);

            $variant = $resultBody['data']['productVariant'] ?? null;
            if ($variant && !empty($variant['sku'])) {
                return $variant['sku'];
            }
        } catch (\Exception $e) {
            // If GraphQL query fails, fall back to the original logic
            Log::warning("Failed to query variant SKU for GID {$variantGid}: " . $e->getMessage());
        }

        // Fallback: return null if we can't find the SKU
        return null;
    }

    /**
     * Extract numeric ID from Shopify GID
     */
    private function extractIdFromGid(?string $gid): ?int
    {
        if (empty($gid)) {
            return null;
        }

        // Extract numeric ID from GID format: gid://shopify/ResourceType/12345
        if (preg_match('/gid:\/\/shopify\/[^\/]+\/(\d+)$/', $gid, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
