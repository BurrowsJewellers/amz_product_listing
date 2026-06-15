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
use App\Services\Shopify\VariantSet;
use App\Services\Shopify\VariantSetBuilder;
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
     * Build a product (with all its variants) in a single productSet call when the
     * family has fewer than this many variants; at or above it, productSet must run
     * asynchronously and the result is polled. The largest current family is 78.
     */
    public const PRODUCTSET_SYNC_MAX = 100;

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
                            {--limit= : Stop after processing this many products (for controlled/manual runs)}
                            {--dry-run : With --sku, print what productSet would receive for that family without calling Shopify or writing to the DB}';

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

        // Dry run: read-only preview of one family. No lock, no Shopify call, no DB write.
        if ($this->option('dry-run')) {
            return $this->runDryRun();
        }

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

                            // Mark only the rows (parent + children) that actually became
                            // Shopify variants; flag the rest for review instead of falsely
                            // marking them synced.
                            $marked = $this->markFlagsFromProductSet($product, $createdProductData);
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
     * Read-only preview for a single family: builds the variant set and prints exactly
     * what productSet would receive, plus which path (fresh create vs sync-existing) and
     * which rows would be flagged for review. Never calls Shopify, never writes the DB.
     */
    private function runDryRun(): int
    {
        $sku = $this->option('sku');
        if (! $sku) {
            $this->error('--dry-run requires --sku=<sku> (a parent or child SKU in the family).');

            return Command::FAILURE;
        }

        $row = RetailEdgeProduct::where('sku', $sku)->first();
        if (! $row) {
            $this->error("SKU {$sku} not found in retail_edge_products.");

            return Command::FAILURE;
        }

        // Resolve to the family's parent so a child SKU still previews the whole family.
        $parentSku = ($row->old_key && $row->old_key !== $row->sku) ? $row->old_key : $row->sku;
        $product = RetailEdgeProduct::where('sku', $parentSku)->with(['brand', 'children'])->first();
        if (! $product) {
            $this->error("Parent SKU {$parentSku} (for {$sku}) not found.");

            return Command::FAILURE;
        }

        if ($parentSku !== $sku) {
            $this->info("{$sku} is a child; previewing its parent family {$parentSku}.");
        }

        $this->printDryRunPlan($this->dryRunPlan($product));

        return Command::SUCCESS;
    }

    /**
     * Build a structured preview of what productSet would do for one family. Read-only.
     *
     * @return array{sku: string, title: ?string, path: string, existing_product_gid: ?string, synchronous: bool, product_options: array, variants: array<int, array{sku: string, options: string, price: string}>, blocked: array<int, array{sku: string, reason: string}>}
     */
    private function dryRunPlan(RetailEdgeProduct $product): array
    {
        $set = (new VariantSetBuilder)->build($product);

        // Path detection mirrors handle(): if any child SKU is already a Shopify variant
        // with a product id, we'd sync onto that existing product; otherwise create fresh.
        $childSkus = $product->children->pluck('sku')->all();
        $existingVariant = ShopifyProductVariant::whereIn('sku', $childSkus)
            ->whereNotNull('product_id')
            ->first();

        return [
            'sku' => $product->sku,
            'title' => $product->title,
            'path' => $existingVariant ? 'sync_existing' : 'create',
            'existing_product_gid' => $existingVariant ? 'gid://shopify/Product/'.$existingVariant->product_id : null,
            'synchronous' => count($set->variants) < self::PRODUCTSET_SYNC_MAX,
            'product_options' => $set->productOptions,
            'variants' => array_map(fn ($v) => [
                'sku' => $v['sku'],
                'options' => collect($v['optionValues'])->map(fn ($o) => $o['optionName'].'='.$o['name'])->implode(' / ') ?: '(none)',
                'price' => $v['price'],
            ], $set->variants),
            'blocked' => $set->blocked,
        ];
    }

    /**
     * Render a dry-run plan to the console.
     */
    private function printDryRunPlan(array $plan): void
    {
        $this->newLine();
        $this->info('DRY RUN — '.$plan['sku'].' — '.$plan['title']);
        $this->line('Path: '.$plan['path'].($plan['existing_product_gid'] ? ' ('.$plan['existing_product_gid'].')' : '').' | productSet: '.($plan['synchronous'] ? 'synchronous' : 'asynchronous'));

        $options = collect($plan['product_options'])
            ->map(fn ($o) => $o['name'].' ['.implode(', ', $o['values']).']')
            ->implode('  |  ');
        $this->line('Options: '.($options ?: '(none — single variant)'));

        $this->newLine();
        $this->table(['Variant SKU', 'Option values', 'Price'], array_map(
            fn ($v) => [$v['sku'], $v['options'], $v['price']],
            $plan['variants']
        ));
        $this->line(count($plan['variants']).' variant(s) would be sent to Shopify.');

        if (! empty($plan['blocked'])) {
            $this->newLine();
            $this->warn(count($plan['blocked']).' row(s) would be FLAGGED FOR REVIEW (not listed):');
            $this->table(['Blocked SKU', 'Reason'], array_map(
                fn ($b) => [$b['sku'], $b['reason']],
                $plan['blocked']
            ));
        }

        $this->newLine();
        $this->info('No changes made (dry run): Shopify was not called and no rows were updated.');
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
                // The parent reaches here only when its OWN SKU is not yet a Shopify variant
                // (whereNotExists above). It is pending if its own row still needs listing
                // (uploaded_to_shopify = 0 — strictly pending, so review-flagged 3 won't churn),
                // or it has a pending child, or it has no children at all.
                $q->where('uploaded_to_shopify', 0)
                    ->orWhereHas('children', fn ($c) => $c->where('uploaded_to_shopify', 0))
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

        // Live product: sync the complete family onto it via productSet. This adds the
        // parent's own variant and any missing children, updates the rest, and (being a
        // list field) prunes variants no longer in the family. Safe here because the
        // product is confirmed live and we emit the full intended set, never a partial one.
        $outcome = $this->syncExistingProductVariants($product, $productGid, $client);
        if (! $outcome['ok']) {
            // Could not complete the update (no buildable set / API issue) — do not delete
            // or mis-flag; retry next run.
            $this->warn("⏭️  {$product->sku}: could not sync variants to {$productGid}; will retry next run");

            return 'skip';
        }

        if (! empty($outcome['blocked'])) {
            Log::warning('CreateProduct: rows could not be synced as distinct variants on existing product; flagged for review', [
                'parent_sku' => $product->sku,
                'product_gid' => $productGid,
                'blocked' => $outcome['blocked'],
            ]);
        }

        $this->syncLogger->logSuccess(
            SyncLogger::MARKETPLACE_SHOPIFY,
            'shopifyCreateProduct',
            $product->sku,
            SyncLogger::OP_PRODUCT_CREATE,
            [
                'item_title' => $product->title,
                'to_value' => $productGid,
                'message' => 'Synced '.count($outcome['created']).' variant(s) to existing Shopify product via productSet',
                'shopify_product_id' => $this->extractIdFromGid($productGid),
                'context_data' => [
                    'created_skus' => $outcome['created'],
                    'already_present' => $existingChildSkus,
                    'blocked_skus' => $outcome['blocked'],
                ],
            ]
        );
        $this->info("✅ {$product->sku}: synced ".count($outcome['created'])." variant(s) to {$productGid}");

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
        // Build the complete variant set (parent + children) up front so the product
        // and all of its variants are created in one atomic productSet call. The parent
        // is included as a variant (the children() relation excludes it), and VT1 size
        // is read per-category, fixing the dropped-parent and chain-collapse defects.
        $set = (new VariantSetBuilder)->build($product);

        if (empty($set->variants)) {
            throw new \Exception("No buildable variants for {$product->sku} (every family row collapsed)");
        }

        $synchronous = count($set->variants) < self::PRODUCTSET_SYNC_MAX;
        $this->line('Executing productSet ('.($synchronous ? 'sync' : 'async').') with '.count($set->variants).' variant(s)...');

        $productId = $this->runProductSet($product, $set, $client, $synchronous);
        if (! $productId) {
            return null;
        }

        // Return the canonical edges shape the downstream save/reconcile code expects.
        return $this->getProductData($productId, $client);
    }

    /**
     * Build the ProductSetInput from the product's base attributes plus the prepared
     * variant set. The target product (for updates) is passed separately via the
     * productSet `identifier` argument, not inside this input.
     */
    private function buildProductSetInput(RetailEdgeProduct $product, VariantSet $set): array
    {
        // Reuse the base product attributes (title, description, vendor, tags, status,
        // Pandora template suffix) but supply options/variants from the builder instead
        // of the legacy buildProductOptions().
        $input = $this->buildProductInput($product);
        unset($input['productOptions']);

        if (! empty($set->productOptions)) {
            $input['productOptions'] = array_map(fn ($option) => [
                'name' => $option['name'],
                'position' => $option['position'],
                'values' => array_map(fn ($value) => ['name' => $value], $option['values']),
            ], $set->productOptions);
        }

        $input['variants'] = array_map(function ($variant) {
            $out = [
                'optionValues' => $variant['optionValues'],
                'price' => $variant['price'],
                'barcode' => $variant['barcode'],
                'inventoryPolicy' => 'DENY',
                'taxable' => true,
                'inventoryItem' => ['sku' => $variant['sku'], 'tracked' => true],
            ];
            if (! empty($variant['compareAtPrice'])) {
                $out['compareAtPrice'] = $variant['compareAtPrice'];
            }

            return $out;
        }, $set->variants);

        return $input;
    }

    /**
     * Execute the productSet mutation and return the created/updated product GID.
     * Pass $existingProductId (a product GID) to update that product in place via the
     * `identifier` argument. Throws on user or GraphQL errors; polls when asynchronous.
     */
    private function runProductSet(RetailEdgeProduct $product, VariantSet $set, $client, bool $synchronous, ?string $existingProductId = null): ?string
    {
        $input = $this->buildProductSetInput($product, $set);

        $mutation = <<<'GRAPHQL'
        mutation productSet($input: ProductSetInput!, $synchronous: Boolean!, $identifier: ProductSetIdentifiers) {
          productSet(synchronous: $synchronous, input: $input, identifier: $identifier) {
            product { id }
            productSetOperation { id status }
            userErrors { code field message }
          }
        }
        GRAPHQL;

        $variables = ['input' => $input, 'synchronous' => $synchronous];
        if ($existingProductId) {
            $variables['identifier'] = ['id' => $existingProductId];
        }

        $response = $client->query(['query' => $mutation, 'variables' => $variables]);
        $body = json_decode($response->getBody()->getContents(), true);

        $userErrors = $body['data']['productSet']['userErrors'] ?? [];
        $this->lastApiContext = [
            'api_request' => $input,
            'api_response' => $body,
            'user_errors' => $userErrors,
            'graphql_errors' => $body['errors'] ?? [],
        ];

        $errors = $this->handleGraphQLErrors($body);
        if (! empty($errors) || ! empty($userErrors)) {
            $messages = array_merge(
                $errors,
                array_map(fn ($e) => $e['message'] ?? json_encode($e), $userErrors)
            );
            throw new \Exception('productSet errors: '.implode(' | ', $messages));
        }

        $productId = $body['data']['productSet']['product']['id'] ?? null;
        if ($productId) {
            return $productId;
        }

        $operationId = $body['data']['productSet']['productSetOperation']['id'] ?? null;
        if ($operationId) {
            return $this->pollProductSetOperation($operationId, $client);
        }

        return null;
    }

    /**
     * Poll an asynchronous productSet operation until the product resolves. Only reached
     * for families at/above PRODUCTSET_SYNC_MAX variants.
     */
    private function pollProductSetOperation(string $operationId, $client, int $maxAttempts = 15): ?string
    {
        $query = <<<'GRAPHQL'
        query productOperation($id: ID!) {
          productOperation(id: $id) {
            ... on ProductSetOperation {
              status
              product { id }
            }
          }
        }
        GRAPHQL;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            usleep(1000000); // 1s between polls
            $response = $client->query(['query' => $query, 'variables' => ['id' => $operationId]]);
            $operation = json_decode($response->getBody()->getContents(), true)['data']['productOperation'] ?? null;

            $status = $operation['status'] ?? null;
            if ($status === 'COMPLETE' && ! empty($operation['product']['id'])) {
                return $operation['product']['id'];
            }
            if ($status === 'FAILED') {
                throw new \Exception("productSet async operation {$operationId} failed");
            }
        }

        throw new \Exception("productSet async operation {$operationId} did not complete in time");
    }

    /**
     * Reconcile uploaded_to_shopify flags after a productSet create, based on which SKUs
     * actually became live variants. Parent and children are treated uniformly: a row is
     * marked synced (1) only if its SKU is a live variant, otherwise flagged for review (3).
     *
     * @return array{created: array<int, string>, blocked: array<int, string>}
     */
    private function markFlagsFromProductSet(RetailEdgeProduct $product, array $createdProductData): array
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

        $parentLive = in_array($product->sku, $liveSkus, true);
        if ($parentLive) {
            $created[] = $product->sku;
        } else {
            $blocked[] = $product->sku;
        }

        $product->update(['uploaded_to_shopify' => ($parentLive && empty($blocked)) ? 1 : self::STATUS_NEEDS_REVIEW]);

        return ['created' => $created, 'blocked' => $blocked];
    }

    /**
     * Sync the complete family (parent + children) onto an already-live Shopify product
     * via productSet, then reconcile flags from what actually went live.
     *
     * productSet treats variants as a list field (create/update/delete to match input);
     * because we emit the full intended set, this adds the parent's own variant and any
     * missing children, updates the rest, and prunes anything no longer in the family.
     * Only call this once the product is confirmed live (never on an unverified fetch).
     *
     * @return array{created: array<int, string>, blocked: array<int, string>, ok: bool}
     */
    private function syncExistingProductVariants(RetailEdgeProduct $product, string $productGid, $client): array
    {
        $set = (new VariantSetBuilder)->build($product);

        if (empty($set->variants)) {
            // Nothing buildable (every row collapsed) — never send an empty/destructive set.
            return ['created' => [], 'blocked' => [], 'ok' => false];
        }

        $synchronous = count($set->variants) < self::PRODUCTSET_SYNC_MAX;
        $updatedId = $this->runProductSet($product, $set, $client, $synchronous, $productGid);
        if (! $updatedId) {
            return ['created' => [], 'blocked' => [], 'ok' => false];
        }

        $refreshed = $this->getProductData($updatedId, $client);
        if (! $refreshed) {
            return ['created' => [], 'blocked' => [], 'ok' => false];
        }

        $marked = $this->markFlagsFromProductSet($product, $refreshed);

        return ['created' => $marked['created'], 'blocked' => $marked['blocked'], 'ok' => true];
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
        ];

        // Variant options/variants are supplied by buildProductSetInput() from the
        // VariantSetBuilder; this method only provides the base product attributes.

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
