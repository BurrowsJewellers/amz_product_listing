<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'marketplace',
        'message',
        'status',
        'started_at',
        'last_heartbeat',
        'process_id',
        'timeout_minutes',
        'is_paused',
        'paused_at',
        'paused_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_heartbeat' => 'datetime',
        'paused_at' => 'datetime',
        'is_paused' => 'boolean',
    ];

    public function isRunning(): bool
    {
        return $this->status == 1;
    }

    /**
     * Start the job and acquire the lock
     */
    public function startJob(): void
    {
        $this->update([
            'status' => 1,
            'started_at' => now(),
            'last_heartbeat' => now(),
            'process_id' => getmypid(),
            'message' => null,
        ]);
    }

    /**
     * Update the heartbeat timestamp to indicate the job is still alive
     */
    public function updateHeartbeat(): void
    {
        $this->update(['last_heartbeat' => now()]);
    }

    /**
     * Finish the job and release the lock
     */
    public function finishJob(?string $message = null): void
    {
        $this->update([
            'status' => 0,
            'started_at' => null,
            'last_heartbeat' => null,
            'process_id' => null,
            'message' => $message ? substr($message, 0, 590) : null,
        ]);
    }

    /**
     * Check if the job is stuck (exceeded timeout or heartbeat is stale)
     */
    public function isStuck(): bool
    {
        if ($this->status !== 1) {
            return false;
        }

        $timeoutMinutes = $this->timeout_minutes ?? 30;
        $timeoutAt = $this->started_at?->addMinutes($timeoutMinutes);
        $heartbeatStale = $this->last_heartbeat?->addMinutes(5);

        return ($timeoutAt && now()->gt($timeoutAt))
            || ($heartbeatStale && now()->gt($heartbeatStale));
    }

    /**
     * Check if the lock can be acquired (job is not running or is stuck)
     */
    public function canAcquireLock(): bool
    {
        // Job is not running
        if (! $this->isRunning()) {
            return true;
        }

        // Job is stuck (timeout exceeded or heartbeat stale)
        if ($this->isStuck()) {
            return true;
        }

        // Check if the process that owns the lock is still running
        if ($this->process_id) {
            // posix_kill with signal 0 checks if process exists
            if (function_exists('posix_kill') && ! @posix_kill((int) $this->process_id, 0)) {
                // Process is not running, lock can be acquired
                return true;
            }
        }

        return false;
    }
}
