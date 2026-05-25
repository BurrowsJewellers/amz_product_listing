<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncOperationLog extends Model
{
    protected $fillable = [
        'marketplace',
        'job_name',
        'item_identifier',
        'item_title',
        'operation_type',
        'status',
        'from_value',
        'to_value',
        'message',
        'api_request',
        'api_response',
        'errors',
        'context_data',
        'error_file',
        'error_line',
        'shopify_product_id',
        'shopify_variant_id',
        'amazon_asin',
        'retry_count',
        'last_retry_at',
    ];

    protected $casts = [
        'api_request' => 'array',
        'api_response' => 'array',
        'errors' => 'array',
        'context_data' => 'array',
        'last_retry_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the Shopify variant that this log belongs to
     */
    public function shopifyVariant(): BelongsTo
    {
        return $this->belongsTo(ShopifyProductVariant::class, 'shopify_variant_id');
    }

    /**
     * Get the Shopify product that this log belongs to
     */
    public function shopifyProduct(): BelongsTo
    {
        return $this->belongsTo(ShopifyProduct::class, 'shopify_product_id');
    }

    /**
     * Scope to get recent logs
     */
    public function scopeRecent($query, $limit = 50)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }

    /**
     * Scope to filter by marketplace
     */
    public function scopeByMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', $marketplace);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by operation type
     */
    public function scopeByOperationType($query, string $operationType)
    {
        return $query->where('operation_type', $operationType);
    }

    /**
     * Scope for successful operations
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope for failed operations
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to get logs older than specified days
     */
    public function scopeOlderThan($query, $days)
    {
        return $query->where('created_at', '<', now()->subDays($days));
    }

    /**
     * Scope to filter by date range
     */
    public function scopeDateRange($query, $from, $to = null)
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }
}
