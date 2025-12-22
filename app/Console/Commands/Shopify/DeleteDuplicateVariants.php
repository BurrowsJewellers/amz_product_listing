<?php

namespace App\Console\Commands\Shopify;

use App\Models\RetailEdgeProduct;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyConnectionService;
use App\Traits\ShopifyCleanupTrait;
use App\Traits\ShopifyErrorFormatterTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;

class DeleteDuplicateVariants extends Command
{
    use ShopifyCleanupTrait;
    use ShopifyErrorFormatterTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:delete-duplicate-variants
        {--dry-run : Preview what would be deleted without making changes}
        {--force : Skip confirmation prompt}
        {--sku= : Target a specific SKU (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete duplicate Shopify variants, keeping the newest variant for each SKU';

    /**
     * GraphQL client instance
     */
    private ?Graphql $client = null;

    /**
     * Statistics tracking
     */
    private array $stats = [
        'duplicates_found' => 0,
        'skipped_standalone' => 0,
        'kept_on_correct_product' => 0,
        'products_deleted' => 0,
        'variants_deleted' => 0,
        'deleted_database' => 0,
        'errors' => 0,
    ];

    public function __construct(
        private ShopifyConnectionService $connectionService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $targetSku = $this->option('sku');

        $this->info('Shopify Duplicate Variants Cleanup');
        $this->info('==================================');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        if ($targetSku) {
            $this->info("Targeting specific SKU: {$targetSku}");
        }

        // Initialize GraphQL client (only if not dry-run)
        if (! $isDryRun) {
            $session = $this->connectionService->getSession();
            $this->client = new Graphql($session->getShop(), $session->getAccessToken());
        }

        // Step 1: Find all duplicate SKUs
        $this->newLine();
        $this->info('Step 1: Finding duplicate SKUs...');
        $duplicateSkus = $this->findDuplicateSkus($targetSku);

        if (empty($duplicateSkus)) {
            $this->info('No duplicate variants found.');

            return Command::SUCCESS;
        }

        $this->stats['duplicates_found'] = count($duplicateSkus);
        $totalDuplicateVariants = array_sum(array_map(fn ($d) => $d->count - 1, $duplicateSkus));
        $this->info("Found {$this->stats['duplicates_found']} SKUs with duplicates ({$totalDuplicateVariants} extra variants)");

        // Confirmation prompt (unless dry-run or --force)
        if (! $isDryRun && ! $this->option('force')) {
            if (! $this->confirm("This will process {$this->stats['duplicates_found']} duplicate SKUs and delete incorrect products/variants from Shopify. Continue?")) {
                $this->info('Operation cancelled.');

                return Command::SUCCESS;
            }
        }

        // Step 2: Process each duplicate SKU
        $this->newLine();
        $this->info('Step 2: Processing duplicates...');

        $progressBar = $this->output->createProgressBar(count($duplicateSkus));
        $progressBar->start();

        foreach ($duplicateSkus as $duplicate) {
            $this->processDuplicateSku($duplicate->sku, $isDryRun);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        // Step 3: Display summary
        $this->displaySummary($isDryRun);

        return $this->stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Find duplicate variants grouped by SKU
     */
    private function findDuplicateSkus(?string $targetSku = null): array
    {
        $query = DB::table('shopify_product_variants')
            ->select('sku', DB::raw('COUNT(*) as count'), DB::raw('MAX(variant_id) as newest_variant_id'))
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->groupBy('sku')
            ->having('count', '>', 1);

        if ($targetSku) {
            $query->where('sku', $targetSku);
        }

        return $query->get()->toArray();
    }

    /**
     * Get all variants for a specific SKU, ordered by variant_id descending
     */
    private function getVariantsForSku(string $sku): \Illuminate\Support\Collection
    {
        return ShopifyProductVariant::where('sku', $sku)
            ->orderBy('variant_id', 'desc')
            ->get();
    }

    /**
     * Check if a SKU is a child product (old_key != sku)
     *
     * @return array{is_child: bool, parent_sku: string|null, retail_edge: RetailEdgeProduct|null}
     */
    private function isChildProduct(string $sku): array
    {
        $retailEdge = RetailEdgeProduct::where('sku', $sku)->first();

        if (! $retailEdge) {
            return ['is_child' => false, 'parent_sku' => null, 'retail_edge' => null];
        }

        // Empty old_key means standalone product
        if (empty($retailEdge->old_key)) {
            return ['is_child' => false, 'parent_sku' => null, 'retail_edge' => $retailEdge];
        }

        // If old_key = sku, it's a parent/standalone
        $isChild = $retailEdge->old_key !== $retailEdge->sku;

        return [
            'is_child' => $isChild,
            'parent_sku' => $isChild ? $retailEdge->old_key : null,
            'retail_edge' => $retailEdge,
        ];
    }

    /**
     * Find the correct Shopify product ID for a child SKU based on its parent
     */
    private function getCorrectProductId(string $parentSku): ?int
    {
        $parentVariant = ShopifyProductVariant::where('sku', $parentSku)->first();

        if (! $parentVariant) {
            Log::warning('DeleteDuplicateVariants: Parent SKU not found in Shopify', [
                'parent_sku' => $parentSku,
            ]);

            return null;
        }

        return $parentVariant->product_id;
    }

    /**
     * Process a single SKU with duplicates
     * For child SKUs: keeps variants on correct parent product, deletes others
     * For parent/standalone SKUs: skips (valid single-variant products)
     */
    private function processDuplicateSku(string $sku, bool $isDryRun): void
    {
        $this->newLine();
        $this->line("  Processing SKU: {$sku}");

        // Check if this is a child product
        $childCheck = $this->isChildProduct($sku);

        if (! $childCheck['is_child']) {
            $this->info('    SKIP: Not a child product (standalone/parent with old_key = sku or empty)');
            $this->stats['skipped_standalone']++;

            return;
        }

        $parentSku = $childCheck['parent_sku'];
        $this->line("    Parent SKU: {$parentSku}");

        // Find the correct product based on parent
        $correctProductId = $this->getCorrectProductId($parentSku);
        $variants = $this->getVariantsForSku($sku);

        $this->line("    Total variants found: {$variants->count()}");
        $this->line('    Correct product_id: '.($correctProductId ?? 'NOT FOUND (parent not in Shopify)'));

        foreach ($variants as $variant) {
            // Check if this variant is on the correct product
            if ($correctProductId && $variant->product_id == $correctProductId) {
                $this->info("    KEEP variant_id: {$variant->variant_id} (on correct parent product)");
                $this->stats['kept_on_correct_product']++;

                continue;
            }

            // This variant is on wrong product - needs deletion
            $this->line("    DELETE variant_id: {$variant->variant_id} (on wrong product {$variant->product_id})");

            if ($isDryRun) {
                $productVariantCount = ShopifyProductVariant::where('product_id', $variant->product_id)->count();
                if ($productVariantCount <= 1) {
                    $this->line("      [DRY RUN] Would delete entire product {$variant->product_id}");
                } else {
                    $this->line('      [DRY RUN] Would delete variant from product');
                }

                continue;
            }

            // Check if this is the only variant on the product
            $productVariantCount = ShopifyProductVariant::where('product_id', $variant->product_id)->count();

            if ($productVariantCount <= 1) {
                // Delete entire product (can't delete last variant)
                $this->line('      Deleting entire product (only variant)...');
                $result = $this->deleteProductFromShopify($variant->product_id);

                if ($result['success']) {
                    $this->stats['products_deleted']++;
                    // Delete all variants for this product from database
                    $this->cleanupProductVariants($variant->product_id);
                    $this->stats['deleted_database']++;
                    $this->info('      Deleted product successfully');
                } else {
                    $this->handleDeletionError($result, $variant, $sku);
                }
            } else {
                // Delete just this variant
                $this->line('      Deleting variant from product...');
                $result = $this->deleteVariantFromShopify($variant->product_id, $variant->variant_id);

                if ($result['success']) {
                    $this->stats['variants_deleted']++;
                    $this->cleanupStaleVariant($variant, 'DeleteDuplicateVariants');
                    $this->stats['deleted_database']++;
                    $this->info('      Deleted variant successfully');
                } else {
                    $this->handleDeletionError($result, $variant, $sku);
                }
            }

            // Rate limiting
            usleep(100000); // 100ms delay
        }
    }

    /**
     * Handle deletion errors with appropriate logging and stats
     */
    private function handleDeletionError(array $result, ShopifyProductVariant $variant, string $sku): void
    {
        $errorMessage = $this->formatGraphQLErrorMessage($result);

        // Check if resource doesn't exist on Shopify (already deleted)
        if ($this->isResourceNotExistsError($errorMessage)) {
            $this->warn('      Not found on Shopify - cleaning database only');
            $this->cleanupStaleVariant($variant, 'DeleteDuplicateVariants');
            $this->stats['deleted_database']++;
        } else {
            $this->error("      Failed: {$errorMessage}");
            $this->stats['errors']++;

            Log::error('DeleteDuplicateVariants: Failed to delete', [
                'sku' => $sku,
                'variant_id' => $variant->variant_id,
                'product_id' => $variant->product_id,
                'error' => $errorMessage,
            ]);
        }
    }

    /**
     * Cleanup all variants for a product from the database
     */
    private function cleanupProductVariants(int $productId): void
    {
        $variants = ShopifyProductVariant::where('product_id', $productId)->get();
        foreach ($variants as $variant) {
            $this->cleanupStaleVariant($variant, 'DeleteDuplicateVariants');
        }
    }

    /**
     * Delete a variant from Shopify using GraphQL (productVariantsBulkDelete)
     */
    private function deleteVariantFromShopify(int $productId, int $variantId): array
    {
        $mutation = <<<'GRAPHQL'
        mutation productVariantsBulkDelete($productId: ID!, $variantsIds: [ID!]!) {
          productVariantsBulkDelete(productId: $productId, variantsIds: $variantsIds) {
            product {
              id
              title
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        $productGid = "gid://shopify/Product/{$productId}";
        $variantGid = "gid://shopify/ProductVariant/{$variantId}";

        try {
            $response = $this->client->query([
                'query' => $mutation,
                'variables' => [
                    'productId' => $productGid,
                    'variantsIds' => [$variantGid],
                ],
            ]);

            $resultBody = json_decode($response->getBody()->getContents(), true);

            $userErrors = $resultBody['data']['productVariantsBulkDelete']['userErrors'] ?? [];
            $graphqlErrors = $resultBody['errors'] ?? [];

            if (! empty($userErrors) || ! empty($graphqlErrors)) {
                return [
                    'success' => false,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ];
            }

            return [
                'success' => true,
                'user_errors' => [],
                'graphql_errors' => [],
            ];
        } catch (\Exception $e) {
            Log::error('DeleteDuplicateVariants: Exception during variant deletion', [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'user_errors' => [],
                'graphql_errors' => [['message' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Delete an entire product from Shopify using GraphQL
     */
    private function deleteProductFromShopify(int $productId): array
    {
        $mutation = <<<'GRAPHQL'
        mutation productDelete($input: ProductDeleteInput!) {
          productDelete(input: $input) {
            deletedProductId
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        $productGid = "gid://shopify/Product/{$productId}";

        try {
            $response = $this->client->query([
                'query' => $mutation,
                'variables' => [
                    'input' => ['id' => $productGid],
                ],
            ]);

            $resultBody = json_decode($response->getBody()->getContents(), true);

            $userErrors = $resultBody['data']['productDelete']['userErrors'] ?? [];
            $graphqlErrors = $resultBody['errors'] ?? [];

            if (! empty($userErrors) || ! empty($graphqlErrors)) {
                return [
                    'success' => false,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ];
            }

            Log::info('DeleteDuplicateVariants: Deleted product from Shopify', [
                'product_id' => $productId,
                'deleted_id' => $resultBody['data']['productDelete']['deletedProductId'] ?? null,
            ]);

            return [
                'success' => true,
                'user_errors' => [],
                'graphql_errors' => [],
            ];
        } catch (\Exception $e) {
            Log::error('DeleteDuplicateVariants: Exception during product deletion', [
                'product_id' => $productId,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'user_errors' => [],
                'graphql_errors' => [['message' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Display summary statistics
     */
    private function displaySummary(bool $isDryRun): void
    {
        $this->newLine();
        $this->info('Summary:');
        $this->info('========');
        $this->info("  Duplicate SKUs processed: {$this->stats['duplicates_found']}");
        $this->info("  Skipped (standalone/parent products): {$this->stats['skipped_standalone']}");
        $this->info("  Kept on correct parent product: {$this->stats['kept_on_correct_product']}");

        if ($isDryRun) {
            $this->warn('  [DRY RUN] No changes were made');
        } else {
            $this->info("  Products deleted from Shopify: {$this->stats['products_deleted']}");
            $this->info("  Variants deleted from Shopify: {$this->stats['variants_deleted']}");
            $this->info("  Database records cleaned: {$this->stats['deleted_database']}");
            $this->info("  Errors: {$this->stats['errors']}");
        }

        if ($this->stats['errors'] > 0) {
            $this->error("Completed with {$this->stats['errors']} errors. Check logs for details.");
        } else {
            $this->info('Completed successfully.');
        }
    }
}
