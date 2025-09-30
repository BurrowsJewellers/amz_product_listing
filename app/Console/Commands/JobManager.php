<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\SyncJobController;

class JobManager extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'job:manage
                            {action : Action to perform (pause|resume|status|pause-all|resume-all)}
                            {type? : Job type to manage}
                            {marketplace? : Marketplace (optional)}
                            {--by=cli : Who is performing the action}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage job pause/resume status and view job information';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        $type = $this->argument('type');
        $marketplace = $this->argument('marketplace');
        $by = $this->option('by');

        switch ($action) {
            case 'pause':
                $this->pauseJob($type, $marketplace, $by);
                break;

            case 'resume':
                $this->resumeJob($type, $marketplace, $by);
                break;

            case 'status':
                $this->showStatus($type);
                break;

            case 'pause-all':
                $this->pauseAllJobs($by);
                break;

            case 'resume-all':
                $this->resumeAllJobs($by);
                break;

            default:
                $this->error("Invalid action: {$action}");
                $this->info("Available actions: pause, resume, status, pause-all, resume-all");
                return 1;
        }

        return 0;
    }

    private function pauseJob($type, $marketplace, $by)
    {
        if (!$type) {
            $this->error("Job type is required for pause action");
            return;
        }

        $success = SyncJobController::pauseJob($type, $marketplace, $by);

        if ($success) {
            $jobName = $type . ($marketplace ? ":{$marketplace}" : '');
            $this->info("✅ Successfully paused job: {$jobName}");
        } else {
            $this->error("❌ Failed to pause job or job not found");
        }
    }

    private function resumeJob($type, $marketplace, $by)
    {
        if (!$type) {
            $this->error("Job type is required for resume action");
            return;
        }

        $success = SyncJobController::resumeJob($type, $marketplace, $by);

        if ($success) {
            $jobName = $type . ($marketplace ? ":{$marketplace}" : '');
            $this->info("✅ Successfully resumed job: {$jobName}");
        } else {
            $this->error("❌ Failed to resume job or job not found");
        }
    }

    private function showStatus($specificType = null)
    {
        $jobs = SyncJobController::getAllJobsStatus();

        if (empty($jobs)) {
            $this->info("No jobs found in the system.");
            return;
        }

        // Filter by specific type if provided
        if ($specificType) {
            $jobs = array_filter($jobs, function($job) use ($specificType) {
                return $job['type'] === $specificType;
            });

            if (empty($jobs)) {
                $this->warn("No jobs found for type: {$specificType}");
                return;
            }
        }

        // Prepare table data
        $tableData = [];
        foreach ($jobs as $key => $job) {
            $status = [];
            if ($job['is_running']) {
                $status[] = '🟡 Running';
            }
            if ($job['is_paused']) {
                $status[] = '🔴 Paused';
            }
            if (empty($status)) {
                $status[] = '🟢 Idle';
            }

            $tableData[] = [
                'Job' => $key,
                'Status' => implode(', ', $status),
                'Last Updated' => $job['last_updated'] ? $job['last_updated']->format('Y-m-d H:i:s') : 'Never',
                'Paused By' => $job['paused_by'] ?: '-',
                'Paused At' => $job['paused_at'] ? $job['paused_at']->format('Y-m-d H:i:s') : '-',
                'Message' => $this->truncateString($job['message'] ?: '-', 50),
            ];
        }

        $this->info('========================================');
        $this->info('Job Status Overview');
        $this->info('========================================');

        $this->table(
            ['Job', 'Status', 'Last Updated', 'Paused By', 'Paused At', 'Message'],
            $tableData
        );

        // Summary
        $runningCount = SyncJobController::getRunningJobsCount();
        $pausedCount = SyncJobController::getPausedJobsCount();
        $totalCount = count($jobs);

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Total jobs: {$totalCount}");
        $this->info("  Running: {$runningCount}");
        $this->info("  Paused: {$pausedCount}");
        $this->info("  Idle: " . ($totalCount - $runningCount - $pausedCount));
    }

    private function pauseAllJobs($by)
    {
        if (!$this->confirm('Are you sure you want to pause ALL jobs? This is an emergency stop.', false)) {
            $this->info('Operation cancelled.');
            return;
        }

        $count = SyncJobController::pauseAllJobs($by);
        $this->warn("🛑 Emergency stop: Paused {$count} jobs");
        $this->info("Use 'job:manage resume-all' to resume all jobs");
    }

    private function resumeAllJobs($by)
    {
        if (!$this->confirm('Are you sure you want to resume ALL jobs?', false)) {
            $this->info('Operation cancelled.');
            return;
        }

        $count = SyncJobController::resumeAllJobs($by);
        $this->info("✅ Resumed {$count} jobs");
    }

    private function truncateString($string, $length)
    {
        if (strlen($string) <= $length) {
            return $string;
        }
        return substr($string, 0, $length - 3) . '...';
    }
}