<?php

namespace App\Console\Commands;

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
                            {chain : Chain to execute (main-sync|amazon-sync|shopify-sync)}
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
                'shopifyUpdatePriceInventory',
            ],
            'job_args' => [
                'shopify:verify-sync-prices' => ['--force' => true],
            ],
        ],
        'amazon-sync' => [
            'description' => 'Amazon operations chain',
            'marketplace' => 'Amazon',
            'jobs' => [
                'generateAmzProductsJson',
                'amazonUpdateInventoryPrice',
                'getAmzMerchantListingAllData',
                'processAmzMerchantListingAllData',
            ],
        ],
        'shopify-sync' => [
            'description' => 'Shopify operations chain',
            'marketplace' => 'Shopify',
            'jobs' => [
                'shopifyGetProducts',
                'shopifyCreateProduct',
                'shopifyUploadImages',
                'shopifyArchiveProducts',
            ],
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
     * Execute a job chain
     */
    private function executeChain(string $chainName, array $chain): int
    {
        $startTime = microtime(true);
        $totalJobs = count($chain['jobs']);
        $currentJob = 0;

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
                    $this->error("❌ Job is paused: {$jobCommand}");
                    Log::error("Chain {$chainName} stopped: Job {$jobCommand} is paused");

                    return 1;
                } else {
                    $this->error("❌ Job is already running: {$jobCommand}");
                    Log::error("Chain {$chainName} stopped: Job {$jobCommand} is already running");

                    return 1;
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
            } else {
                $this->error("❌ Failed: {$jobCommand} (exit code: {$exitCode})");
                Log::error("Chain {$chainName} failed at job: {$jobCommand}", [
                    'exit_code' => $exitCode,
                    'duration' => $jobDuration,
                ]);

                return $exitCode;
            }
        }

        $totalDuration = microtime(true) - $startTime;
        $this->info('');
        $this->info('🎉 Chain completed successfully!');
        $this->info('Total duration: '.number_format($totalDuration, 2).'s');

        Log::info("Job chain completed successfully: {$chainName}", [
            'total_duration' => $totalDuration,
            'jobs_executed' => $totalJobs,
        ]);

        return 0;
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
}
