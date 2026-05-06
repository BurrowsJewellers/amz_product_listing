<?php

namespace App\Console\Commands\Shopify;

use App\Models\RetailEdgeProduct;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyProduct;
use App\Services\MetafieldAssignmentService;
use App\Services\ShopifyConnectionService;
use App\Services\SyncLogger;
use App\Traits\ShopifyErrorFormatterTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;

class BackfillMetafields extends Command
{
    use ShopifyErrorFormatterTrait;

    protected $signature = 'shopify:backfill-metafields
        {--dry-run : Plan changes without writing to Shopify}
        {--force : Skip confirmation when running live}
        {--sku= : Limit to a single parent SKU}
        {--limit= : Cap the number of products processed}';

    protected $description = 'Backfill Shopify metafields to dual-level placement and set design_number_variant on every variant.';

    private ?Graphql $client = null;

    private SyncLogger $syncLogger;

    private MetafieldAssignmentService $assignmentService;

    /** @var array<string, ShopifyMetafield> */
    private array $definitionCache = [];

    private ?ShopifyMetafield $designDefinition = null;

    public function __construct(private ShopifyConnectionService $connectionService)
    {
        parent::__construct();
        $this->syncLogger = new SyncLogger;
        $this->assignmentService = new MetafieldAssignmentService;
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sku = $this->option('sku');
        $limitOpt = $this->option('limit');
        $limit = $limitOpt !== null ? (int) $limitOpt : null;

        if (! $dryRun && ! $this->option('force')) {
            if (! $this->confirm('This will write metafields to Shopify. Continue?')) {
                return self::SUCCESS;
            }
        }

        $this->info($dryRun ? '[DRY RUN] No writes will be sent to Shopify.' : 'Live mode: writes will be sent to Shopify.');

        if (! $dryRun) {
            $session = $this->connectionService->getSession();
            $this->client = new Graphql($session->getShop(), $session->getAccessToken());
        }

        // Pre-warm definition cache — single query for all metafield definitions
        $allDefinitions = ShopifyMetafield::all();
        foreach ($allDefinitions as $def) {
            $this->definitionCache["{$def->name}|{$def->owner_type}"] = $def;
        }

        // Load design_number_variant definition separately
        $this->designDefinition = ShopifyMetafield::where('namespace', 'custom')
            ->where('key', 'design_number_variant')
            ->where('owner_type', 'PRODUCTVARIANT')
            ->first();

        $products = $this->collectProducts($sku, $limit);
        $this->info('Products in scope: '.$products->count());

        $stats = ['scanned' => 0, 'product_writes' => 0, 'variant_writes' => 0, 'errors' => 0];

        $progressBar = $this->output->createProgressBar($products->count());
        $progressBar->start();

        foreach ($products as $row) {
            $stats['scanned']++;
            try {
                $this->processProduct($row, $dryRun, $stats);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("Failed to process product GID={$row->gid}: ".$e->getMessage());
                Log::error('shopify:backfill-metafields failure', ['gid' => $row->gid, 'exception' => $e]);

                if (! $dryRun) {
                    $this->syncLogger->logFailure(
                        SyncLogger::MARKETPLACE_SHOPIFY,
                        'shopify:backfill-metafields',
                        $row->gid,
                        SyncLogger::OP_METAFIELD_BACKFILL,
                        $e,
                        [
                            'context_data' => ['gid' => $row->gid],
                        ]
                    );
                }
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $rows = [];
        foreach ($stats as $k => $v) {
            $rows[] = [$k, $v];
        }
        $this->table(['metric', 'value'], $rows);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function collectProducts(?string $sku, ?int $limit): Collection
    {
        $query = DB::table('shopify_products as sp')
            ->join('shopify_product_variants as spv', 'spv.shopify_product_id', '=', 'sp.id')
            ->whereNull('sp.deleted_at')
            ->select('sp.id as shopify_products_id', 'sp.product_id', DB::raw("CONCAT('gid://shopify/Product/', sp.product_id) as gid"))
            ->groupBy('sp.id', 'sp.product_id');

        if ($sku) {
            $query->where('spv.sku', $sku);
        }

        $rows = $query->get();

        if ($limit !== null) {
            $rows = $rows->take($limit);
        }

        return $rows;
    }

    private function processProduct(object $row, bool $dryRun, array &$stats): void
    {
        $shopifyProduct = ShopifyProduct::with('variants')->find($row->shopify_products_id);
        if (! $shopifyProduct) {
            return;
        }

        $variantSkus = $shopifyProduct->variants->pluck('sku')->filter()->values();
        if ($variantSkus->isEmpty()) {
            return;
        }

        $parent = $this->resolveParent($variantSkus->first());
        if (! $parent) {
            return;
        }

        $assignment = $this->assignmentService->determineMetafieldAssignment($parent);

        $batch = $this->buildMetafieldBatch($shopifyProduct, $assignment);
        if (empty($batch)) {
            return;
        }

        $productCount = count(array_filter($batch, fn ($m) => str_contains($m['ownerId'], '/Product/')));
        $variantCount = count(array_filter($batch, fn ($m) => str_contains($m['ownerId'], '/ProductVariant/')));

        if ($dryRun) {
            $this->line(" [DRY] {$row->gid}: would set ".count($batch).' metafields');
            $stats['product_writes'] += $productCount;
            $stats['variant_writes'] += $variantCount;

            return;
        }

        $userErrorCount = $this->writeBatch($batch);
        $stats['product_writes'] += $productCount;
        $stats['variant_writes'] += $variantCount;

        if ($userErrorCount > 0) {
            $this->syncLogger->logFailure(
                SyncLogger::MARKETPLACE_SHOPIFY,
                'shopify:backfill-metafields',
                $parent->sku,
                SyncLogger::OP_METAFIELD_BACKFILL,
                "Metafield write completed with {$userErrorCount} userError(s)",
                [
                    'shopify_product_id' => (int) $shopifyProduct->product_id,
                    'context_data' => [
                        'parent_sku' => $parent->sku,
                        'batch_size' => count($batch),
                        'user_errors' => $userErrorCount,
                    ],
                ]
            );
        } else {
            $this->syncLogger->logSuccess(
                SyncLogger::MARKETPLACE_SHOPIFY,
                'shopify:backfill-metafields',
                $parent->sku,
                SyncLogger::OP_METAFIELD_BACKFILL,
                [
                    'message' => 'Backfilled metafields for product',
                    'shopify_product_id' => (int) $shopifyProduct->product_id,
                    'context_data' => [
                        'parent_sku' => $parent->sku,
                        'product_metafields' => $productCount,
                        'variant_metafields' => $variantCount,
                    ],
                ]
            );
        }

        usleep(100_000);
    }

    private function resolveParent(string $variantSku): ?RetailEdgeProduct
    {
        $variantRep = RetailEdgeProduct::where('sku', $variantSku)->first();
        if (! $variantRep) {
            return null;
        }

        if (empty($variantRep->old_key) || $variantRep->old_key === $variantRep->sku) {
            return $variantRep; // standalone or self-parent
        }

        return RetailEdgeProduct::where('sku', $variantRep->old_key)->first() ?? $variantRep;
    }

    private function buildMetafieldBatch(ShopifyProduct $shopifyProduct, array $assignment): array
    {
        $batch = [];

        // Product-level entries — cache lookup instead of per-row query
        foreach ($assignment['product_metafields'] as $mf) {
            $def = $this->definitionCache["{$mf['isd_name']}|PRODUCT"] ?? null;
            if (! $def || empty($mf['value'])) {
                continue;
            }
            $batch[] = [
                'ownerId' => "gid://shopify/Product/{$shopifyProduct->product_id}",
                'namespace' => $def->namespace,
                'key' => $def->key,
                'type' => $def->type,
                'value' => (string) $mf['value'],
            ];
        }

        // Variant-level ISD entries — cache lookup instead of per-row query
        foreach ($assignment['variant_metafields'] as $variantSku => $metafields) {
            $variantRow = $shopifyProduct->variants->firstWhere('sku', $variantSku);
            if (! $variantRow || empty($variantRow->variant_id)) {
                continue;
            }
            $ownerId = "gid://shopify/ProductVariant/{$variantRow->variant_id}";

            foreach ($metafields as $mf) {
                $def = $this->definitionCache["{$mf['isd_name']}|PRODUCTVARIANT"] ?? null;
                if (! $def || empty($mf['value'])) {
                    continue;
                }
                $batch[] = [
                    'ownerId' => $ownerId,
                    'namespace' => $def->namespace,
                    'key' => $def->key,
                    'type' => $def->type,
                    'value' => (string) $mf['value'],
                ];
            }
        }

        // design_number_variant — full design number per variant
        // Use whereIn to batch the RetailEdge lookup for all variants at once
        if ($this->designDefinition) {
            $variantSkus = $shopifyProduct->variants
                ->filter(fn ($v) => ! empty($v->variant_id) && ! empty($v->sku))
                ->pluck('sku')
                ->values()
                ->all();

            $repBySkus = RetailEdgeProduct::whereIn('sku', $variantSkus)
                ->get()
                ->keyBy('sku');

            foreach ($shopifyProduct->variants as $variantRow) {
                if (empty($variantRow->variant_id) || empty($variantRow->sku)) {
                    continue;
                }
                $rep = $repBySkus->get($variantRow->sku);
                if (! $rep || empty($rep->real_design_number)) {
                    continue;
                }
                $batch[] = [
                    'ownerId' => "gid://shopify/ProductVariant/{$variantRow->variant_id}",
                    'namespace' => $this->designDefinition->namespace,
                    'key' => $this->designDefinition->key,
                    'type' => $this->designDefinition->type,
                    'value' => (string) $rep->real_design_number,
                ];
            }
        }

        return $batch;
    }

    /**
     * Write a batch of metafields to Shopify and return the count of userErrors.
     */
    private function writeBatch(array $batch): int
    {
        $mutation = <<<'GRAPHQL'
    mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) {
        metafields { id namespace key value ownerType }
        userErrors { field message }
      }
    }
    GRAPHQL;

        $totalUserErrors = 0;

        foreach (array_chunk($batch, 25) as $chunk) {
            $response = $this->client->query(['query' => $mutation, 'variables' => ['metafields' => $chunk]]);
            $body = json_decode($response->getBody()->getContents(), true);

            $userErrors = $body['data']['metafieldsSet']['userErrors'] ?? ($body['errors'] ?? []);
            if (! empty($userErrors)) {
                $msg = $this->formatGraphQLErrorMessage([
                    'userErrors' => $userErrors,
                    'errors' => $body['errors'] ?? [],
                ]);
                Log::warning('shopify:backfill-metafields userErrors', ['msg' => $msg, 'count' => count($chunk)]);
                $this->warn('userErrors: '.$msg);
                $totalUserErrors += count($userErrors);
            }
        }

        return $totalUserErrors;
    }
}
