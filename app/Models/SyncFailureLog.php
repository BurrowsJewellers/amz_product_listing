<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncFailureLog extends Model
{
    protected $fillable = [
        'marketplace',
        'job_name',
        'item_identifier',
        'operation_type',
        'flag_value',
        'error_message',
        'api_request',
        'api_response',
        'user_errors',
        'graphql_errors',
        'current_data',
        'target_data',
        'error_file',
        'error_line',
        'variant_id',
        'retry_job_id',
    ];

    protected $casts = [
        'api_request' => 'array',
        'api_response' => 'array',
        'user_errors' => 'array',
        'graphql_errors' => 'array',
        'current_data' => 'array',
        'target_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the variant that this failure belongs to
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ShopifyProductVariant::class, 'variant_id');
    }

    /**
     * Get the retry job that this failure is linked to
     */
    public function retryJob(): BelongsTo
    {
        return $this->belongsTo(SyncRetryJob::class, 'retry_job_id');
    }

    /**
     * Scope to get recent failures
     */
    public function scopeRecent($query, $limit = 50)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }

    /**
     * Scope to get failures by flag
     */
    public function scopeByFlag($query, $flag)
    {
        return $query->where('flag_value', $flag);
    }

    /**
     * Scope to get failures for cleanup (older than retention days)
     */
    public function scopeOlderThan($query, $days)
    {
        return $query->where('created_at', '<', now()->subDays($days));
    }
}
