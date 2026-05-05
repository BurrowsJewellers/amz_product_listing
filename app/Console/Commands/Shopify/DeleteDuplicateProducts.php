<?php

namespace App\Console\Commands\Shopify;

use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyConnectionService;
use App\Services\SyncLogger;
use App\Traits\ShopifyCleanupTrait;
use App\Traits\ShopifyErrorFormatterTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Shopify\Clients\Graphql;

/**
 * Parent-level duplicate-product cleanup.
 *
 * Detects parent/standalone SKUs (retail_edge_products.old_key = sku, or empty)
 * that appear as a variant on more than one live Shopify product, picks the
 * "most-complete" copy to keep (most legitimate child variants, tiebreak on
 * oldest created_at then lowest product_id), and hard-deletes the rest from
 * Shopify with a cascading hard-delete of the local mirror rows.
 *
 * Companion to shopify:delete-duplicate-variants which handles the disjoint
 * case of child SKUs duplicated across products.
 */
class DeleteDuplicateProducts extends Command
{
    use ShopifyCleanupTrait;
    use ShopifyErrorFormatterTrait;

    protected $signature = 'shopify:delete-duplicate-products
        {--dry-run : Preview what would be deleted without making changes}
        {--force : Skip confirmation prompt}
        {--sku= : Target a specific parent SKU (optional)}
        {--limit= : Cap the number of duplicate parent SKUs to process}';

    protected $description = 'Delete duplicate Shopify products that share a parent SKU. Keeps the most-complete copy (most legitimate child variants), tiebreaks on oldest created_at then lowest product_id.';

    private ?Graphql $client = null;

    private SyncLogger $syncLogger;

    private array $stats = [
        'duplicate_parents_found' => 0,
        'kept' => 0,
        'skipped' => 0,
        'products_deleted' => 0,
        'variants_cascaded' => 0,
        'errors' => 0,
    ];

    public function __construct(private ShopifyConnectionService $connectionService)
    {
        parent::__construct();
        $this->syncLogger = new SyncLogger;
    }

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $targetSku = $this->option('sku');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->info('Shopify Duplicate Products Cleanup (parent-level)');
        $this->info('=================================================');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        if ($targetSku) {
            $this->info("Targeting specific SKU: {$targetSku}");
        }

        if ($limit !== null) {
            $this->info("Limit: {$limit}");
        }

        if (! $isDryRun) {
            $session = $this->connectionService->getSession();
            $this->client = new Graphql($session->getShop(), $session->getAccessToken());
        }

        $this->newLine();
        $this->info('Step 1: Finding parent SKUs on multiple live Shopify products...');
        $duplicates = $this->findDuplicateParents($targetSku, $limit);

        if (empty($duplicates)) {
            $this->info('No duplicate parent products found.');

            return Command::SUCCESS;
        }

        $this->stats['duplicate_parents_found'] = count($duplicates);
        $totalToDelete = array_sum(array_map(fn ($d) => $d->instances - 1, $duplicates));
        $this->info("Found {$this->stats['duplicate_parents_found']} parent SKUs on multiple live products ({$totalToDelete} extra products to delete)");

        if (! $isDryRun && ! $this->option('force')) {
            if (! $this->confirm("This will delete approximately {$totalToDelete} duplicate Shopify products. Continue?")) {
                $this->info('Operation cancelled.');

                return Command::SUCCESS;
            }
        }

        $this->newLine();
        $this->info('Step 2: Processing duplicates...');

        $progressBar = $this->output->createProgressBar(count($duplicates));
        $progressBar->start();

        foreach ($duplicates as $duplicate) {
            $this->processParentSku($duplicate->sku, $isDryRun);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->displaySummary($isDryRun);

        return $this->stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Find parent SKUs (old_key = sku, or empty) that have >1 live Shopify product.
     */
    private function findDuplicateParents(?string $targetSku, ?int $limit): array
    {
        $query = DB::table('shopify_product_variants as spv')
            ->join('shopify_products as sp', 'sp.id', '=', 'spv.shopify_product_id')
            ->join('retail_edge_products as rep', 'rep.sku', '=', 'spv.sku')
            ->whereNull('sp.deleted_at')
            ->where(function ($q) {
                $q->whereColumn('rep.old_key', 'rep.sku')
                    ->orWhere('rep.old_key', '');
            })
            ->whereNotNull('spv.sku')
            ->where('spv.sku', '!=', '')
            ->select('spv.sku', DB::raw('COUNT(DISTINCT spv.shopify_product_id) as instances'))
            ->groupBy('spv.sku')
            ->having('instances', '>', 1);

        if ($targetSku) {
            $query->where('spv.sku', $targetSku);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->toArray();
    }

    /**
     * Score, choose, and delete extras for a single parent SKU.
     */
    private function processParentSku(string $parentSku, bool $isDryRun): void
    {
        $this->newLine();
        $this->line("  Processing parent SKU: {$parentSku}");

        $candidates = DB::table('shopify_product_variants as spv')
            ->join('shopify_products as sp', 'sp.id', '=', 'spv.shopify_product_id')
            ->whereNull('sp.deleted_at')
            ->where('spv.sku', $parentSku)
            ->select('sp.id as pid', 'sp.product_id', 'sp.title', 'sp.created_at')
            ->distinct()
            ->get();

        if ($candidates->count() < 2) {
            $this->info('    SKIP: only one live product remaining (no longer a duplicate)');
            $this->stats['skipped']++;

            return;
        }

        $scored = [];
        foreach ($candidates as $c) {
            $scored[] = [
                'pid' => $c->pid,
                'product_id' => (int) $c->product_id,
                'title' => $c->title,
                'created_at' => $c->created_at,
                'score' => $this->scoreCandidate((int) $c->pid, $parentSku),
            ];
        }

        // Sort: score DESC, created_at ASC, product_id ASC
        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            $cmp = strcmp((string) $a['created_at'], (string) $b['created_at']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $a['product_id'] <=> $b['product_id'];
        });

        $keep = $scored[0];
        $toDelete = array_slice($scored, 1);

        $this->line("    Candidates: {$candidates->count()}");
        foreach ($scored as $s) {
            $tag = $s['pid'] === $keep['pid'] ? 'KEEP   ' : 'DELETE ';
            $titlePreview = substr((string) $s['title'], 0, 50);
            $this->line("      [{$tag}] product_id={$s['product_id']} score={$s['score']} created={$s['created_at']} title=\"{$titlePreview}\"");
        }

        $this->stats['kept']++;

        foreach ($toDelete as $d) {
            $this->deleteOne($d, $parentSku, $isDryRun);
        }
    }

    /**
     * Score = number of variants on this product whose SKU is a legitimate
     * child of $parentSku per RetailEdge (rep.old_key = $parentSku). The
     * parent's own self-variant (sku = old_key = parentSku) is included.
     */
    private function scoreCandidate(int $shopifyProductsId, string $parentSku): int
    {
        return DB::table('shopify_product_variants as spv')
            ->join('retail_edge_products as rep', 'rep.sku', '=', 'spv.sku')
            ->where('spv.shopify_product_id', $shopifyProductsId)
            ->where('rep.old_key', $parentSku)
            ->count();
    }

    private function deleteOne(array $d, string $parentSku, bool $isDryRun): void
    {
        if ($isDryRun) {
            $variantsCount = ShopifyProductVariant::where('shopify_product_id', $d['pid'])->count();
            $this->line("        [DRY RUN] Would delete product_id={$d['product_id']} ({$variantsCount} mirrored variants)");

            return;
        }

        $result = $this->deleteProductFromShopify($d['product_id']);

        if ($result['success']) {
            $this->cascadeLocalCleanup($d['pid']);
            $this->stats['products_deleted']++;

            $this->info("        Deleted product_id={$d['product_id']} successfully");

            $this->syncLogger->logSuccess(
                SyncLogger::MARKETPLACE_SHOPIFY,
                'shopify:delete-duplicate-products',
                $parentSku,
                SyncLogger::OP_PRODUCT_DELETE,
                [
                    'message' => 'Deleted duplicate parent product (kept most-complete/oldest copy)',
                    'shopify_product_id' => $d['product_id'],
                    'context_data' => ['parent_sku' => $parentSku, 'score' => $d['score']],
                ]
            );
        } else {
            $this->handleDeletionError($result, $d, $parentSku);
        }

        usleep(100000); // 100ms rate limit
    }

    /**
     * Hard-delete the local mirror rows for a Shopify product. Per-variant
     * cleanup uses ShopifyCleanupTrait so the uploaded_to_shopify flag on
     * orphaned SKUs gets reset for the next sync to pick up.
     */
    private function cascadeLocalCleanup(int $shopifyProductsId): void
    {
        $variants = ShopifyProductVariant::where('shopify_product_id', $shopifyProductsId)->get();
        foreach ($variants as $v) {
            $this->cleanupStaleVariant($v, 'DeleteDuplicateProducts');
            $this->stats['variants_cascaded']++;
        }
        // Trait deletes the product row when no variants remain; this is a belt-and-braces
        // for the case where the product had zero variants in the local mirror.
        ShopifyProduct::where('id', $shopifyProductsId)->forceDelete();
    }

    private function deleteProductFromShopify(int $productId): array
    {
        $mutation = <<<'GRAPHQL'
        mutation productDelete($input: ProductDeleteInput!) {
          productDelete(input: $input) {
            deletedProductId
            userErrors { field message }
          }
        }
        GRAPHQL;

        $productGid = "gid://shopify/Product/{$productId}";

        try {
            $response = $this->client->query([
                'query' => $mutation,
                'variables' => ['input' => ['id' => $productGid]],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $userErrors = $body['data']['productDelete']['userErrors'] ?? [];
            $graphqlErrors = $body['errors'] ?? [];

            if (! empty($userErrors) || ! empty($graphqlErrors)) {
                return [
                    'success' => false,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ];
            }

            return ['success' => true, 'user_errors' => [], 'graphql_errors' => []];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'user_errors' => [],
                'graphql_errors' => [['message' => $e->getMessage()]],
            ];
        }
    }

    private function handleDeletionError(array $result, array $d, string $parentSku): void
    {
        $errorMessage = $this->formatGraphQLErrorMessage($result);

        if ($this->isResourceNotExistsError($errorMessage)) {
            $this->warn('        Not found on Shopify - cleaning local mirror only');
            $this->cascadeLocalCleanup($d['pid']);
            $this->stats['products_deleted']++;

            $this->syncLogger->logSuccess(
                SyncLogger::MARKETPLACE_SHOPIFY,
                'shopify:delete-duplicate-products',
                $parentSku,
                SyncLogger::OP_DUPLICATE_CLEANUP,
                [
                    'message' => 'Cleaned up stale local mirror (Shopify product no longer exists)',
                    'shopify_product_id' => $d['product_id'],
                    'context_data' => ['parent_sku' => $parentSku],
                ]
            );

            return;
        }

        $this->error("        Failed: {$errorMessage}");
        $this->stats['errors']++;

        $this->syncLogger->logFailure(
            SyncLogger::MARKETPLACE_SHOPIFY,
            'shopify:delete-duplicate-products',
            $parentSku,
            SyncLogger::OP_PRODUCT_DELETE,
            $errorMessage,
            [
                'shopify_product_id' => $d['product_id'],
                'errors' => array_merge($result['user_errors'] ?? [], $result['graphql_errors'] ?? []),
                'context_data' => ['parent_sku' => $parentSku],
            ]
        );
    }

    private function displaySummary(bool $isDryRun): void
    {
        $this->newLine();
        $this->info('Summary:');
        $this->info('========');
        $this->info("  Duplicate parent SKUs found: {$this->stats['duplicate_parents_found']}");
        $this->info("  Products kept (one per parent SKU): {$this->stats['kept']}");
        $this->info("  Skipped (already single-instance at processing time): {$this->stats['skipped']}");

        if ($isDryRun) {
            $this->warn('  [DRY RUN] No changes were made');
        } else {
            $this->info("  Products deleted from Shopify: {$this->stats['products_deleted']}");
            $this->info("  Variant rows cascaded (local mirror): {$this->stats['variants_cascaded']}");
            $this->info("  Errors: {$this->stats['errors']}");
        }

        if ($this->stats['errors'] > 0) {
            $this->error("Completed with {$this->stats['errors']} errors. Check logs for details.");
        } else {
            $this->info('Completed successfully.');
        }
    }
}
