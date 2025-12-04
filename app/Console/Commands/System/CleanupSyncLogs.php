<?php

namespace App\Console\Commands\System;

use App\Models\SyncRetryJob;
use App\Services\SyncFailureLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupSyncLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:cleanup-logs {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old sync failure logs and completed retry jobs based on configured retention periods';

    protected SyncFailureLogger $failureLogger;

    public function __construct(SyncFailureLogger $failureLogger)
    {
        parent::__construct();
        $this->failureLogger = $failureLogger;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        if (! config('sync.cleanup_enabled')) {
            $this->warn('⚠️  Sync log cleanup is disabled in config/sync.php');
            $this->info('Set SYNC_CLEANUP_ENABLED=true in .env to enable automatic cleanup.');

            return 0;
        }

        $this->info('🧹 Starting sync logs cleanup...');
        $this->newLine();

        // Cleanup failure logs
        $failureLogRetentionDays = config('sync.log_retention_days', 7);
        $this->info("📋 Cleaning up failure logs older than {$failureLogRetentionDays} days...");

        if ($isDryRun) {
            $failureLogsCount = \App\Models\SyncFailureLog::olderThan($failureLogRetentionDays)->count();
            $this->line("  Would delete: {$failureLogsCount} failure log(s)");
        } else {
            $deletedFailureLogs = $this->failureLogger->cleanupOldLogs();
            $this->line("  ✅ Deleted: {$deletedFailureLogs} failure log(s)");
            Log::info("Cleaned up {$deletedFailureLogs} old sync failure logs");
        }

        $this->newLine();

        // Cleanup completed retry jobs
        $retryJobRetentionDays = config('sync.retry_job_retention_days', 30);
        $this->info("🔄 Cleaning up completed retry jobs older than {$retryJobRetentionDays} days...");

        if ($isDryRun) {
            $retryJobsCount = SyncRetryJob::completedOlderThan($retryJobRetentionDays)->count();
            $this->line("  Would delete: {$retryJobsCount} retry job(s)");
        } else {
            $deletedRetryJobs = SyncRetryJob::completedOlderThan($retryJobRetentionDays)->delete();
            $this->line("  ✅ Deleted: {$deletedRetryJobs} retry job(s)");
            Log::info("Cleaned up {$deletedRetryJobs} old sync retry jobs");
        }

        $this->newLine();

        if ($isDryRun) {
            $this->info('🔍 Dry run completed - no records were actually deleted');
        } else {
            $this->info('✨ Cleanup completed successfully!');
        }

        return 0;
    }
}
