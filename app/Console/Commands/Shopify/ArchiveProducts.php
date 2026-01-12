<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProduct;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncLogger;
use App\Traits\ShopifyCleanupTrait;
use App\Traits\ShopifyErrorFormatterTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArchiveProducts extends Command
{
    use ShopifyCleanupTrait;
    use ShopifyErrorFormatterTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyArchiveProducts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive Shopify products with zero inventory using GraphQL';

    protected ShopifyGraphQLService $graphqlService;

    private SyncLogger $syncLogger;

    public function __construct(ShopifyGraphQLService $graphqlService)
    {
        parent::__construct();
        $this->graphqlService = $graphqlService;
        $this->syncLogger = new SyncLogger;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyArchiveProducts';

        // Acquire lock using locking system
        $job = SyncJobController::acquireLock($jobType, $marketplace);
        if (! $job) {
            $this->warn('Job is already running or paused.');
            Log::info("$marketplace $jobType: Cannot acquire lock (running or paused)");

            return Command::SUCCESS;
        }

        try {
            Log::info("$marketplace $jobType started!");

            $this->archiveZeroInventoryProducts($marketplace);

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
     * Archive products that have all variants with zero inventory
     */
    private function archiveZeroInventoryProducts(string $marketplace): void
    {
        // Find active products where ALL variants have zero inventory
        $sql = "SELECT
            sp.id AS pid,
            sp.title AS title,
            sp.product_id AS product_id,
            COUNT(spv.id) AS variant_count,
            sp.status
        FROM
            shopify_products sp
        LEFT JOIN
            shopify_product_variants spv ON sp.id = spv.shopify_product_id
        WHERE sp.status = 'active'
        GROUP BY
            sp.id, sp.title, sp.product_id, sp.status
        HAVING
            COUNT(spv.id) > 0 AND COUNT(spv.id) = SUM(CASE WHEN spv.inventory_quantity = 0 THEN 1 ELSE 0 END)
        ;";

        try {
            $products = DB::select($sql);
        } catch (\Throwable $e) {
            Log::error("shopifyArchiveProducts: Database query failed: {$e->getMessage()}");
            $this->error("Database query failed: {$e->getMessage()}");

            return;
        }

        $count = count($products);

        if ($count === 0) {
            $this->info('No products to archive.');

            return;
        }

        $this->info("Found {$count} product(s) to archive (all variants have zero inventory)");

        $successCount = 0;
        $failedCount = 0;
        $cleanedCount = 0;

        foreach ($products as $p) {
            try {
                // Validate product_id exists
                if (empty($p->product_id)) {
                    $failedCount++;

                    // Log skipped with SyncLogger
                    $this->syncLogger->logSkipped(
                        SyncLogger::MARKETPLACE_SHOPIFY,
                        'shopifyArchiveProducts',
                        "[pid:{$p->pid}]",
                        SyncLogger::OP_PRODUCT_ARCHIVE,
                        'Product has no product_id',
                        [
                            'item_title' => $p->title,
                            'context_data' => ['internal_pid' => $p->pid],
                        ]
                    );

                    continue;
                }

                $result = $this->graphqlService->updateProductStatus($p->product_id, 'ARCHIVED');

                if ($result['success']) {
                    ShopifyProduct::where('id', $p->pid)->update(['status' => 'archived']);

                    $this->info("Archived: {$p->title}");
                    $successCount++;

                    // Log success with SyncLogger
                    $this->syncLogger->logSuccess(
                        SyncLogger::MARKETPLACE_SHOPIFY,
                        'shopifyArchiveProducts',
                        "[product:{$p->product_id}]",
                        SyncLogger::OP_PRODUCT_ARCHIVE,
                        [
                            'item_title' => $p->title,
                            'message' => 'Product archived (zero inventory)',
                            'shopify_product_id' => $p->product_id,
                        ]
                    );
                } else {
                    $errorMessage = $this->formatGraphQLErrorMessage($result);

                    // Check if product no longer exists on Shopify - clean up stale record
                    if ($this->isResourceNotExistsError($errorMessage)) {
                        $this->cleanupStaleProduct($p, 'shopifyArchiveProducts');
                        $cleanedCount++;

                        // Log cleanup success with SyncLogger
                        $this->syncLogger->logSuccess(
                            SyncLogger::MARKETPLACE_SHOPIFY,
                            'shopifyArchiveProducts',
                            "[product:{$p->product_id}]",
                            SyncLogger::OP_DUPLICATE_CLEANUP,
                            [
                                'item_title' => $p->title,
                                'message' => 'Cleaned up stale product record (not found on Shopify)',
                                'shopify_product_id' => $p->product_id,
                            ]
                        );

                        continue;
                    }

                    $this->error("Failed to archive: {$p->title} - {$errorMessage}");
                    $failedCount++;

                    // Log failure with SyncLogger
                    $this->syncLogger->logFailure(
                        SyncLogger::MARKETPLACE_SHOPIFY,
                        'shopifyArchiveProducts',
                        "[product:{$p->product_id}]",
                        SyncLogger::OP_PRODUCT_ARCHIVE,
                        $errorMessage,
                        [
                            'item_title' => $p->title,
                            'shopify_product_id' => $p->product_id,
                            'errors' => array_merge($result['user_errors'] ?? [], $result['graphql_errors'] ?? []),
                        ]
                    );
                }
            } catch (\Throwable $e) {
                $this->error("Exception archiving product {$p->title}: {$e->getMessage()}");
                report($e);
                $failedCount++;

                // Log failure with SyncLogger
                $this->syncLogger->logFailure(
                    SyncLogger::MARKETPLACE_SHOPIFY,
                    'shopifyArchiveProducts',
                    "[product:{$p->product_id}]",
                    SyncLogger::OP_PRODUCT_ARCHIVE,
                    $e,
                    [
                        'item_title' => $p->title,
                        'shopify_product_id' => $p->product_id ?? null,
                    ]
                );
            }

            // Minimal delay between GraphQL calls (100ms)
            usleep(100000);
        }

        $summary = "Archive complete: {$successCount} succeeded, {$failedCount} failed";
        if ($cleanedCount > 0) {
            $summary .= ", {$cleanedCount} stale records cleaned";
        }
        $this->info($summary);

        // Individual operations logged via SyncLogger; job lifecycle logged by handle()
    }
}
