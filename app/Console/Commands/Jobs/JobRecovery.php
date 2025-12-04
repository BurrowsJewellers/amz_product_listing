<?php

namespace App\Console\Commands\Jobs;

use App\Http\Controllers\SyncJobController;
use App\Models\SyncJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class JobRecovery extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'job:recover {--dry-run : Show what would be recovered without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recover stuck jobs that have exceeded their timeout or have stale heartbeats';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->info('Checking for stuck jobs...');

        // Get all running jobs
        $runningJobs = SyncJob::where('status', 1)->get();

        if ($runningJobs->isEmpty()) {
            $this->info('No running jobs found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$runningJobs->count()} running job(s). Analyzing...");

        $stuckJobs = [];
        $activeJobs = [];

        foreach ($runningJobs as $job) {
            $isStuck = false;
            $reason = '';

            // Check if job is stuck by timeout
            if ($job->started_at) {
                $timeoutMinutes = $job->timeout_minutes ?? 30;
                $timeoutAt = $job->started_at->addMinutes($timeoutMinutes);

                if (now()->gt($timeoutAt)) {
                    $isStuck = true;
                    $reason = "Timeout exceeded (started: {$job->started_at}, timeout: {$timeoutMinutes}min)";
                }
            }

            // Check if heartbeat is stale (more than 5 minutes old)
            if (! $isStuck && $job->last_heartbeat) {
                $heartbeatStale = $job->last_heartbeat->addMinutes(5);
                if (now()->gt($heartbeatStale)) {
                    $isStuck = true;
                    $reason = "Heartbeat stale (last: {$job->last_heartbeat})";
                }
            }

            // Check if process is still running
            if (! $isStuck && $job->process_id && function_exists('posix_kill')) {
                if (! @posix_kill((int) $job->process_id, 0)) {
                    $isStuck = true;
                    $reason = "Process {$job->process_id} is not running";
                }
            }

            if ($isStuck) {
                $stuckJobs[] = ['job' => $job, 'reason' => $reason];
            } else {
                $activeJobs[] = $job;
            }
        }

        // Display active jobs
        if (! empty($activeJobs)) {
            $this->info("\nActive jobs (not stuck):");
            foreach ($activeJobs as $job) {
                $this->line("  - {$job->type} ({$job->marketplace}): started {$job->started_at}, PID: {$job->process_id}");
            }
        }

        // Display and recover stuck jobs
        if (empty($stuckJobs)) {
            $this->info("\nNo stuck jobs found.");

            return Command::SUCCESS;
        }

        $this->warn("\nFound ".count($stuckJobs).' stuck job(s):');

        foreach ($stuckJobs as $stuck) {
            $job = $stuck['job'];
            $reason = $stuck['reason'];

            $this->line("  - {$job->type} ({$job->marketplace}): {$reason}");

            if (! $isDryRun) {
                $previousMessage = $job->message;
                $job->finishJob('Auto-recovered: '.$reason.($previousMessage ? ". Previous: {$previousMessage}" : ''));

                Log::warning("Job recovery: Recovered stuck job {$job->type} ({$job->marketplace})", [
                    'reason' => $reason,
                    'started_at' => $job->started_at,
                    'last_heartbeat' => $job->last_heartbeat,
                    'process_id' => $job->process_id,
                ]);

                $this->info("    ✓ Recovered");
            } else {
                $this->info("    Would recover (dry-run)");
            }
        }

        $recoveredCount = $isDryRun ? 0 : count($stuckJobs);

        if ($recoveredCount > 0) {
            $this->info("\nRecovered {$recoveredCount} stuck job(s).");
            Log::info("Job recovery completed: Recovered {$recoveredCount} stuck job(s)");
        }

        return Command::SUCCESS;
    }
}
