<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Models\RetailEdgeProduct;
use App\Services\ShopifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;
use App\Models\SyncJob;

class GetProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyGetProducts {--dry-run : Run without making changes} {--chunk-size=250 : Number of products to process per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get Shopify products using GraphQL API with smart sync and auto-recreation';

    /**
     * GraphQL client instance
     */
    private $client;

    /**
     * Statistics tracking
     */
    private $stats = [
        'synced' => 0,
        'created' => 0,
        'deleted' => 0,
        'errors' => 0,
        'api_calls' => 0
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyGetProducts';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                // Check if GetProductsFromEWebMain is running and wait if needed
                $maxWaitTime = 300; // 5 minutes max wait
                $waitInterval = 10; // Check every 10 seconds
                $totalWaitTime = 0;
                
                while ($totalWaitTime < $maxWaitTime) {
                    $ewebJob = SyncJob::where('job_type', 'getProductsFromEWebMain')
                        ->where('marketplace', 'EWeb')
                        ->first();
                    
                    if ($ewebJob && $ewebJob->status == 1) {
                        $this->info("⏳ GetProductsFromEWebMain is running. Waiting... ({$totalWaitTime}s elapsed)");
                        Log::info("$marketplace $jobType waiting for GetProductsFromEWebMain to complete. Wait time: {$totalWaitTime}s");
                        
                        sleep($waitInterval);
                        $totalWaitTime += $waitInterval;
                    } else {
                        // GetProductsFromEWebMain is not running, proceed
                        if ($totalWaitTime > 0) {
                            $this->info("✅ GetProductsFromEWebMain completed. Proceeding with Shopify sync.");
                            Log::info("$marketplace $jobType proceeding after waiting {$totalWaitTime}s for GetProductsFromEWebMain");
                        }
                        break;
                    }
                }
                
                if ($totalWaitTime >= $maxWaitTime) {
                    $this->warn("⚠️ Waited maximum time ({$maxWaitTime}s) for GetProductsFromEWebMain. Proceeding anyway.");
                    Log::warning("$marketplace $jobType exceeded max wait time for GetProductsFromEWebMain. Proceeding.");
                }

                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                // Initialize GraphQL client
                $session = (new ShopifyService)->getSession();
                $this->client = new Graphql($session->getShop(), $session->getAccessToken());

                $this->info("🚀 Starting Shopify GraphQL sync...");

                if ($this->option('dry-run')) {
                    $this->warn("🔍 DRY RUN MODE - No changes will be made");
                }

                // Step 1: Get Shopify locations
                $this->info("📍 Syncing locations...");
                $this->getLocations();

                // Step 2: Get all products from Shopify with smart sync
                $this->info("📦 Syncing products with GraphQL...");
                $shopifySkus = $this->getProductsWithGraphQL();

                // Step 3: Identify and handle missing products
                $this->info("🔍 Analyzing product differences...");
                $this->handleProductDifferences($shopifySkus);

                // Step 4: Get inventory levels
                $this->info("📊 Syncing inventory levels...");
                $this->getInventoryLevels();

                // Display final statistics
                $this->displayStatistics();

                $job->update(['status' => 0, 'message' => null]);
                Log::info("$marketplace $jobType finished successfully!");
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                report($e);
                $this->error("❌ Error: " . $e->getMessage());
                Log::error("Shopify GetProducts failed: " . $e->getMessage(), ['exception' => $e]);
            }
        } else {
            Log::info("$marketplace $jobType is already running.");
            $this->warn("⚠️  Job is already running.");
        }
    }

    /**
     * Get locations using GraphQL
     */
    private function getLocations(): void
    {
        try {
            $query = <<<GRAPHQL
            query getLocations {
              locations(first: 250) {
                edges {
                  node {
                    id
                    name
                    address {
                      address1
                      address2
                      city
                      zip
                      province
                      country
                      phone
                      countryCode
                      provinceCode
                    }
                    isActive
                  }
                }
              }
            }
            GRAPHQL;

            $response = $this->client->query(['query' => $query]);
            $this->stats['api_calls']++;

            $resultBody = json_decode($response->getBody()->getContents(), true);

            if (isset($resultBody['data']['locations']['edges'])) {
                foreach ($resultBody['data']['locations']['edges'] as $edge) {
                    $locationData = $edge['node'];

                    if (!$this->option('dry-run')) {
                        ShopifyLocation::updateOrCreate(
                            [
                                'location_id' => str_replace('gid://shopify/Location/', '', $locationData['id']),
                            ],
                            [
                                'name' => $locationData['name'],
                                'address1' => $locationData['address']['address1'] ?? null,
                                'address2' => $locationData['address']['address2'] ?? null,
                                'city' => $locationData['address']['city'] ?? null,
                                'zip' => $locationData['address']['zip'] ?? null,
                                'province' => $locationData['address']['province'] ?? null,
                                'country' => $locationData['address']['country'] ?? null,
                                'phone' => $locationData['address']['phone'] ?? null,
                                'country_code' => $locationData['address']['countryCode'] ?? null,
                                'country_name' => $locationData['address']['country'] ?? null,
                                'province_code' => $locationData['address']['provinceCode'] ?? null,
                                'active' => $locationData['isActive'] ?? true,
                            ]
                        );
                    }
                }
                $this->info("✅ Synced " . count($resultBody['data']['locations']['edges']) . " locations");
            }
        } catch (\Exception $e) {
            $this->error("❌ Failed to sync locations: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get products using GraphQL with pagination
     */
    private function getProductsWithGraphQL(): array
    {
        $allShopifySkus = [];
        $hasNextPage = true;
        $cursor = null;
        $chunkSize = (int) $this->option('chunk-size');

        while ($hasNextPage) {
            try {
                // Get all products (no status filter to match REST behavior)
                $query = <<<GRAPHQL
                query getProducts(\$first: Int!, \$after: String) {
                  products(first: \$first, after: \$after) {
                    edges {
                      node {
                        id
                        title
                        handle
                        status
                        vendor
                        productType
                        tags
                        createdAt
                        updatedAt
                        variants(first: 250) {
                          edges {
                            node {
                              id
                              sku
                              price
                              compareAtPrice
                              barcode
                              inventoryQuantity
                              inventoryItem {
                                id
                                tracked
                              }
                            }
                          }
                          pageInfo {
                            hasNextPage
                            endCursor
                          }
                        }
                      }
                    }
                    pageInfo {
                      hasNextPage
                      endCursor
                    }
                  }
                }
                GRAPHQL;

                $variables = [
                    'first' => $chunkSize,
                    'after' => $cursor
                ];

                $response = $this->client->query(['query' => $query, 'variables' => $variables]);
                $this->stats['api_calls']++;

                $resultBody = json_decode($response->getBody()->getContents(), true);

                if (isset($resultBody['errors'])) {
                    throw new \Exception('GraphQL errors: ' . json_encode($resultBody['errors']));
                }

                $products = $resultBody['data']['products']['edges'] ?? [];

                if (!empty($products)) {
                    $chunkSkus = $this->processProductChunk($products);
                    $allShopifySkus = array_merge($allShopifySkus, $chunkSkus);

                    $this->info("📦 Processed " . count($products) . " products (Total SKUs: " . count($allShopifySkus) . ")");
                }

                // Check pagination
                $pageInfo = $resultBody['data']['products']['pageInfo'] ?? [];
                $hasNextPage = $pageInfo['hasNextPage'] ?? false;
                $cursor = $pageInfo['endCursor'] ?? null;


                // Rate limiting delay
                if ($hasNextPage) {
                    usleep(2000000); // 2 second delay
                }
            } catch (\Exception $e) {
                $this->error("❌ Failed to fetch products: " . $e->getMessage());
                throw $e;
            }
        }

        $this->info("✅ Total products synced: " . $this->stats['synced']);
        return array_unique($allShopifySkus);
    }

    /**
     * Process a chunk of products from GraphQL response
     */
    private function processProductChunk(array $products): array
    {
        $chunkSkus = [];

        foreach ($products as $edge) {
            $productNode = $edge['node'];

            // Check if this product has more variants to fetch
            $variantPageInfo = $productNode['variants']['pageInfo'] ?? [];
            $hasMoreVariants = $variantPageInfo['hasNextPage'] ?? false;

            // If product has more than 250 variants, fetch all variants separately
            if ($hasMoreVariants) {
                $allVariants = $this->getAllVariantsForProduct($productNode['id']);
                $productNode['variants']['edges'] = $allVariants;
            }

            if ($this->option('dry-run')) {
                // In dry-run mode, just collect SKUs
                $variants = $productNode['variants']['edges'] ?? [];
                foreach ($variants as $variantEdge) {
                    $sku = $variantEdge['node']['sku'] ?? '';
                    if (!empty($sku)) {
                        $chunkSkus[] = $sku;
                    }
                }
            } else {
                // Process products in a database transaction
                DB::transaction(function () use ($productNode, &$chunkSkus) {
                    try {
                        $productData = $this->convertGraphQLToRestFormat($productNode);
                        (new ShopifyService)->saveProductToDb($productData);

                        // Collect SKUs
                        foreach ($productData['variants'] as $variant) {
                            if (!empty($variant['sku'])) {
                                $chunkSkus[] = $variant['sku'];
                            }
                        }

                        $this->stats['synced']++;
                    } catch (\Exception $e) {
                        $this->stats['errors']++;
                        $this->error("❌ Failed to save product {$productNode['id']}: " . $e->getMessage());
                        Log::error("Failed to save product: " . $e->getMessage(), ['product_id' => $productNode['id']]);
                    }
                });
            }
        }

        return $chunkSkus;
    }

    /**
     * Get all variants for a product that has more than 250 variants
     */
    private function getAllVariantsForProduct(string $productId): array
    {
        $allVariants = [];
        $hasNextPage = true;
        $cursor = null;

        $this->info("   🔄 Fetching all variants for product with 250+ variants: $productId");

        while ($hasNextPage) {
            try {
                $query = <<<GRAPHQL
                query getProductVariants(\$productId: ID!, \$first: Int!, \$after: String) {
                  product(id: \$productId) {
                    variants(first: \$first, after: \$after) {
                      edges {
                        node {
                          id
                          sku
                          price
                          compareAtPrice
                          barcode
                          inventoryQuantity
                          inventoryItem {
                            id
                            tracked
                          }
                        }
                      }
                      pageInfo {
                        hasNextPage
                        endCursor
                      }
                    }
                  }
                }
                GRAPHQL;

                $variables = [
                    'productId' => $productId,
                    'first' => 250,
                    'after' => $cursor
                ];

                $response = $this->client->query(['query' => $query, 'variables' => $variables]);
                $this->stats['api_calls']++;

                $resultBody = json_decode($response->getBody()->getContents(), true);

                if (isset($resultBody['errors'])) {
                    throw new \Exception('GraphQL errors: ' . json_encode($resultBody['errors']));
                }

                $variants = $resultBody['data']['product']['variants']['edges'] ?? [];
                $allVariants = array_merge($allVariants, $variants);

                // Check pagination
                $pageInfo = $resultBody['data']['product']['variants']['pageInfo'] ?? [];
                $hasNextPage = $pageInfo['hasNextPage'] ?? false;
                $cursor = $pageInfo['endCursor'] ?? null;

                // Rate limiting delay
                if ($hasNextPage) {
                    usleep(1000000); // 1 second delay for variant pagination
                }
            } catch (\Exception $e) {
                $this->error("❌ Failed to fetch variants for product $productId: " . $e->getMessage());
                break;
            }
        }

        $this->info("   ✅ Fetched " . count($allVariants) . " variants for product $productId");
        return $allVariants;
    }

    /**
     * Convert GraphQL product format to REST format for compatibility
     */
    private function convertGraphQLToRestFormat(array $graphqlProduct): array
    {
        $variants = [];

        foreach ($graphqlProduct['variants']['edges'] as $variantEdge) {
            $variant = $variantEdge['node'];
            $variants[] = [
                'id' => str_replace('gid://shopify/ProductVariant/', '', $variant['id']),
                'sku' => $variant['sku'] ?? '',
                'price' => $variant['price'] ?? '0.00',
                'compare_at_price' => $variant['compareAtPrice'] ?? null,
                'barcode' => $variant['barcode'] ?? '',
                'product_id' => str_replace('gid://shopify/Product/', '', $graphqlProduct['id']),
                'title' => null,
                'position' => 1,
                'inventory_policy' => 'deny',
                'fulfillment_service' => 'manual',
                'inventory_management' => 'shopify',
                'option1' => null,
                'option2' => null,
                'option3' => null,
                'taxable' => true,
                'grams' => 0,
                'weight' => 0,
                'inventory_item_id' => str_replace('gid://shopify/InventoryItem/', '', $variant['inventoryItem']['id'] ?? ''),
                'inventory_quantity' => $variant['inventoryQuantity'] ?? 0,
                'old_inventory_quantity' => $variant['inventoryQuantity'] ?? 0,
                'requires_shipping' => true,
            ];
        }

        return [
            'id' => str_replace('gid://shopify/Product/', '', $graphqlProduct['id']),
            'title' => $graphqlProduct['title'] ?? '',
            'handle' => $graphqlProduct['handle'] ?? '',
            'status' => strtolower($graphqlProduct['status'] ?? 'draft'),
            'vendor' => $graphqlProduct['vendor'] ?? null,
            'product_type' => $graphqlProduct['productType'] ?? null,
            'tags' => is_array($graphqlProduct['tags']) ? implode(',', $graphqlProduct['tags']) : ($graphqlProduct['tags'] ?? ''),
            'variants' => $variants,
        ];
    }

    /**
     * Handle product differences and auto-recreation
     */
    private function handleProductDifferences(array $shopifySkus): void
    {
        // Get all SKUs from retail_edge_products
        $retailEdgeSkus = RetailEdgeProduct::whereHas('children', function ($query) {
            $query->where('quantity', '>', 0);
        })->pluck('sku')->toArray();

        // Get all SKUs from local Shopify database
        $localShopifySkus = ShopifyProductVariant::pluck('sku')->toArray();

        // Products to recreate (in retail_edge but not in Shopify)
        $skusToRecreate = array_diff($retailEdgeSkus, $shopifySkus);

        // Products to delete (in local DB but not in Shopify AND not in retail_edge)
        $skusToDelete = array_diff($localShopifySkus, array_merge($shopifySkus, $retailEdgeSkus));

        $this->info("📊 Analysis Results:");
        $this->info("   • Shopify SKUs: " . count($shopifySkus));
        $this->info("   • Retail Edge SKUs: " . count($retailEdgeSkus));
        $this->info("   • Local Shopify SKUs: " . count($localShopifySkus));
        $this->info("   • SKUs to recreate: " . count($skusToRecreate));
        $this->info("   • SKUs to delete: " . count($skusToDelete));

        // Handle recreation
        if (!empty($skusToRecreate)) {
            $this->handleProductRecreation($skusToRecreate);
        }

        // Handle deletion
        if (!empty($skusToDelete)) {
            $this->handleProductDeletion($skusToDelete);
        }
    }

    /**
     * Handle product recreation for missing products
     */
    private function handleProductRecreation(array $skusToRecreate): void
    {
        $this->info("🔄 Found " . count($skusToRecreate) . " products to recreate on Shopify");

        if ($this->option('dry-run')) {
            $this->warn("🔍 DRY RUN: Would recreate products for SKUs: " . implode(', ', array_slice($skusToRecreate, 0, 10)) . (count($skusToRecreate) > 10 ? '...' : ''));
            return;
        }

        // Mark retail_edge products as needing upload
        $productsToRecreate = RetailEdgeProduct::whereIn('sku', $skusToRecreate)->get();

        foreach ($productsToRecreate as $product) {
            $product->children()->update(['uploaded_to_shopify' => 0]);
            $this->info("   • Marked for recreation: {$product->sku} - {$product->title}");
        }

        $this->stats['created'] = count($skusToRecreate);

        // Call CreateProduct command
        $this->info("🚀 Calling CreateProduct command to recreate missing products...");
        $exitCode = $this->call('shopifyCreateProduct');

        if ($exitCode === 0) {
            $this->info("✅ Product recreation completed successfully");
        } else {
            $this->error("❌ Product recreation failed with exit code: $exitCode");
        }
    }

    /**
     * Handle deletion of products that no longer exist
     */
    private function handleProductDeletion(array $skusToDelete): void
    {
        if (empty($skusToDelete)) {
            return;
        }

        $this->info("🗑️  Found " . count($skusToDelete) . " products to delete from local database");

        if ($this->option('dry-run')) {
            $this->warn("🔍 DRY RUN: Would delete products for SKUs: " . implode(', ', array_slice($skusToDelete, 0, 10)) . (count($skusToDelete) > 10 ? '...' : ''));
            return;
        }

        // Get product IDs to delete
        $variantsToDelete = ShopifyProductVariant::whereIn('sku', $skusToDelete)->get();
        $productIdsToDelete = $variantsToDelete->pluck('shopify_product_id')->unique()->toArray();

        if (!empty($productIdsToDelete)) {
            $this->deleteShopifyProductsFromDb($productIdsToDelete);
            $this->stats['deleted'] = count($productIdsToDelete);
        }
    }

    /**
     * Delete Shopify products from local database
     */
    private function deleteShopifyProductsFromDb(array $productIds): void
    {
        $shopifyProducts = ShopifyProduct::whereIn('product_id', $productIds)->with('variants')->get();

        foreach ($shopifyProducts as $shopifyProduct) {
            try {
                DB::transaction(function () use ($shopifyProduct) {
                    // Delete related inventory levels
                    foreach ($shopifyProduct->variants as $variant) {
                        ShopifyInventoryLevel::where('inventory_item_id', $variant->inventory_item_id)->delete();
                    }

                    // Delete the product (variants will be deleted via cascade)
                    $shopifyProduct->forceDelete();
                });

                $this->info("   • Deleted product: {$shopifyProduct->product_id}");
                Log::info("Deleted Shopify product from local DB: {$shopifyProduct->product_id}");
            } catch (\Exception $e) {
                $this->error("❌ Failed to delete product {$shopifyProduct->product_id}: " . $e->getMessage());
                Log::error("Failed to delete Shopify product: " . $e->getMessage(), ['product_id' => $shopifyProduct->product_id]);
            }
        }
    }

    /**
     * Get inventory levels (keeping existing REST logic for now)
     */
    private function getInventoryLevels(): void
    {
        try {
            // Use existing REST API for inventory levels as GraphQL inventory is more complex
            $session = (new ShopifyService)->getSession();
            $restClient = new \Shopify\Clients\Rest($session->getShop(), $session->getAccessToken());

            $location = ShopifyLocation::first();
            if (!$location) {
                $this->warn("⚠️  No locations found, skipping inventory sync");
                return;
            }

            $nextPage = true;
            $getNextPageQuery = [];
            $inventoryCount = 0;

            while ($nextPage) {
                if (empty($getNextPageQuery)) {
                    $params = ['location_ids' => $location->location_id, 'limit' => 250];
                } else {
                    $params = $getNextPageQuery;
                }

                $response = $restClient->get(path: 'inventory_levels', query: $params);
                $this->stats['api_calls']++;

                $body = $response->getDecodedBody();

                if (!empty($body) && isset($body['inventory_levels']) && count($body['inventory_levels']) > 0) {
                    foreach ($body['inventory_levels'] as $inventoryLevelData) {
                        try {
                            if (!$this->option('dry-run')) {
                                (new ShopifyService)->saveInventoryLevelToDb($inventoryLevelData);
                            }
                            $inventoryCount++;
                        } catch (\Exception $e) {
                            $this->error("❌ Failed to save inventory level: " . $e->getMessage());
                        }
                    }
                }

                try {
                    /** @var \Shopify\Clients\PageInfo|null $pageInfo */
                    $pageInfo = $response->getPageInfo();

                    if ($pageInfo && $pageInfo->hasNextPage()) {
                        $getNextPageQuery = $pageInfo->getNextPageQuery();
                        usleep(2000000); // 2 second delay
                    } else {
                        $nextPage = false;
                    }
                } catch (\Exception $e) {
                    $this->warn("⚠️  Pagination info not available, ending inventory sync");
                    $nextPage = false;
                }
            }

            $this->info("✅ Synced $inventoryCount inventory levels");
        } catch (\Exception $e) {
            $this->error("❌ Failed to sync inventory levels: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Display final statistics
     */
    private function displayStatistics(): void
    {
        $this->info("");
        $this->info("📊 Sync Statistics:");
        $this->info("   • Products synced: " . $this->stats['synced']);
        $this->info("   • Products created: " . $this->stats['created']);
        $this->info("   • Products deleted: " . $this->stats['deleted']);
        $this->info("   • API calls made: " . $this->stats['api_calls']);
        $this->info("   • Errors encountered: " . $this->stats['errors']);
        $this->info("");

        if ($this->stats['errors'] > 0) {
            $this->warn("⚠️  Completed with {$this->stats['errors']} errors. Check logs for details.");
        } else {
            $this->info("✅ Sync completed successfully!");
        }
    }
}
