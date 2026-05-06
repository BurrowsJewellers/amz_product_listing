<?php

namespace App\Console\Commands\Shopify;

use App\Services\ShopifyConnectionService;
use App\Traits\ShopifyErrorFormatterTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        // Implemented in Task 7
    }
}
