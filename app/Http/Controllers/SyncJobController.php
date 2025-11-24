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
}
