<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyAndSyncPrices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:verify-sync-prices
                            {--force : Force update all mismatches}
                            {--dry-run : Preview changes without updating}
                            {--limit=0 : Limit number of items to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify and sync prices between RetailEdge and Shopify, ensuring 100% accuracy';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopify:verify-sync-prices';

        // Acquire lock using locking system
        $job = SyncJobController::acquireLock($jobType, $marketplace);
        if (! $job) {
            $this->warn('Job is already running or paused.');
            Log::info("$marketplace $jobType: Cannot acquire lock (running or paused)");

            return Command::SUCCESS;
        }

        try {
            Log::info("$marketplace $jobType started!");

            $isDryRun = $this->option('dry-run');
            $isForce = $this->option('force');
            $limit = (int) $this->option('limit');

            $this->info('========================================');
            $this->info('Price & Inventory Verification Tool');
            $this->info('========================================');
            $this->info('Mode: '.($isDryRun ? 'DRY RUN (No changes will be made)' : ($isForce ? 'FORCE UPDATE' : 'NORMAL')));
            $this->newLine();

            // Statistics
            $stats = [
                'price_mismatches' => 0,
                'compare_price_mismatches' => 0,
                'inventory_mismatches' => 0,
                'special_price_removals' => 0,
                'total_checked' => 0,
                'total_flagged' => 0,
            ];

            // Find all mismatches using raw SQL for accuracy
            $query = '
            SELECT
                spv.id,
                spv.sku,
                spv.variant_id,
                spv.price as shopify_price,
                spv.compare_at_price as shopify_compare,
                spv.inventory_quantity as shopify_inventory,
                spv.price_requires_update,
                spv.inventory_requires_update,
                rep.price as retail_price,
                rep.compare_at_price as retail_compare,
                rep.quantity as retail_inventory,
                rep.retail_price1,
                rep.special_price,
                rep.special_price_end,
                CASE
                    WHEN spv.price != rep.price THEN 1
                    ELSE 0
                END as price_mismatch,
                CASE
                    WHEN IFNULL(spv.compare_at_price, 0) != IFNULL(rep.compare_at_price, 0) THEN 1
                    WHEN rep.compare_at_price = 0 AND spv.compare_at_price IS NOT NULL AND spv.compare_at_price > 0 THEN 1
                    ELSE 0
                END as compare_mismatch,
                CASE
                    WHEN spv.inventory_quantity != rep.quantity THEN 1
                    ELSE 0
                END as inventory_mismatch
            FROM shopify_product_variants spv
            JOIN retail_edge_products rep ON spv.sku = rep.sku
            WHERE spv.variant_id IS NOT NULL
            HAVING price_mismatch = 1 OR compare_mismatch = 1 OR inventory_mismatch = 1
        ';

            // Use parameter binding to prevent SQL injection
            $bindings = [];
            if ($limit > 0) {
                $query .= ' LIMIT ?';
                $bindings[] = $limit;
            }

            $mismatches = DB::select($query, $bindings);
            $stats['total_checked'] = ShopifyProductVariant::whereNotNull('variant_id')->count();

            if (empty($mismatches)) {
                $this->info('✅ All prices and inventory are in sync!');
                $this->info("Total variants checked: {$stats['total_checked']}");

                $job->finishJob();
                Log::info("$marketplace $jobType finished - all in sync!");

                return Command::SUCCESS;
            }

            $this->warn('Found '.count($mismatches).' mismatches out of '.$stats['total_checked'].' variants');
            $this->newLine();

            // Create a table for display
            $tableData = [];
            $updateIds = [];

            foreach ($mismatches as $item) {
                $priceChange = '';
                $compareChange = '';
                $inventoryChange = '';
                $issues = [];

                if ($item->price_mismatch) {
                    $stats['price_mismatches']++;
                    $priceChange = "{$item->shopify_price} → {$item->retail_price}";
                    $issues[] = 'Price';
                }

                if ($item->compare_mismatch) {
                    $stats['compare_price_mismatches']++;
                    $shopifyCompare = $item->shopify_compare ?? 'NULL';
                    $retailCompare = $item->retail_compare ?? 'NULL';
                    $compareChange = "{$shopifyCompare} → {$retailCompare}";
                    $issues[] = 'Compare';

                    // Check if this is a special price removal
                    if ($item->retail_compare == 0 && $item->shopify_compare > 0) {
                        $stats['special_price_removals']++;
                        $issues[] = 'SpecialRemoved';
                    }
                }

                if ($item->inventory_mismatch) {
                    $stats['inventory_mismatches']++;
                    $inventoryChange = "{$item->shopify_inventory} → {$item->retail_inventory}";
                    $issues[] = 'Inventory';
                }

                $tableData[] = [
                    'SKU' => $item->sku,
                    'Issues' => implode(', ', $issues),
                    'Price' => $priceChange ?: '-',
                    'Compare' => $compareChange ?: '-',
                    'Inventory' => $inventoryChange ?: '-',
                ];

                $updateIds[] = $item->id;

                // Log detailed information
                if (! $isDryRun) {
                    Log::info("Price/Inventory Verification - SKU: {$item->sku}", [
                        'sku' => $item->sku,
                        'price_mismatch' => $item->price_mismatch,
                        'compare_mismatch' => $item->compare_mismatch,
                        'inventory_mismatch' => $item->inventory_mismatch,
                        'shopify_price' => $item->shopify_price,
                        'retail_price' => $item->retail_price,
                        'shopify_compare' => $item->shopify_compare,
                        'retail_compare' => $item->retail_compare,
                        'shopify_inventory' => $item->shopify_inventory,
                        'retail_inventory' => $item->retail_inventory,
                    ]);
                }
            }

            // Display the mismatches table
            if (count($tableData) <= 20 || $this->confirm('Display all '.count($tableData).' mismatches?', false)) {
                $this->table(
                    ['SKU', 'Issues', 'Price Change', 'Compare Change', 'Inventory Change'],
                    array_slice($tableData, 0, 50)
                );
                if (count($tableData) > 50) {
                    $this->info('... and '.(count($tableData) - 50).' more items');
                }
            }

            // Display summary statistics
            $this->newLine();
            $this->info('Summary:');
            $this->info("  Price mismatches: {$stats['price_mismatches']}");
            $this->info("  Compare-at price mismatches: {$stats['compare_price_mismatches']}");
            $this->info("    (Special price removals: {$stats['special_price_removals']})");
            $this->info("  Inventory mismatches: {$stats['inventory_mismatches']}");
            $this->newLine();

            // Handle updates
            if ($isDryRun) {
                $this->info('🔍 DRY RUN MODE - No changes made');
                $this->info('Run without --dry-run to apply these changes');
            } else {
                if ($isForce || $this->confirm('Do you want to flag these items for update?', true)) {
                    $stats['total_flagged'] = $this->flagForUpdate($updateIds);
                    $this->info("✅ Flagged {$stats['total_flagged']} variants for update");
                    $this->newLine();
                    $this->info('Next steps:');
                    $this->info('1. Run: php artisan shopifyUpdatePriceInventory');
                    $this->info('   This will push the corrected prices to Shopify');
                } else {
                    $this->info('No changes made.');
                }
            }

            // Log the verification run
            Log::info('Price/Inventory Verification Completed', $stats);

            $job->finishJob();
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
     * Flag variants for update
     */
    private function flagForUpdate(array $variantIds): int
    {
        if (empty($variantIds)) {
            return 0;
        }

        // Validate and cast all IDs to integers for safety
        $variantIds = array_map('intval', $variantIds);

        // Create placeholders for parameter binding
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));

        // Update using raw SQL with parameter binding for safety
        // Only set flags - values will be updated after successful Shopify API sync
        $updatedCount = DB::update("
            UPDATE shopify_product_variants spv
            JOIN retail_edge_products rep ON spv.sku = rep.sku
            SET
                spv.price_requires_update = CASE
                    WHEN spv.price != rep.price
                        OR IFNULL(spv.compare_at_price, 0) != IFNULL(rep.compare_at_price, 0)
                        OR (rep.compare_at_price = 0 AND spv.compare_at_price IS NOT NULL AND spv.compare_at_price > 0)
                    THEN 1
                    ELSE spv.price_requires_update
                END,
                spv.inventory_requires_update = CASE
                    WHEN spv.inventory_quantity != rep.quantity THEN 1
                    ELSE spv.inventory_requires_update
                END,
                spv.updated_at = CURRENT_TIMESTAMP
            WHERE spv.id IN ({$placeholders})
        ", $variantIds);

        return $updatedCount;
    }

    /**
     * Additional method to check for orphaned special prices
     */
    public function checkOrphanedSpecialPrices()
    {
        $orphaned = DB::select('
            SELECT
                spv.sku,
                spv.price,
                spv.compare_at_price,
                rep.retail_price1,
                rep.special_price,
                rep.special_price_end
            FROM shopify_product_variants spv
            JOIN retail_edge_products rep ON spv.sku = rep.sku
            WHERE spv.compare_at_price IS NOT NULL
                AND spv.compare_at_price > 0
                AND (rep.special_price_end IS NULL OR rep.special_price_end < NOW())
        ');

        if (! empty($orphaned)) {
            $this->warn('Found '.count($orphaned).' products with compare_at_price but no active special price');
            foreach ($orphaned as $item) {
                $this->line("  SKU: {$item->sku} - Compare at: {$item->compare_at_price} (should be removed)");
            }
        }

        return count($orphaned);
    }
}
