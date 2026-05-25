<?php

namespace App\Console\Commands\Jobs;

use App\Http\Controllers\SyncJobController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class JobOrchestrator extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'job:orchestrator
                            {chain : Chain to execute (main-sync|shopify-sync)}
                            {--dry-run : Show what would be executed without running}
                            {--force : Force execution even if jobs are running}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Orchestrate job chains with proper dependency management and pause checking';

    /**
     * Job chain definitions
     */
    private $chains = [
        'main-sync' => [
            'description' => 'Main product sync chain',
            'marketplace' => 'EWeb',
            'jobs' => [
                'getProductsFromEWebMain',
                'shopify:verify-sync-prices',
                'shopify:update-price-inventory-batch',
            ],
            'job_args' => [
                'shopify:verify-sync-prices' => ['--force' => true],
            ],
        ],
        'shopify-sync' => [
            'description' => 'Shopify operations chain',
            'marketplace' => 'Shopify',
            'jobs' => [
                'shopifyGetProducts',                      // 1. Sync existing Shopify products to local DB
                'shopify:delete-duplicate-products',       // 2. Clean up duplicate parent products (consolidates parent SKU → one Shopify product)
                'shopify:delete-duplicate-variants',       // 3. Clean up duplicate child variants (relies on step 2 for deterministic parent lookup)
                'shopifyCreateProduct',                    // 4. Create new products (parents with children as variants)
                'shopifyUploadImages',                     // 5. Upload product images
                'shopifyArchiveProducts',                  // 6. Archive discontinued products
            ],
            'job_args' => [
                'shopify:delete-duplicate-products' => ['--force' => true],
                'shopify:delete-duplicate-variants' => ['--force' => true],
            ],
        ],
        'amazon-sync' => [
            'description' => 'Amazon operations chain',
            'marketplace' => 'Amazon',
            'jobs' => [
                'processAmzMerchantListingAllData', // 1. Download Amazon report & sync listings
                'getProductsFromEWebAmazon',        // 2. Import from RetailEdge & reset feed status on inventory change
                'generateAmzProductsJson',          // 3. Submit new product listings to Amazon
                'checkAmzFeedStatus',               // 4. Check feed submission status
                'amazonUpdateInventoryPrice',       // 5. Update inventory and prices for existing products
            ],
            'job_args' => [],
        ],
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $chainName = $this->argument('chain');
        $isDryRun = $this->option('dry-run');
        $isForce = $this->option('force');

        if (! isset($this->chains[$chainName])) {
            $this->error("Unknown chain: {$chainName}");
            $this->info('Available chains: '.implode(', ', array_keys($this->chains)));

            return 1;
        }

        $chain = $this->chains[$chainName];
        $this->info('========================================');
        $this->info("Job Orchestrator: {$chain['description']}");
        $this->info('========================================');

        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No jobs will be executed');
            $this->showChainPlan($chain);

            return 0;
        }

        // Check if chain can be started
        if (! $this->canStartChain($chain, $isForce)) {
            return 1;
        }

        // Execute the chain
        return $this->executeChain($chainName, $chain);
    }

    /**
     * Check if a chain can be started
     */
    private function canStartChain(array $chain, bool $force = false): bool
    {
        $marketplace = $chain['marketplace'] ?? null;

        // Check if any jobs in the chain are paused
        if (SyncJobController::isChainPaused($chain['jobs'], $marketplace)) {
            $this->error('❌ Cannot start chain: One or more jobs are paused');
            $this->info("Use 'php artisan job:manage status' to see paused jobs");
            $this->info("Use 'php artisan job:manage resume <job-type>' to resume individual jobs");

            return false;
        }

        // Check if any jobs in the chain are already running
        if (! $force && SyncJobController::isChainRunning($chain['jobs'], $marketplace)) {
            $this->error('❌ Cannot start chain: One or more jobs are already running');
            $this->info('Use --force to override this check (not recommended)');
            $this->info("Use 'php artisan job:manage status' to see running jobs");

            return false;
        }

        return true;
    }

    /**
     * Chain dependencies - define which chains must complete before another can start
     */
    private $chainDependencies = [
        'shopify-sync' => ['main-sync'], // shopify-sync waits for main-sync
    ];

    /**
     * Execute a job chain
     * Jobs continue to execute even if previous jobs fail (resilient execution)
     */
    private function executeChain(string $chainName, array $chain): int
    {
        $startTime = microtime(true);
        $totalJobs = count($chain['jobs']);
        $currentJob = 0;

        // Track results for summary
        $results = [
            'successful' => [],
            'failed' => [],
            'skipped' => [],
        ];

        // Wait for dependent chains to complete
        if (isset($this->chainDependencies[$chainName])) {
            foreach ($this->chainDependencies[$chainName] as $dependentChain) {
                if (! $this->waitForChainCompletion($dependentChain)) {
                    $this->error("❌ Timed out waiting for {$dependentChain} to complete");
                    Log::warning("Chain {$chainName} timed out waiting for {$dependentChain}");

                    return 1;
                }
            }
        }

        Log::info("Starting job chain: {$chainName}", [
            'chain' => $chainName,
            'total_jobs' => $totalJobs,
            'jobs' => $chain['jobs'],
        ]);

        foreach ($chain['jobs'] as $jobCommand) {
            $currentJob++;
            $this->info('');
            $this->info("📋 Step {$currentJob}/{$totalJobs}: {$jobCommand}");
            $this->info(str_repeat('─', 50));

            // Check if this specific job can start
            $marketplace = $chain['marketplace'] ?? null;
            if (! SyncJobController::canStart($jobCommand, $marketplace)) {
                if (SyncJobController::isPaused($jobCommand, $marketplace)) {
                    $this->warn("⏸️  Skipped (paused): {$jobCommand}");
                    $results['skipped'][] = ['job' => $jobCommand, 'reason' => 'paused'];
                    Log::warning("Chain {$chainName}: Job {$jobCommand} skipped (paused)");

                    continue; // Continue to next job
                } else {
                    $this->warn("⏭️  Skipped (already running): {$jobCommand}");
                    $results['skipped'][] = ['job' => $jobCommand, 'reason' => 'already_running'];
                    Log::warning("Chain {$chainName}: Job {$jobCommand} skipped (already running)");

                    continue; // Continue to next job
                }
            }

            // Get job arguments
            $args = $chain['job_args'][$jobCommand] ?? [];

            // Execute the job
            $jobStartTime = microtime(true);
            $exitCode = $this->call($jobCommand, $args);
            $jobDuration = microtime(true) - $jobStartTime;

            if ($exitCode === 0) {
                $this->info("✅ Completed: {$jobCommand} (".number_format($jobDuration, 2).'s)');
                $results['successful'][] = [
                    'job' => $jobCommand,
                    'duration' => $jobDuration,
                ];
            } else {
                $this->error("❌ Failed: {$jobCommand} (exit code: {$exitCode})");
                $results['failed'][] = [
                    'job' => $jobCommand,
                    'exit_code' => $exitCode,
                    'duration' => $jobDuration,
                ];
                Log::error("Chain {$chainName}: Job failed but continuing to next job", [
                    'job' => $jobCommand,
                    'exit_code' => $exitCode,
                    'duration' => $jobDuration,
                ]);
                // Continue to next job instead of returning
            }
        }

        $totalDuration = microtime(true) - $startTime;

        // Display chain summary
        $this->displayChainSummary($chainName, $results, $totalDuration);

        // Log final status
        $hasFailures = ! empty($results['failed']);
        if ($hasFailures) {
            Log::warning("Job chain completed with failures: {$chainName}", [
                'total_duration' => $totalDuration,
                'successful' => count($results['successful']),
                'failed' => count($results['failed']),
                'skipped' => count($results['skipped']),
                'failed_jobs' => array_column($results['failed'], 'job'),
            ]);
        } else {
            Log::info("Job chain completed successfully: {$chainName}", [
                'total_duration' => $totalDuration,
                'jobs_executed' => count($results['successful']),
                'skipped' => count($results['skipped']),
            ]);
        }

        // Return failure code if any job failed
        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Display a summary of the chain execution
     */
    private function displayChainSummary(string $chainName, array $results, float $totalDuration): void
    {
        $this->info('');
        $this->info(str_repeat('═', 50));
        $this->info("📊 Chain Summary: {$chainName}");
        $this->info(str_repeat('═', 50));

        $successCount = count($results['successful']);
        $failedCount = count($results['failed']);
        $skippedCount = count($results['skipped']);
        $totalJobs = $successCount + $failedCount + $skippedCount;

        $this->info("Total jobs: {$totalJobs}");
        $this->info("✅ Successful: {$successCount}");

        if ($failedCount > 0) {
            $this->error("❌ Failed: {$failedCount}");
            foreach ($results['failed'] as $failed) {
                $this->error("   - {$failed['job']} (exit code: {$failed['exit_code']})");
            }
        } else {
            $this->info('❌ Failed: 0');
        }

        if ($skippedCount > 0) {
            $this->warn("⏭️  Skipped: {$skippedCount}");
            foreach ($results['skipped'] as $skipped) {
                $this->warn("   - {$skipped['job']} ({$skipped['reason']})");
            }
        }

        $this->info('');
        $this->info('Total duration: '.number_format($totalDuration, 2).'s');

        if ($failedCount === 0 && $skippedCount === 0) {
            $this->info('🎉 Chain completed successfully!');
        } elseif ($failedCount === 0) {
            $this->info('✅ Chain completed (some jobs skipped)');
        } else {
            $this->error('⚠️  Chain completed with failures - check logs for details');
        }
    }

    /**
     * Show what would be executed in dry-run mode
     */
    private function showChainPlan(array $chain): void
    {
        $this->info("Chain: {$chain['description']}");
        $this->info('Marketplace: '.($chain['marketplace'] ?? 'N/A'));
        $this->newLine();

        $this->info('Jobs to execute:');
        foreach ($chain['jobs'] as $index => $jobCommand) {
            $step = $index + 1;
            $args = $chain['job_args'][$jobCommand] ?? [];
            $argsStr = $args ? ' '.$this->formatArgs($args) : '';

            $marketplace = $chain['marketplace'] ?? null;
            $canStart = SyncJobController::canStart($jobCommand, $marketplace);
            $status = $canStart ? '🟢' : '🔴';

            $this->line("  {$step}. {$status} {$jobCommand}{$argsStr}");

            if (! $canStart) {
                if (SyncJobController::isPaused($jobCommand, $marketplace)) {
                    $this->line('      ⚠️ Job is paused');
                } else {
                    $this->line('      ⚠️ Job is running');
                }
            }
        }

        $this->newLine();
        $this->info('Use without --dry-run to execute the chain');
    }

    /**
     * Format command arguments for display
     */
    private function formatArgs(array $args): string
    {
        $formatted = [];
        foreach ($args as $key => $value) {
            if (is_bool($value) && $value) {
                $formatted[] = $key;
            } elseif (! is_bool($value)) {
                $formatted[] = "{$key}={$value}";
            }
        }

        return implode(' ', $formatted);
    }

    /**
     * Wait for a chain to complete before proceeding
     *
     * @param  string  $chainName  The chain to wait for
     * @param  int  $maxWaitSeconds  Maximum time to wait (default 5 minutes)
     * @return bool True if chain completed, false if timeout
     */
    private function waitForChainCompletion(string $chainName, int $maxWaitSeconds = 300): bool
    {
        if (! isset($this->chains[$chainName])) {
            return true; // Unknown chain, don't wait
        }

        $chain = $this->chains[$chainName];
        $marketplace = $chain['marketplace'] ?? null;
        $waitInterval = 10; // Check every 10 seconds
        $totalWaitTime = 0;

        while ($totalWaitTime < $maxWaitSeconds) {
            if (! SyncJobController::isChainRunning($chain['jobs'], $marketplace)) {
                if ($totalWaitTime > 0) {
                    $this->info("✅ {$chainName} completed. Proceeding...");
                    Log::info("Chain dependency satisfied: {$chainName} completed after {$totalWaitTime}s wait");
                }

                return true;
            }

            $this->info("⏳ Waiting for {$chainName} to complete... ({$totalWaitTime}s elapsed)");
            Log::info("Waiting for chain {$chainName} to complete. Wait time: {$totalWaitTime}s");

            sleep($waitInterval);
            $totalWaitTime += $waitInterval;
        }

        return false;
    }
}
