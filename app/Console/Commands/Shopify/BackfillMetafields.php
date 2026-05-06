<?php

namespace App\Console\Commands\Shopify;

use App\Models\RetailEdgeProduct;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyProduct;
use App\Services\MetafieldAssignmentService;
use App\Services\ShopifyConnectionService;
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

    public function __construct(private ShopifyConnectionService $connectionService)
    {
        parent::__construct();
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

        $products = $this->collectProducts($sku, $limit);
        $this->info('Products in scope: '.$products->count());

        $stats = ['scanned' => 0, 'product_writes' => 0, 'variant_writes' => 0, 'errors' => 0];

        foreach ($products as $row) {
            $stats['scanned']++;
            try {
                $this->processProduct($row, $dryRun, $stats);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("Failed to process product GID={$row->gid}: ".$e->getMessage());
                Log::error('shopify:backfill-metafields failure', ['gid' => $row->gid, 'exception' => $e]);
            }
        }

        $rows = [];
        foreach ($stats as $k => $v) {
            $rows[] = [$k, $v];
        }
        $this->table(['metric', 'value'], $rows);

        return self::SUCCESS;
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

        $service = new MetafieldAssignmentService;
        $assignment = $service->determineMetafieldAssignment($parent);

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

        $this->writeBatch($batch);
        $stats['product_writes'] += $productCount;
        $stats['variant_writes'] += $variantCount;
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

        // Product-level entries
        foreach ($assignment['product_metafields'] as $mf) {
            $def = ShopifyMetafield::where('name', $mf['isd_name'])
                ->where('owner_type', 'PRODUCT')
                ->first();
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

        // Variant-level ISD entries
        foreach ($assignment['variant_metafields'] as $variantSku => $metafields) {
            $variantRow = $shopifyProduct->variants->firstWhere('sku', $variantSku);
            if (! $variantRow || empty($variantRow->variant_id)) {
                continue;
            }
            $ownerId = "gid://shopify/ProductVariant/{$variantRow->variant_id}";

            foreach ($metafields as $mf) {
                $def = ShopifyMetafield::where('name', $mf['isd_name'])
                    ->where('owner_type', 'PRODUCTVARIANT')
                    ->first();
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
        $designDef = ShopifyMetafield::where('namespace', 'custom')
            ->where('key', 'design_number_variant')
            ->where('owner_type', 'PRODUCTVARIANT')
            ->first();

        if ($designDef) {
            foreach ($shopifyProduct->variants as $variantRow) {
                if (empty($variantRow->variant_id) || empty($variantRow->sku)) {
                    continue;
                }
                $rep = RetailEdgeProduct::where('sku', $variantRow->sku)->first();
                if (! $rep || empty($rep->real_design_number)) {
                    continue;
                }
                $batch[] = [
                    'ownerId' => "gid://shopify/ProductVariant/{$variantRow->variant_id}",
                    'namespace' => $designDef->namespace,
                    'key' => $designDef->key,
                    'type' => $designDef->type,
                    'value' => (string) $rep->real_design_number,
                ];
            }
        }

        return $batch;
    }

    private function writeBatch(array $batch): void
    {
        $session = $this->connectionService->getSession();
        $client = new Graphql($session->getShop(), $session->getAccessToken());

        $mutation = <<<'GRAPHQL'
    mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) {
        metafields { id namespace key value ownerType }
        userErrors { field message }
      }
    }
    GRAPHQL;

        foreach (array_chunk($batch, 25) as $chunk) {
            $response = $client->query(['query' => $mutation, 'variables' => ['metafields' => $chunk]]);
            $body = json_decode($response->getBody()->getContents(), true);

            $userErrors = $body['data']['metafieldsSet']['userErrors'] ?? ($body['errors'] ?? []);
            if (! empty($userErrors)) {
                $msg = $this->formatGraphQLErrorMessage([
                    'userErrors' => $userErrors,
                    'errors' => $body['errors'] ?? [],
                ]);
                Log::warning('shopify:backfill-metafields userErrors', ['msg' => $msg, 'count' => count($chunk)]);
                $this->warn('userErrors: '.$msg);
            }
        }
    }
}
