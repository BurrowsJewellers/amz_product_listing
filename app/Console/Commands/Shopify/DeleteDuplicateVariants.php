<?php

namespace App\Console\Commands\Shopify;

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
        'deleted_shopify' => 0,
        'deleted_database' => 0,
        'skipped' => 0,
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

        $this->stats['duplicates_found'] = array_sum(array_map(fn ($d) => $d->count - 1, $duplicateSkus));
        $this->info('Found '.count($duplicateSkus)." SKUs with duplicates ({$this->stats['duplicates_found']} variants to delete)");

        // Confirmation prompt (unless dry-run or --force)
        if (! $isDryRun && ! $this->option('force')) {
            if (! $this->confirm("This will delete {$this->stats['duplicates_found']} duplicate variants from Shopify and the database. Continue?")) {
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
            $this->processDuplicateSku($duplicate->sku, $duplicate->newest_variant_id, $isDryRun);
            $progressBar->advance();

            // Rate limiting delay (100ms between operations)
            if (! $isDryRun) {
                usleep(100000);
            }
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
     * Process a single SKU with duplicates
     */
    private function processDuplicateSku(string $sku, int $newestVariantId, bool $isDryRun): void
    {
        $variants = $this->getVariantsForSku($sku);

        // Skip the first one (newest to keep)
        $variantsToDelete = $variants->skip(1);

        foreach ($variantsToDelete as $variant) {
            $this->newLine();
            $this->line("  SKU: {$sku}");
            $this->line("    Keeping variant_id: {$newestVariantId}");
            $this->line("    Deleting variant_id: {$variant->variant_id}");

            // Check if this would leave the product with no variants (Shopify won't allow this)
            $productVariantCount = ShopifyProductVariant::where('product_id', $variant->product_id)->count();
            if ($productVariantCount <= 1) {
                $this->warn('    Skipping - cannot delete the last variant of a product');
                $this->stats['skipped']++;

                continue;
            }

            if ($isDryRun) {
                $this->line('    [DRY RUN] Would delete from Shopify and database');

                continue;
            }

            // Delete from Shopify first
            $shopifyResult = $this->deleteVariantFromShopify($variant->variant_id);

            if ($shopifyResult['success']) {
                $this->stats['deleted_shopify']++;

                // Delete from local database using trait
                $this->cleanupStaleVariant($variant, 'DeleteDuplicateVariants');
                $this->stats['deleted_database']++;

                $this->info('    Deleted successfully');
                Log::info('DeleteDuplicateVariants: Deleted duplicate variant', [
                    'sku' => $sku,
                    'deleted_variant_id' => $variant->variant_id,
                    'kept_variant_id' => $newestVariantId,
                ]);
            } else {
                $errorMessage = $this->formatGraphQLErrorMessage($shopifyResult);

                // Check if variant doesn't exist on Shopify (already deleted)
                if ($this->isResourceNotExistsError($errorMessage)) {
                    $this->warn('    Variant not found on Shopify - cleaning database only');

                    // Still delete from database
                    $this->cleanupStaleVariant($variant, 'DeleteDuplicateVariants');
                    $this->stats['deleted_database']++;

                    Log::info('DeleteDuplicateVariants: Cleaned stale variant record', [
                        'sku' => $sku,
                        'variant_id' => $variant->variant_id,
                    ]);
                } else {
                    $this->error("    Failed to delete: {$errorMessage}");
                    $this->stats['errors']++;

                    Log::error('DeleteDuplicateVariants: Failed to delete variant', [
                        'sku' => $sku,
                        'variant_id' => $variant->variant_id,
                        'error' => $errorMessage,
                    ]);
                }
            }
        }
    }

    /**
     * Delete a variant from Shopify using GraphQL
     */
    private function deleteVariantFromShopify(int $variantId): array
    {
        $mutation = <<<'GRAPHQL'
        mutation productVariantDelete($id: ID!) {
          productVariantDelete(id: $id) {
            deletedProductVariantId
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

        $variantGid = "gid://shopify/ProductVariant/{$variantId}";

        try {
            $response = $this->client->query([
                'query' => $mutation,
                'variables' => ['id' => $variantGid],
            ]);

            $resultBody = json_decode($response->getBody()->getContents(), true);

            $userErrors = $resultBody['data']['productVariantDelete']['userErrors'] ?? [];
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
                'deleted_id' => $resultBody['data']['productVariantDelete']['deletedProductVariantId'] ?? null,
                'user_errors' => [],
                'graphql_errors' => [],
            ];
        } catch (\Exception $e) {
            Log::error('DeleteDuplicateVariants: Exception during variant deletion', [
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
     * Display summary statistics
     */
    private function displaySummary(bool $isDryRun): void
    {
        $this->newLine();
        $this->info('Summary:');
        $this->info('========');
        $this->info("  Duplicate variants found: {$this->stats['duplicates_found']}");

        if ($isDryRun) {
            $this->warn('  [DRY RUN] No changes were made');
            if ($this->stats['skipped'] > 0) {
                $this->warn("  Skipped (last variant): {$this->stats['skipped']}");
            }
        } else {
            $this->info("  Deleted from Shopify: {$this->stats['deleted_shopify']}");
            $this->info("  Deleted from database: {$this->stats['deleted_database']}");
            if ($this->stats['skipped'] > 0) {
                $this->warn("  Skipped (last variant): {$this->stats['skipped']}");
            }
            $this->info("  Errors: {$this->stats['errors']}");
        }

        if ($this->stats['errors'] > 0) {
            $this->error("Completed with {$this->stats['errors']} errors. Check logs for details.");
        } else {
            $this->info('Completed successfully.');
        }
    }
}
