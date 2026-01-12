<?php

namespace App\Services;

use App\Models\SyncOperationLog;
use Exception;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncLogger
{
    // Operation type constants
    public const OP_PRODUCT_CREATE = 'product_create';

    public const OP_PRODUCT_UPDATE = 'product_update';

    public const OP_PRODUCT_DELETE = 'product_delete';

    public const OP_PRODUCT_ARCHIVE = 'product_archive';

    public const OP_PRODUCT_SYNC = 'product_sync';

    public const OP_VARIANT_CREATE = 'variant_create';

    public const OP_VARIANT_UPDATE = 'variant_update';

    public const OP_VARIANT_DELETE = 'variant_delete';

    public const OP_DUPLICATE_CLEANUP = 'duplicate_cleanup';

    public const OP_PRICE_UPDATE = 'price_update';

    public const OP_INVENTORY_UPDATE = 'inventory_update';

    public const OP_PRICE_INVENTORY_UPDATE = 'price_inventory_update';

    public const OP_IMAGE_UPLOAD = 'image_upload';

    public const OP_IMAGE_DELETE = 'image_delete';

    public const OP_METAFIELD_UPDATE = 'metafield_update';

    public const OP_FEED_SUBMIT = 'feed_submit';

    public const OP_FEED_STATUS_CHECK = 'feed_status_check';

    // Status constants
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SKIPPED = 'skipped';

    // Marketplace constants
    public const MARKETPLACE_SHOPIFY = 'Shopify';

    public const MARKETPLACE_AMAZON = 'Amazon';

    /**
     * Log a sync operation (success or failure)
     */
    public function log(
        string $marketplace,
        string $jobName,
        string $itemIdentifier,
        string $operationType,
        string $status,
        array $options = []
    ): ?SyncOperationLog {
        try {
            $logData = [
                'marketplace' => $marketplace,
                'job_name' => $jobName,
                'item_identifier' => $itemIdentifier,
                'operation_type' => $operationType,
                'status' => $status,
                'item_title' => $options['item_title'] ?? null,
                'from_value' => $options['from_value'] ?? null,
                'to_value' => $options['to_value'] ?? null,
                'message' => $options['message'] ?? null,
                'api_request' => $options['api_request'] ?? null,
                'api_response' => $options['api_response'] ?? null,
                'errors' => $options['errors'] ?? null,
                'context_data' => $options['context_data'] ?? null,
                'error_file' => $options['error_file'] ?? null,
                'error_line' => $options['error_line'] ?? null,
                'shopify_product_id' => $options['shopify_product_id'] ?? null,
                'shopify_variant_id' => $options['shopify_variant_id'] ?? null,
                'amazon_asin' => $options['amazon_asin'] ?? null,
                'retry_count' => $options['retry_count'] ?? 0,
                'last_retry_at' => $options['last_retry_at'] ?? null,
            ];

            $log = SyncOperationLog::create($logData);

            // Log to Laravel for immediate visibility
            $logMethod = $status === self::STATUS_FAILED ? 'error' : 'info';
            Log::$logMethod("Sync operation: {$operationType} [{$status}]", [
                'log_id' => $log->id,
                'marketplace' => $marketplace,
                'item' => $itemIdentifier,
                'message' => $options['message'] ?? null,
            ]);

            return $log;
        } catch (Exception $e) {
            Log::error('Failed to create SyncOperationLog entry', [
                'error' => $e->getMessage(),
                'marketplace' => $marketplace,
                'item' => $itemIdentifier,
                'operation' => $operationType,
            ]);

            return null;
        }
    }

    /**
     * Log a successful operation
     */
    public function logSuccess(
        string $marketplace,
        string $jobName,
        string $itemIdentifier,
        string $operationType,
        array $options = []
    ): ?SyncOperationLog {
        return $this->log($marketplace, $jobName, $itemIdentifier, $operationType, self::STATUS_SUCCESS, $options);
    }

    /**
     * Log a failed operation
     */
    public function logFailure(
        string $marketplace,
        string $jobName,
        string $itemIdentifier,
        string $operationType,
        Throwable|string $error,
        array $options = []
    ): ?SyncOperationLog {
        $errorMessage = $error instanceof Throwable ? $error->getMessage() : $error;
        $errorFile = $error instanceof Throwable ? $error->getFile() : ($options['error_file'] ?? null);
        $errorLine = $error instanceof Throwable ? $error->getLine() : ($options['error_line'] ?? null);

        $options['message'] = $errorMessage;
        $options['error_file'] = $errorFile;
        $options['error_line'] = $errorLine;

        return $this->log($marketplace, $jobName, $itemIdentifier, $operationType, self::STATUS_FAILED, $options);
    }

    /**
     * Log a skipped operation
     */
    public function logSkipped(
        string $marketplace,
        string $jobName,
        string $itemIdentifier,
        string $operationType,
        string $reason,
        array $options = []
    ): ?SyncOperationLog {
        $options['message'] = $reason;

        return $this->log($marketplace, $jobName, $itemIdentifier, $operationType, self::STATUS_SKIPPED, $options);
    }

    /**
     * Get statistics for dashboard
     */
    public function getStatistics(?string $marketplace = null): array
    {
        $query = SyncOperationLog::query();

        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }

        $total = (clone $query)->count();
        $successful = (clone $query)->where('status', self::STATUS_SUCCESS)->count();
        $failed = (clone $query)->where('status', self::STATUS_FAILED)->count();
        $skipped = (clone $query)->where('status', self::STATUS_SKIPPED)->count();

        $today = (clone $query)->whereDate('created_at', today())->count();
        $thisWeek = (clone $query)->where('created_at', '>=', now()->subWeek())->count();
        $failedToday = (clone $query)->where('status', self::STATUS_FAILED)
            ->whereDate('created_at', today())->count();

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => $failed,
            'skipped' => $skipped,
            'success_rate' => $total > 0 ? round(($successful / $total) * 100, 2) : 0,
            'today' => $today,
            'this_week' => $thisWeek,
            'failed_today' => $failedToday,
        ];
    }

    /**
     * Get operation breakdown by type
     */
    public function getOperationBreakdown(?string $marketplace = null): array
    {
        $query = SyncOperationLog::query();

        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }

        $breakdown = [];
        foreach (self::getOperationTypes() as $type) {
            $typeQuery = clone $query;
            $typeQuery->where('operation_type', $type);
            $breakdown[$type] = [
                'total' => (clone $typeQuery)->count(),
                'successful' => (clone $typeQuery)->where('status', self::STATUS_SUCCESS)->count(),
                'failed' => (clone $typeQuery)->where('status', self::STATUS_FAILED)->count(),
            ];
        }

        return $breakdown;
    }

    /**
     * Get marketplace breakdown
     */
    public function getMarketplaceBreakdown(): array
    {
        return [
            'Shopify' => $this->getStatistics(self::MARKETPLACE_SHOPIFY),
            'Amazon' => $this->getStatistics(self::MARKETPLACE_AMAZON),
        ];
    }

    /**
     * Get recent failures for attention
     */
    public function getRecentFailures(int $limit = 50, ?string $marketplace = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = SyncOperationLog::where('status', self::STATUS_FAILED)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }

        return $query->get();
    }

    /**
     * Clean up old logs
     */
    public function cleanupOldLogs(): int
    {
        if (! config('sync.cleanup_enabled')) {
            return 0;
        }

        $days = config('sync.log_retention_days', 30);

        return SyncOperationLog::olderThan($days)->delete();
    }

    /**
     * Get all available operation types
     */
    public static function getOperationTypes(): array
    {
        return [
            self::OP_PRODUCT_CREATE,
            self::OP_PRODUCT_UPDATE,
            self::OP_PRODUCT_DELETE,
            self::OP_PRODUCT_ARCHIVE,
            self::OP_PRODUCT_SYNC,
            self::OP_VARIANT_CREATE,
            self::OP_VARIANT_UPDATE,
            self::OP_VARIANT_DELETE,
            self::OP_DUPLICATE_CLEANUP,
            self::OP_PRICE_UPDATE,
            self::OP_INVENTORY_UPDATE,
            self::OP_PRICE_INVENTORY_UPDATE,
            self::OP_IMAGE_UPLOAD,
            self::OP_IMAGE_DELETE,
            self::OP_METAFIELD_UPDATE,
            self::OP_FEED_SUBMIT,
            self::OP_FEED_STATUS_CHECK,
        ];
    }

    /**
     * Get all available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_PENDING,
            self::STATUS_SKIPPED,
        ];
    }

    /**
     * Get all available marketplaces
     */
    public static function getMarketplaces(): array
    {
        return [
            self::MARKETPLACE_SHOPIFY,
            self::MARKETPLACE_AMAZON,
        ];
    }
}
