<?php

namespace App\Http\Controllers;

use App\Models\SyncJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SyncJobController extends Controller
{
    public static function markAsFinished($id)
    {
        return SyncJob::where('id', $id)->update(['status' => 0]);
    }

    public static function getJob($type, $marketplace): SyncJob
    {
        return SyncJob::firstOrCreate(['type' => $type, 'marketplace' => $marketplace]);
    }

    /**
     * Check if a job is currently paused
     */
    public static function isPaused($type, $marketplace = null): bool
    {
        $query = SyncJob::where('type', $type);
        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }

        $job = $query->first();

        return $job ? $job->is_paused : false;
    }

    /**
     * Check if a job is currently running
     */
    public static function isRunning($type, $marketplace = null): bool
    {
        $query = SyncJob::where('type', $type)->where('status', 1);
        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }

        return $query->exists();
    }

    /**
     * Check if a job can be started (not paused and not running)
     */
    public static function canStart($type, $marketplace = null): bool
    {
        return ! self::isPaused($type, $marketplace) && ! self::isRunning($type, $marketplace);
    }

    /**
     * Pause a job
     */
    public static function pauseJob($type, $marketplace = null, $pausedBy = 'system'): bool
    {
        $query = SyncJob::where('type', $type);
        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }

        $updated = $query->update([
            'is_paused' => true,
            'paused_at' => Carbon::now(),
            'paused_by' => $pausedBy,
        ]);

        if ($updated) {
            Log::info("Job paused: {$type}".($marketplace ? " ({$marketplace})" : '')." by {$pausedBy}");
        }

        return $updated > 0;
    }

    /**
     * Resume a job
     */
    public static function resumeJob($type, $marketplace = null, $resumedBy = 'system'): bool
    {
        $query = SyncJob::where('type', $type);
        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }

        $updated = $query->update([
            'is_paused' => false,
            'paused_at' => null,
            'paused_by' => null,
        ]);

        if ($updated) {
            Log::info("Job resumed: {$type}".($marketplace ? " ({$marketplace})" : '')." by {$resumedBy}");
        }

        return $updated > 0;
    }

    /**
     * Get all jobs with their status
     */
    public static function getAllJobsStatus(): array
    {
        $jobs = SyncJob::all();
        $status = [];

        foreach ($jobs as $job) {
            $key = $job->type.($job->marketplace ? ":{$job->marketplace}" : '');
            $status[$key] = [
                'type' => $job->type,
                'marketplace' => $job->marketplace,
                'is_running' => $job->status == 1,
                'is_paused' => $job->is_paused,
                'paused_at' => $job->paused_at,
                'paused_by' => $job->paused_by,
                'last_updated' => $job->updated_at,
                'message' => $job->message,
            ];
        }

        return $status;
    }

    /**
     * Pause all jobs (emergency stop)
     */
    public static function pauseAllJobs($pausedBy = 'system'): int
    {
        $updated = SyncJob::update([
            'is_paused' => true,
            'paused_at' => Carbon::now(),
            'paused_by' => $pausedBy,
        ]);

        Log::warning("All jobs paused by {$pausedBy}");

        return $updated;
    }

    /**
     * Resume all jobs
     */
    public static function resumeAllJobs($resumedBy = 'system'): int
    {
        $updated = SyncJob::update([
            'is_paused' => false,
            'paused_at' => null,
            'paused_by' => null,
        ]);

        Log::info("All jobs resumed by {$resumedBy}");

        return $updated;
    }

    /**
     * Get running jobs count
     */
    public static function getRunningJobsCount(): int
    {
        return SyncJob::where('status', 1)->count();
    }

    /**
     * Get paused jobs count
     */
    public static function getPausedJobsCount(): int
    {
        return SyncJob::where('is_paused', true)->count();
    }

    /**
     * Check if any jobs are running in a specific chain
     */
    public static function isChainRunning(array $jobTypes, $marketplace = null): bool
    {
        $query = SyncJob::whereIn('type', $jobTypes)->where('status', 1);
        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }

        return $query->exists();
    }

    /**
     * Check if any jobs are paused in a specific chain
     */
    public static function isChainPaused(array $jobTypes, $marketplace = null): bool
    {
        $query = SyncJob::whereIn('type', $jobTypes)->where('is_paused', true);
        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }

        return $query->exists();
    }

    /**
     * Attempt to acquire a lock for a job
     * Returns the job if lock acquired, null if not
     */
    public static function acquireLock($type, $marketplace): ?SyncJob
    {
        $job = self::getJob($type, $marketplace);

        // Check if job is paused
        if ($job->is_paused) {
            Log::info("Job {$type} ({$marketplace}) is paused, cannot acquire lock");

            return null;
        }

        // Check if lock can be acquired
        if (! $job->canAcquireLock()) {
            Log::info("Job {$type} ({$marketplace}) is already running, cannot acquire lock");

            return null;
        }

        // Acquire the lock
        $job->startJob();
        Log::info("Job {$type} ({$marketplace}) lock acquired, process ID: ".getmypid());

        return $job;
    }

    /**
     * Recover stuck jobs that have exceeded their timeout or have stale heartbeats
     * Returns the number of jobs recovered
     */
    public static function recoverStuckJobs(): int
    {
        $recovered = 0;

        // Find all running jobs
        $runningJobs = SyncJob::where('status', 1)->get();

        foreach ($runningJobs as $job) {
            // Check if job is stuck
            if (! $job->isStuck()) {
                // Check if process is still running (only if we have a process_id)
                if ($job->process_id && function_exists('posix_kill')) {
                    if (@posix_kill((int) $job->process_id, 0)) {
                        // Process is still running, skip
                        continue;
                    }
                } else {
                    // Can't verify process status, skip if not stuck by time
                    continue;
                }
            }

            // Job is stuck, recover it
            $previousMessage = $job->message;
            $job->finishJob('Auto-recovered: stuck job timeout at '.now().($previousMessage ? ". Previous: {$previousMessage}" : ''));

            Log::warning("Auto-recovered stuck job: {$job->type} ({$job->marketplace})", [
                'started_at' => $job->started_at,
                'last_heartbeat' => $job->last_heartbeat,
                'process_id' => $job->process_id,
                'timeout_minutes' => $job->timeout_minutes,
            ]);

            $recovered++;
        }

        return $recovered;
    }

    /**
     * Get all stuck jobs
     */
    public static function getStuckJobs(): array
    {
        $stuckJobs = [];
        $runningJobs = SyncJob::where('status', 1)->get();

        foreach ($runningJobs as $job) {
            if ($job->isStuck()) {
                $stuckJobs[] = $job;
            }
        }

        return $stuckJobs;
    }
}
