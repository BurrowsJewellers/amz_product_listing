<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\Brand;
use App\Models\RetailEdgeProduct;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyProductMetafield;
use App\Models\ShopifyProductVariant;
use App\Models\ShopifyProductVariantMetafield;
use App\Services\MetafieldAssignmentService;
use App\Services\ShopifyService;
use App\Services\SyncLogger;
use App\Traits\ShopifyCleanupTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;

class CreateProduct extends Command
{
    use ShopifyCleanupTrait;

    /**
     * uploaded_to_shopify value for children that could not be created as distinct
     * Shopify variants (option-collapse: their distinguishing attribute is missing from
     * the variant-option source data). Flags them for a source-data fix without falsely
     * reporting them as synced (1) or as a transient failure (2), and keeps them out of
     * the create-pending query so they don't churn every run.
     */
    public const STATUS_NEEDS_REVIEW = 3;

    /**
     * Sync logger for operation tracking
     */
    private SyncLogger $syncLogger;

    /**
     * Store last API context for error logging
     */
    private array $lastApiContext = [];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyCreateProduct
                            {--sku= : Only process this single parent SKU (for controlled/manual runs)}
                            {--limit= : Stop after processing this many products (for controlled/manual runs)}';

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

        // Acquire lock using new locking system
        $job = SyncJobController::acquireLock($jobType, $marketplace);
        if (! $job) {
            $this->warn('Job is already running or paused.');
            Log::info("$marketplace $jobType: Cannot acquire lock (running or paused)");

            return Command::SUCCESS;
        }

        try {
            Log::info("$marketplace $jobType started!");
            $product_errors_occurred = false;

            // Initialize sync logger for operation tracking
            $this->syncLogger = new SyncLogger;

            // Pending parent/standalone products are selected via pendingParentBaseQuery()
            // so the count and the iteration share one definition (see methods below).
            $session = (new ShopifyService)->getSession();
            $variantTypes = ['vt1' => 'Size', 'vt2' => 'Color', 'vt3' => 'Material', 'vt4' => 'Style'];

            $brands = Brand::all();

            $brandsArray = [];

            foreach ($brands as $brand) {
                $brandsArray[$brand->brand_id]['id'] = $brand->id;
                $brandsArray[$brand->brand_id]['name'] = $brand->name;
            }

            // Optional controlled-run constraints (default: process everything).
            $onlySku = $this->option('sku') ?: null;
            $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

            $count = $this->pendingParentBaseQuery()
                ->when($onlySku, fn ($q) => $q->where('retail_edge_products.sku', $onlySku))
                ->count();

            // Initialize GraphQL client
            $client = new Graphql($session->getShop(), $session->getAccessToken());

            // Process each pending parent at most once. Tracking handled IDs guarantees
            // the loop terminates even when a parent cannot be removed from the query by
            // our writes — the root cause of the prior infinite loop on a parent whose
            // children only partially exist in Shopify.
            $processedIds = [];

            while ($product = $this->nextPendingProduct($processedIds, $onlySku)) {
                if ($limit !== null && count($processedIds) >= $limit) {
                    $this->info("Reached --limit={$limit}; stopping this run.");
                    break;
                }

                $processedIds[] = $product->id;
                $this->info('Pending: '.$count.' | processed this run: '.count($processedIds)." (latest: {$product->sku})");

                if ($product) {
                    // Defensive check: skip if this is somehow a child product
                    if ($product->old_key !== $product->sku && $product->old_key !== '') {
                        Log::warning('CreateProduct: Skipping child product that slipped through', [
                            'sku' => $product->sku,
                            'old_key' => $product->old_key,
                        ]);
                        $product->update(['uploaded_to_shopify' => 1]); // Mark to prevent reprocessing

                        continue;
                    }
                    $this->info('======================================');
                    $this->info("Processing Product: {$product->title} (SKU: {$product->sku})");

                    // If any children are flagged as already in Shopify, reconcile against
                    // the live store: add missing variants to a still-live product, or clean
                    // up a stale mirror (deleted product) and fall through to recreate.
                    if ($product->children->isNotEmpty()) {
                        $childSkus = $product->children->pluck('sku')->toArray();
                        $existingChildSkus = ShopifyProductVariant::whereIn('sku', $childSkus)->pluck('sku')->toArray();

                        if (! empty($existingChildSkus)) {
                            $outcome = $this->reconcileExistingChildren($product, $existingChildSkus, $client);

                            // 'added'    -> variants added to a still-live product; done with this parent
                            // 'skip'     -> transient error verifying the product; retry on a later run
                            // 'recreate' -> stale mirror was cleaned up; fall through and create it fresh
                            if ($outcome !== 'recreate') {
                                $job->updateHeartbeat();

                                continue;
                            }
                        }
                    }

                    try {
                        // Create product using GraphQL
                        $createdProductData = $this->createProductWithGraphQL($product, $client);

                        if ($createdProductData) {
                            // Log product creation success
                            $this->syncLogger->logSuccess(
                                SyncLogger::MARKETPLACE_SHOPIFY,
                                'shopifyCreateProduct',
                                $product->sku,
                                SyncLogger::OP_PRODUCT_CREATE,
                                [
                                    'item_title' => $product->title,
                                    'to_value' => $createdProductData['id'],
                                    'message' => "Product created successfully with ID: {$createdProductData['id']}",
                                    'shopify_product_id' => $this->extractIdFromGid($createdProductData['id']),
                                    'context_data' => [
                                        'children_count' => $product->children->count(),
                                        'brand' => $product->brand?->name,
                                    ],
                                ]
                            );

                            // Handle metafields after creation (same as UpdateProduct)
                            $this->handleMetafieldsAfterCreation($product, $createdProductData, $client);

                            // Save product to database
                            $this->saveProductToDatabase($createdProductData, $product);

                            // Mark only the children that actually became Shopify variants;
                            // flag the rest for review instead of falsely marking them synced.
                            $marked = $this->reconcileChildrenAfterCreate($product, $createdProductData);
                            $this->line('Marked '.count($marked['created']).' child variant(s) as uploaded for '.$product->sku);

                            if (! empty($marked['blocked'])) {
                                $this->warn("⚠️  {$product->sku}: ".count($marked['blocked']).' child(ren) could not be created as distinct variants (option collapse) — flagged for review: '.implode(', ', $marked['blocked']));
                                Log::warning('CreateProduct: children could not be created as distinct variants; flagged for review', [
                                    'parent_sku' => $product->sku,
                                    'created' => $marked['created'],
                                    'blocked' => $marked['blocked'],
                                ]);
                                $this->syncLogger->logFailure(
                                    SyncLogger::MARKETPLACE_SHOPIFY,
                                    'shopifyCreateProduct',
                                    $product->sku,
                                    SyncLogger::OP_PRODUCT_CREATE,
                                    count($marked['blocked']).' child(ren) collapsed to an existing variant option and were not created; source data needs a distinguishing variant attribute',
                                    [
                                        'item_title' => $product->title,
                                        'context_data' => [
                                            'created_skus' => $marked['created'],
                                            'blocked_skus' => $marked['blocked'],
                                        ],
                                    ]
                                );
                            }

                            $this->info("Successfully created product: {$product->title}");
                        } else {
                            throw new \Exception('Product creation returned null data');
                        }
                    } catch (\Exception $e) {
                        $product_errors_occurred = true;
                        $errorMessage = $e->getMessage();

                        // Log product creation failure
                        $this->syncLogger->logFailure(
                            SyncLogger::MARKETPLACE_SHOPIFY,
                            'shopifyCreateProduct',
                            $product->sku,
                            SyncLogger::OP_PRODUCT_CREATE,
                            $e,
                            [
                                'item_title' => $product->title,
                                'api_request' => $this->lastApiContext['api_request'] ?? $this->buildProductInput($product),
                                'api_response' => $this->lastApiContext['api_response'] ?? null,
                                'errors' => array_merge(
                                    $this->lastApiContext['user_errors'] ?? [],
                                    $this->lastApiContext['graphql_errors'] ?? []
                                ),
                                'context_data' => [
                                    'sku' => $product->sku,
                                    'title' => $product->title,
                                    'children_count' => $product->children->count(),
                                    'brand' => $product->brand?->name,
                                    'quantity' => $product->quantity,
                                ],
                            ]
                        );

                        // Clear API context after logging
                        $this->lastApiContext = [];

                        // Error already logged via SyncLogger above
                        $this->error("Failed to create product {$product->sku}: {$errorMessage}");

                        // Mark children as failed
                        foreach ($product->children as $child) {
                            $child->update(['uploaded_to_shopify' => 2]);
                        }

                        // Mark parent as failed too
                        $product->update(['uploaded_to_shopify' => 2]);
                    }

                    usleep(1500000); // 1.5 second delay
                    $job->updateHeartbeat(); // Update heartbeat after each product
                }
            }

            if ($product_errors_occurred) {
                $job->finishJob('Completed with one or more product creation errors.');
            } else {
                $job->finishJob();
            }

            Log::info("$marketplace $jobType finished!");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $job->finishJob($e->getMessage());
            report($e);
            $this->error($e->getMessage());
            Log::error("$marketplace $jobType failed: ".$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Base query for pending parent/standalone products that need a Shopify product.
     *
     * Conditions: SKU not yet in Shopify, parent/standalone (old_key = sku or empty),
     * in stock, and either has at least one pending child or no children at all.
     */
    protected function pendingParentBaseQuery()
    {
        return RetailEdgeProduct::query()
            ->whereNotExists(function ($subquery) {
                $subquery->select(DB::raw(1))
                    ->from('shopify_product_variants')
                    ->whereColumn('shopify_product_variants.sku', 'retail_edge_products.sku');
            })
            ->where(function ($q) {
                $q->whereColumn('old_key', 'sku')
                    ->orWhere('old_key', '');
            })
            ->where('quantity', '>', 0)
            ->where(function ($q) {
                $q->whereHas('children', fn ($c) => $c->where('uploaded_to_shopify', 0))
                    ->orWhereDoesntHave('children');
            });
    }

    /**
     * Fetch the next pending parent to process, excluding ones already handled this run.
     *
     * Excluding handled IDs guarantees the create loop terminates: each parent is
     * returned at most once even if our writes cannot remove it from the base query.
     */
    public function nextPendingProduct(array $excludeIds = [], ?string $onlySku = null): ?RetailEdgeProduct
    {
        return $this->pendingParentBaseQuery()
            ->when($excludeIds, fn ($q) => $q->whereNotIn('retail_edge_products.id', $excludeIds))
            ->when($onlySku, fn ($q) => $q->where('retail_edge_products.sku', $onlySku))
            ->with(['brand', 'children'])
            ->first();
    }

    /**
     * After a product create, mark each child uploaded only if its SKU actually became a
     * variant on the created product; flag the rest as STATUS_NEEDS_REVIEW. This avoids
     * falsely reporting children as synced when they collapse to the same variant option
     * (their distinguishing attribute is missing from the variant-option source data).
     * The parent is marked uploaded only when every child resolved.
     *
     * @return array{created: array<int, string>, blocked: array<int, string>}
     */
    public function reconcileChildrenAfterCreate(RetailEdgeProduct $product, array $createdProductData): array
    {
        $liveSkus = collect($createdProductData['variants']['edges'] ?? [])
            ->pluck('node.sku')
            ->filter()
            ->all();

        $created = [];
        $blocked = [];

        foreach ($product->children as $child) {
            if (in_array($child->sku, $liveSkus, true)) {
                $child->update(['uploaded_to_shopify' => 1]);
                $created[] = $child->sku;
            } else {
                $child->update(['uploaded_to_shopify' => self::STATUS_NEEDS_REVIEW]);
                $blocked[] = $child->sku;
            }
        }

        $product->update(['uploaded_to_shopify' => empty($blocked) ? 1 : self::STATUS_NEEDS_REVIEW]);

        return ['created' => $created, 'blocked' => $blocked];
    }

    /**
     * Reconcile a parent whose children are flagged as already existing in Shopify.
     *
     * The local mirror can be stale (the Shopify product was deleted but the
     * shopify_product_variants rows remain), so verify against the live store:
     *  - live     -> add the missing children as variants; returns 'added'
     *  - gone     -> clean up the orphaned local rows; returns 'recreate' (the caller
     *                then creates the product fresh)
     *  - error    -> transient failure verifying; returns 'skip' (retry next run —
     *                never delete the mirror on an unconfirmed result)
     *
     * @return string one of 'added' | 'recreate' | 'skip'
     */
    private function reconcileExistingChildren(RetailEdgeProduct $product, array $existingChildSkus, $client): string
    {
        // Resolve the Shopify product from a child that claims to be synced.
        $existingVariant = ShopifyProductVariant::whereIn('sku', $existingChildSkus)
            ->whereNotNull('product_id')
            ->first();

        if (! $existingVariant || empty($existingVariant->product_id)) {
            // Mirror references children but no product id — treat as stale and recreate.
            $this->warn("⚠️  {$product->sku}: child variants present without a product id — cleaning stale mirror");
            $this->cleanupStaleMirrorFor($existingChildSkus);

            return 'recreate';
        }

        $productGid = 'gid://shopify/Product/'.$existingVariant->product_id;
        $state = $this->classifyProductFetch($this->fetchProductBody($productGid, $client));

        if ($state === 'error') {
            Log::warning('CreateProduct: could not verify existing Shopify product; skipping this run', [
                'parent_sku' => $product->sku,
                'product_gid' => $productGid,
            ]);
            $this->warn("⏭️  {$product->sku}: could not verify {$productGid} (transient); will retry next run");

            return 'skip';
        }

        if ($state === 'gone') {
            // Stale mirror: the Shopify product was deleted. Drop the orphaned local
            // rows (this also resets the children's uploaded flag) and recreate.
            $this->warn("♻️  {$product->sku}: existing Shopify product {$productGid} no longer exists — cleaning stale mirror and recreating");
            $this->cleanupStaleMirrorFor($existingChildSkus);

            $this->syncLogger->logSuccess(
                SyncLogger::MARKETPLACE_SHOPIFY,
                'shopifyCreateProduct',
                $product->sku,
                SyncLogger::OP_DUPLICATE_CLEANUP,
                [
                    'item_title' => $product->title,
                    'message' => 'Cleaned up stale local mirror (Shopify product no longer exists); recreating',
                    'shopify_product_id' => $this->extractIdFromGid($productGid),
                    'context_data' => ['stale_child_skus' => $existingChildSkus],
                ]
            );

            return 'recreate';
        }

        // Live product: add only the missing children as variants.
        $existingProductData = $this->getProductData($productGid, $client);
        if (! $existingProductData) {
            // Defensive: classified live but the full fetch failed — do not delete, retry later.
            return 'skip';
        }

        // createProductVariants() skips option-combinations that already exist on the
        // product, so only the missing children are added. Returns created SKUs.
        $createdSkus = $this->createProductVariants($existingProductData, $product, $client);

        // Existing children are already in Shopify; newly created ones now are too.
        $resolvedSkus = array_values(array_unique(array_merge($existingChildSkus, $createdSkus)));
        $product->children()->whereIn('sku', $resolvedSkus)->update(['uploaded_to_shopify' => 1]);

        // Any child that couldn't be resolved into a variant (option collapse) is flagged
        // for review rather than left pending to churn every run.
        $blocked = $product->children->pluck('sku')
            ->reject(fn ($sku) => in_array($sku, $resolvedSkus, true))
            ->values()
            ->all();
        if (! empty($blocked)) {
            $product->children()->whereIn('sku', $blocked)->update(['uploaded_to_shopify' => self::STATUS_NEEDS_REVIEW]);
            Log::warning('CreateProduct: children could not be added as distinct variants to existing product; flagged for review', [
                'parent_sku' => $product->sku,
                'product_gid' => $productGid,
                'blocked' => $blocked,
            ]);
        }

        // Parent done only when every child resolved; otherwise it is flagged too.
        $product->update(['uploaded_to_shopify' => empty($blocked) ? 1 : self::STATUS_NEEDS_REVIEW]);

        $this->syncLogger->logSuccess(
            SyncLogger::MARKETPLACE_SHOPIFY,
            'shopifyCreateProduct',
            $product->sku,
            SyncLogger::OP_PRODUCT_CREATE,
            [
                'item_title' => $product->title,
                'to_value' => $productGid,
                'message' => 'Added '.count($createdSkus).' missing variant(s) to existing Shopify product',
                'shopify_product_id' => $this->extractIdFromGid($productGid),
                'context_data' => [
                    'created_skus' => $createdSkus,
                    'already_present' => $existingChildSkus,
                    'blocked_skus' => $blocked,
                ],
            ]
        );
        $this->info("✅ {$product->sku}: added ".count($createdSkus)." variant(s) to {$productGid}");

        return 'added';
    }

    /**
     * Fetch a product's GraphQL body for an existence check, returning null if the
     * request threw. Kept separate from classification so the decision stays pure.
     */
    private function fetchProductBody(string $productGid, $client): ?array
    {
        try {
            $response = $client->query([
                'query' => 'query($id: ID!){ product(id: $id){ id } }',
                'variables' => ['id' => $productGid],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Throwable $e) {
            Log::warning('CreateProduct: product existence check threw: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Classify a product-existence GraphQL response.
     *
     * @return string 'live' (product present), 'gone' (query ok but product null),
     *                or 'error' (no body / GraphQL errors / malformed) — callers must
     *                never delete local data on 'error'.
     */
    public function classifyProductFetch(?array $resultBody): string
    {
        if ($resultBody === null) {
            return 'error';
        }
        if (! empty($resultBody['errors'])) {
            return 'error';
        }
        if (! array_key_exists('data', $resultBody)) {
            return 'error';
        }

        return ($resultBody['data']['product'] ?? null) === null ? 'gone' : 'live';
    }

    /**
     * Force-delete the local Shopify mirror rows for the given child SKUs whose backing
     * Shopify product no longer exists, resetting their RetailEdge uploaded flag so the
     * product can be recreated. Reuses ShopifyCleanupTrait::cleanupStaleVariant().
     */
    private function cleanupStaleMirrorFor(array $childSkus): void
    {
        ShopifyProductVariant::whereIn('sku', $childSkus)
            ->get()
            ->each(fn ($variant) => $this->cleanupStaleVariant($variant, 'CreateProduct'));
    }

    /**
     * Create product using GraphQL API
     */
    private function createProductWithGraphQL(RetailEdgeProduct $product, $client): ?array
    {
        // Build product input for GraphQL
        $productInput = $this->buildProductInput($product);

        $mutation = <<<'GRAPHQL'
        mutation productCreate($product: ProductCreateInput!) {
          productCreate(product: $product) {
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

        $this->line('Executing GraphQL productCreate mutation...');
        $response = $client->query(['query' => $mutation, 'variables' => ['product' => $productInput]]);
        $resultBody = json_decode($response->getBody()->getContents(), true);

        // Store API context for error logging
        $this->lastApiContext = [
            'api_request' => $productInput,
            'api_response' => $resultBody,
            'user_errors' => $resultBody['data']['productCreate']['userErrors'] ?? [],
            'graphql_errors' => $resultBody['errors'] ?? [],
        ];

        // Handle errors
        $errors = $this->handleGraphQLErrors($resultBody);
        if (! empty($errors)) {
            throw new \Exception('GraphQL Errors: '.implode(' | ', $errors));
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
            if (! in_array('Pandora', $productTags)) {
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
                $vts = array_filter(array_map('trim', array_map('strtolower', explode('-', $child->id3))));

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
                            if ($child->s_cat == 'Bracelets') {
                                // TODO - Aman
                                // <a:ItemISD>
                                // <a:Index>6</a:Index>
                                // <a:Name>Style</a:Name>
                                // <a:Value>Letter T</a:Value>
                            } else {
                                $variantTypeValue = $child->pendant_style;
                            }
                        }

                        if (! empty($variantTypeValue)) {
                            if (! isset($variantOptions[$variantType])) {
                                $variantOptions[$variantType] = [];
                            }
                            if (! in_array($variantTypeValue, $variantOptions[$variantType])) {
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
                'values' => $values,
            ];
        }

        return $productOptions;
    }

    /**
     * Create product variants (with duplicate detection)
     */
    private function createProductVariants(array $createdProduct, RetailEdgeProduct $product, $client): array
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
            $vts = array_filter(array_map('trim', array_map('strtolower', explode('-', $child->id3))));

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

                    if (! empty($variantTypeValue)) {
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

        if (! empty($variants)) {
            return $this->createVariantsBulk($variants, $client, $createdProduct);
        }

        $this->line('No new variants to create - all option combinations already exist');

        return [];
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
    private function createVariantsBulk(array $variants, $client, array $createdProduct): array
    {
        $this->line('Creating '.count($variants).' variants using bulk creation...');

        return $this->createVariantsIndividually($variants, $client, $createdProduct);
    }

    /**
     * Create variants using productVariantsBulkCreate (2025-01 API).
     *
     * @return array<int, string> SKUs of the variants successfully created
     */
    private function createVariantsIndividually(array $variants, $client, array $createdProduct): array
    {
        $this->line('Using productVariantsBulkCreate for variant creation...');

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
            if (! empty($variant['compareAtPrice']) && $variant['compareAtPrice'] !== $variant['price']) {
                $bulkVariant['compareAtPrice'] = $variant['compareAtPrice'];
            }

            // Add inventory item with SKU (correct field structure)
            $bulkVariant['inventoryItem'] = [
                'sku' => $variant['sku'],
                'tracked' => true,
            ];

            // Add option values if they exist (using optionId from created product)
            if (! empty($variant['optionValues'])) {
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

        $mutation = <<<'GRAPHQL'
        mutation productVariantsBulkCreate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
          productVariantsBulkCreate(productId: $productId, variants: $variants) {
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
                    'variants' => $bulkVariants,
                ],
            ]);
            $resultBody = json_decode($response->getBody()->getContents(), true);

            $userErrors = $resultBody['data']['productVariantsBulkCreate']['userErrors'] ?? ($resultBody['errors'] ?? []);
            if (! empty($userErrors)) {
                foreach ($userErrors as $error) {
                    $this->error("Bulk variant creation error: {$error['message']} ".(isset($error['field']) ? json_encode($error['field']) : ''));
                }

                return [];
            }

            $createdVariants = $resultBody['data']['productVariantsBulkCreate']['productVariants'] ?? [];
            $this->info('Successfully created '.count($createdVariants).' variants using bulk creation');

            $createdSkus = [];
            foreach ($createdVariants as $variant) {
                $this->line("Created variant: {$variant['sku']} (ID: {$variant['id']})");
                if (! empty($variant['sku'])) {
                    $createdSkus[] = $variant['sku'];
                }
            }

            return $createdSkus;
        } catch (\Exception $e) {
            $this->error('Exception during bulk variant creation: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Get option ID by index from created product
     */
    private function getOptionIdByIndex(array $createdProduct, int $index): ?string
    {
        if (! isset($createdProduct['options'])) {
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
        $metafieldService = new MetafieldAssignmentService;
        $assignment = $metafieldService->determineMetafieldAssignment($product);

        $this->line("Metafield assignment type: {$assignment['type']} for Product: {$product->sku}");

        $metafieldsToSet = [];

        // Handle product-level metafields (SAME as UpdateProduct)
        if (! empty($assignment['product_metafields'])) {
            $this->line('Processing '.count($assignment['product_metafields']).' product-level metafields');
            foreach ($assignment['product_metafields'] as $metafield) {
                $shopifyMetafieldDef = ShopifyMetafield::where('name', $metafield['isd_name'])
                    ->where('owner_type', 'PRODUCT')
                    ->first();

                if ($shopifyMetafieldDef && ! empty($metafield['value'])) {
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
        if (! empty($assignment['variant_metafields'])) {
            foreach ($assignment['variant_metafields'] as $sku => $metafields) {
                // Find variant ID from created product data
                $variantId = $this->findVariantIdBySku($createdProductData, $sku);
                if (! $variantId) {
                    $this->warn("Could not find variant ID for SKU: {$sku}");

                    continue;
                }

                $this->line('Processing '.count($metafields)." variant-level metafields for SKU: {$sku}");
                foreach ($metafields as $metafield) {
                    $shopifyMetafieldDef = ShopifyMetafield::where('name', $metafield['isd_name'])
                        ->where('owner_type', 'PRODUCTVARIANT')
                        ->first();

                    if ($shopifyMetafieldDef && ! empty($metafield['value'])) {
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

        // design_number_variant — full RetailEdge real_design_number per variant
        $designDef = ShopifyMetafield::where('namespace', 'custom')
            ->where('key', 'design_number_variant')
            ->where('owner_type', 'PRODUCTVARIANT')
            ->first();

        if ($designDef) {
            $variantSkus = $product->children->isNotEmpty()
                ? $product->children->pluck('sku', 'sku')
                : collect([$product->sku => $product->sku]);

            foreach ($variantSkus as $variantSku) {
                $variantId = $this->findVariantIdBySku($createdProductData, $variantSku);
                $variantRep = RetailEdgeProduct::where('sku', $variantSku)->first();

                if (! $variantId || ! $variantRep || empty($variantRep->real_design_number)) {
                    continue;
                }

                $metafieldsToSet[] = [
                    'ownerId' => $variantId,
                    'namespace' => $designDef->namespace,
                    'key' => $designDef->key,
                    'type' => $designDef->type,
                    'value' => (string) $variantRep->real_design_number,
                ];
                $this->line("Added design_number_variant: {$variantSku} = {$variantRep->real_design_number}");
            }
        } else {
            $this->warn('design_number_variant definition not found in shopify_metafields. Run shopify:create-metafield-definitions.');
        }

        // Batch process metafields in chunks of 250 (Shopify's limit)
        if (! empty($metafieldsToSet)) {
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
        if (! isset($productData['variants']['edges'])) {
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
        if (! empty($resultBody['data']['productCreate']['userErrors'])) {
            foreach ($resultBody['data']['productCreate']['userErrors'] as $error) {
                $errors[] = "Field '{$error['field']}': {$error['message']}";
            }
        }

        // Handle GraphQL errors (system-level)
        if (! empty($resultBody['errors'])) {
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
            $shopifyService = new ShopifyService;

            // Convert GraphQL response to format expected by saveProductToDb
            $restFormatProduct = $this->convertGraphQLToRestFormat($productData, $product);

            $shopifyService->saveProductToDb($restFormatProduct);

            $this->info("Product saved to database: {$product->title}");
        } catch (\Exception $e) {
            $this->warn('Failed to save product to database: '.$e->getMessage());
            Log::warning("Failed to save product to database for SKU {$product->sku}: ".$e->getMessage());
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
        $designNumber = explode('-', (string) $product->real_design_number)[0];

        if ($designNumber !== '') {
            $mktDescription .= ' - Design number: '.$designNumber;
        }

        return $mktDescription;
    }

    /**
     * Get updated product data
     */
    private function getProductData(string $productId, $client): ?array
    {
        $query = <<<'GRAPHQL'
        query getProduct($id: ID!) {
          product(id: $id) {
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
            $this->warn('Failed to fetch updated product data: '.$e->getMessage());

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
                    foreach (explode(',', $propValue) as $tempTag) {
                        $tags[] = $value.'_'.trim($tempTag);
                    }
                }
            }

            // Add id2 tags if they exist
            if (! empty($product->id2) && $product->id2 !== 'N/A') {
                foreach (explode(',', $product->id2) as $id2Value) {
                    $trimmedValue = trim($id2Value);
                    if ($trimmedValue !== '') {
                        $tags[] = $trimmedValue;
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

                if (! $inputMetafield) {
                    continue;
                }

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
            $this->warn("Failed to save metafields to database for product {$product->sku}: ".$e->getMessage());
            Log::warning("Failed to save metafields to database for product {$product->sku}: ".$e->getMessage());
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

        $this->line("Processing {$totalMetafields} metafields in ".count($batches)." batches of {$batchSize} for product: {$product->sku}");

        $metafieldsSetMutation = <<<'GRAPHQL'
        mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
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
            $this->line("Processing batch {$batchNumber}/".count($batches).' ('.count($batch).' metafields)');

            try {
                $response = $client->query(['query' => $metafieldsSetMutation, 'variables' => ['metafields' => $batch]]);
                $resultBody = json_decode($response->getBody()->getContents(), true);

                $userErrors = $resultBody['data']['metafieldsSet']['userErrors'] ?? ($resultBody['errors'] ?? []);
                if (! empty($userErrors)) {
                    foreach ($userErrors as $error) {
                        $failedMetafieldIndex = $error['elementIndex'] ?? 'N/A';
                        $failedMetafield = ($failedMetafieldIndex !== 'N/A' && isset($batch[$failedMetafieldIndex])) ? $batch[$failedMetafieldIndex]['key'] : 'unknown';
                        $this->error("Shopify MetafieldsSet API Error in batch {$batchNumber} (Metafield: {$failedMetafield}): {$error['message']}");
                        $totalFailed++;
                    }

                    // Log batch error
                    $this->syncLogger->logFailure(
                        SyncLogger::MARKETPLACE_SHOPIFY,
                        'shopifyCreateProduct',
                        $product->sku,
                        SyncLogger::OP_METAFIELD_UPDATE,
                        "Batch {$batchNumber} failed with ".count($userErrors).' errors',
                        [
                            'item_title' => $product->title,
                            'errors' => $userErrors,
                        ]
                    );
                } else {
                    $createdMetafields = $resultBody['data']['metafieldsSet']['metafields'] ?? [];
                    $batchSuccessful = count($createdMetafields);
                    $totalSuccessful += $batchSuccessful;
                    $this->info("Batch {$batchNumber} successful: {$batchSuccessful} metafields created");

                    // Store result body for database saving with correct batch offset
                    $allResultBodies[] = [
                        'resultBody' => $resultBody,
                        'batch' => $batch,
                        'batchOffset' => $batchIndex * $batchSize,
                    ];
                }

                // Small delay between batches to avoid rate limiting
                if ($batchNumber < count($batches)) {
                    usleep(500000); // 0.5 second delay
                }
            } catch (\Exception $e) {
                $this->error("Exception during metafieldsSet batch {$batchNumber} for product {$product->sku}: ".$e->getMessage());
                $totalFailed += count($batch);

                // Log batch exception
                $this->syncLogger->logFailure(
                    SyncLogger::MARKETPLACE_SHOPIFY,
                    'shopifyCreateProduct',
                    $product->sku,
                    SyncLogger::OP_METAFIELD_UPDATE,
                    $e,
                    [
                        'item_title' => $product->title,
                        'message' => "Batch {$batchNumber} exception: ".$e->getMessage(),
                    ]
                );
            }
        }

        // Save all successful metafields to local database
        if (! empty($allResultBodies)) {
            foreach ($allResultBodies as $batchData) {
                $this->saveMetafieldsToDatabase($batchData['resultBody'], $batchData['batch'], $product);
            }
        }

        // Final summary
        $this->info("Metafield processing complete: {$totalSuccessful} successful, {$totalFailed} failed out of {$totalMetafields} total");

        // Log final summary
        $status = $totalFailed > 0 ? SyncLogger::STATUS_FAILED : SyncLogger::STATUS_SUCCESS;
        $this->syncLogger->log(
            SyncLogger::MARKETPLACE_SHOPIFY,
            'shopifyCreateProduct',
            $product->sku,
            SyncLogger::OP_METAFIELD_UPDATE,
            $status,
            [
                'item_title' => $product->title,
                'from_value' => '0',
                'to_value' => "{$totalSuccessful}_of_{$totalMetafields}",
                'message' => "Metafield batch processing complete: {$totalSuccessful} successful, {$totalFailed} failed",
            ]
        );
    }

    /**
     * Update the first variant's SKU if it's empty
     * For standalone products (no children), uses the product itself as the variant source
     */
    private function updateFirstVariantSku(array $createdProduct, RetailEdgeProduct $product, $client): void
    {
        if (! isset($createdProduct['variants']['edges'][0])) {
            return;
        }

        $firstVariant = $createdProduct['variants']['edges'][0]['node'];

        // For standalone products (no children), use the product itself as the variant source
        $firstChild = $product->children->first();
        $variantSource = $firstChild ?? $product;

        // Check if the first variant has an empty SKU
        if (empty($firstVariant['sku'])) {
            $this->line("Updating first variant SKU from empty to: {$variantSource->sku}");

            // Calculate prices for the first variant
            $retailPrices = [$variantSource->retail_price1, $variantSource->retail_price2];
            $prices = array_filter(array_map('floatval', $retailPrices), function ($price) {
                return $price > 0;
            });

            $price = empty($prices) ? 0 : min($prices);
            $compareAtPrice = empty($prices) ? 0 : max($prices);

            $variantInput = [
                'id' => $firstVariant['id'],
                'price' => (string) $price,
                'compareAtPrice' => ($price == $compareAtPrice) ? null : (string) $compareAtPrice,
                'barcode' => $variantSource->barcode,
                'inventoryItem' => [
                    'sku' => $variantSource->sku,
                    'tracked' => true,
                ],
                'inventoryPolicy' => 'DENY',
                'taxable' => true,
            ];

            $mutation = <<<'GRAPHQL'
            mutation productVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
              productVariantsBulkUpdate(productId: $productId, variants: $variants) {
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
                        'variants' => [$variantInput],
                    ],
                ]);
                $resultBody = json_decode($response->getBody()->getContents(), true);

                $userErrors = $resultBody['data']['productVariantsBulkUpdate']['userErrors'] ?? ($resultBody['errors'] ?? []);
                if (! empty($userErrors)) {
                    foreach ($userErrors as $error) {
                        $this->error("First variant SKU update error: {$error['message']}");
                    }
                } else {
                    $this->info("Successfully updated first variant SKU to: {$variantSource->sku}");
                }
            } catch (\Exception $e) {
                $this->error('Exception updating first variant SKU: '.$e->getMessage());
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

            $query = <<<'GRAPHQL'
            query getVariant($id: ID!) {
              productVariant(id: $id) {
                id
                sku
              }
            }
            GRAPHQL;

            $response = $client->query(['query' => $query, 'variables' => ['id' => $variantGid]]);
            $resultBody = json_decode($response->getBody()->getContents(), true);

            $variant = $resultBody['data']['productVariant'] ?? null;
            if ($variant && ! empty($variant['sku'])) {
                return $variant['sku'];
            }
        } catch (\Exception $e) {
            // If GraphQL query fails, fall back to the original logic
            Log::warning("Failed to query variant SKU for GID {$variantGid}: ".$e->getMessage());
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
