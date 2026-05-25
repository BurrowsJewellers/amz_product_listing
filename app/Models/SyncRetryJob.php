<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRetryJob extends Model
{
    protected $fillable = [
        'job_type',
        'triggered_by',
        'status',
        'total_items',
        'processed_items',
        'successful_items',
        'failed_items',
        'items_to_retry',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'items_to_retry' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the failure logs associated with this retry job
     */
    public function failureLogs(): HasMany
    {
        return $this->hasMany(SyncFailureLog::class, 'retry_job_id');
    }

    /**
     * Check if job is currently running
     */
    public function isRunning(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if job is completed
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, ['completed', 'failed']);
    }

    /**
     * Get progress percentage
     */
    public function getProgressPercentage(): float
    {
        if ($this->total_items == 0) {
            return 0;
        }

        return round(($this->processed_items / $this->total_items) * 100, 2);
    }

    /**
     * Get success rate percentage
     */
    public function getSuccessRate(): float
    {
        if ($this->processed_items == 0) {
            return 0;
        }

        return round(($this->successful_items / $this->processed_items) * 100, 2);
    }

    /**
     * Scope to get active (running) jobs
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Scope to get completed jobs older than specified days
     */
    public function scopeCompletedOlderThan($query, $days)
    {
        return $query->whereIn('status', ['completed', 'failed'])
            ->where('completed_at', '<', now()->subDays($days));
    }
}
