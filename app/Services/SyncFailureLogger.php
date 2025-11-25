<?php

namespace App\Services;

use App\Models\ShopifyProductVariant;
use App\Models\SyncFailureLog;
use Exception;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncFailureLogger
{
    /**
     * Log a sync failure with complete details
     *
     * @param  ShopifyProductVariant  $variant  The variant that failed to update
     * @param  string  $operationType  'price', 'inventory', or 'price_inventory'
     * @param  Throwable|string  $error  The exception or error message
     * @param  array|null  $apiResponse  Full API response (if available)
     * @param  array  $context  Additional context (api_request, current_data, target_data, etc.)
     * @return SyncFailureLog|null The created log entry
     */
    public function logFailure(
        ShopifyProductVariant $variant,
        string $operationType,
        Throwable|string $error,
        ?array $apiResponse = null,
        array $context = []
    ): ?SyncFailureLog {
        try {
            // Extract error details
            $errorMessage = $error instanceof Throwable ? $error->getMessage() : $error;
            $errorFile = $error instanceof Throwable ? $error->getFile() : ($context['error_file'] ?? null);
            $errorLine = $error instanceof Throwable ? $error->getLine() : ($context['error_line'] ?? null);

            // Determine flag value
            $flagValue = $this->determineFlagValue($variant, $operationType);

            // Prepare log data
            $logData = [
                'marketplace' => 'Shopify',
                'job_name' => $context['job_name'] ?? 'unknown',
                'item_identifier' => $variant->sku ?? (string) $variant->variant_id,
                'operation_type' => $operationType,
                'flag_value' => $flagValue,
                'error_message' => $errorMessage,
                'api_request' => $context['api_request'] ?? null,
                'api_response' => $apiResponse,
                'user_errors' => $context['user_errors'] ?? ($apiResponse['userErrors'] ?? null),
                'graphql_errors' => $context['graphql_errors'] ?? ($apiResponse['errors'] ?? null),
                'current_data' => $context['current_data'] ?? $this->getCurrentData($variant, $operationType),
                'target_data' => $context['target_data'] ?? null,
                'error_file' => $errorFile,
                'error_line' => $errorLine,
                'variant_id' => $variant->id,
                'retry_job_id' => $context['retry_job_id'] ?? null,
            ];

            // Create the log entry
            $log = SyncFailureLog::create($logData);

            // Also log to Laravel logs for immediate visibility
            Log::error('Sync failure logged', [
                'log_id' => $log->id,
                'sku' => $variant->sku,
                'operation' => $operationType,
                'error' => $errorMessage,
            ]);

            return $log;
        } catch (Exception $e) {
            // If logging fails, at least log to Laravel logs
            Log::error('Failed to create SyncFailureLog entry', [
                'error' => $e->getMessage(),
                'original_error' => $errorMessage ?? 'unknown',
                'sku' => $variant->sku ?? 'unknown',
            ]);

            return null;
        }
    }

    /**
     * Log a successful retry for comparison
     *
     * @param  ShopifyProductVariant  $variant
     * @param  string  $operationType
     * @param  array  $context
     * @return void
     */
    public function logSuccess(
        ShopifyProductVariant $variant,
        string $operationType,
        array $context = []
    ): void {
        Log::info('Sync retry successful', [
            'sku' => $variant->sku,
            'operation' => $operationType,
            'job_name' => $context['job_name'] ?? 'unknown',
            'retry_job_id' => $context['retry_job_id'] ?? null,
        ]);
    }

    /**
     * Get current data from variant for comparison
     *
     * @param  ShopifyProductVariant  $variant
     * @param  string  $operationType
     * @return array
     */
    private function getCurrentData(ShopifyProductVariant $variant, string $operationType): array
    {
        $data = [];

        if (in_array($operationType, ['price', 'price_inventory'])) {
            $data['price'] = $variant->price;
            $data['compare_at_price'] = $variant->compare_at_price;
        }

        if (in_array($operationType, ['inventory', 'price_inventory'])) {
            $data['inventory_quantity'] = $variant->inventory_quantity;
        }

        return $data;
    }

    /**
     * Determine the current flag value for the operation
     *
     * @param  ShopifyProductVariant  $variant
     * @param  string  $operationType
     * @return string
     */
    private function determineFlagValue(ShopifyProductVariant $variant, string $operationType): string
    {
        if (in_array($operationType, ['price', 'price_inventory'])) {
            return (string) $variant->price_requires_update;
        }

        if ($operationType === 'inventory') {
            return (string) $variant->inventory_requires_update;
        }

        return '1'; // Default
    }

    /**
     * Get failure history for a variant
     *
     * @param  int  $variantId
     * @param  int  $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getFailureHistory(int $variantId, int $limit = 10)
    {
        return SyncFailureLog::where('variant_id', $variantId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get recent failures across all variants
     *
     * @param  int  $limit
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRecentFailures(int $limit = 50, array $filters = [])
    {
        $query = SyncFailureLog::with('variant')->recent($limit);

        if (isset($filters['flag'])) {
            $query->where('flag_value', $filters['flag']);
        }

        if (isset($filters['operation_type'])) {
            $query->where('operation_type', $filters['operation_type']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->get();
    }

    /**
     * Clean up old failure logs
     *
     * @return int Number of deleted records
     */
    public function cleanupOldLogs(): int
    {
        if (! config('sync.cleanup_enabled')) {
            return 0;
        }

        $days = config('sync.log_retention_days', 7);

        return SyncFailureLog::olderThan($days)->delete();
    }

    /**
     * Get statistics for dashboard
     *
     * @return array
     */
    public function getStatistics(): array
    {
        return [
            'total_failures' => SyncFailureLog::count(),
            'failures_flag_2' => SyncFailureLog::byFlag('2')->count(),
            'failures_flag_3' => SyncFailureLog::byFlag('3')->count(),
            'failures_today' => SyncFailureLog::whereDate('created_at', today())->count(),
            'failures_this_week' => SyncFailureLog::where('created_at', '>=', now()->subWeek())->count(),
        ];
    }

    /**
     * Get failure breakdown by operation type
     *
     * @return array
     */
    public function getFailureBreakdown(): array
    {
        return [
            'price' => SyncFailureLog::where('operation_type', 'price')->count(),
            'inventory' => SyncFailureLog::where('operation_type', 'inventory')->count(),
            'price_inventory' => SyncFailureLog::where('operation_type', 'price_inventory')->count(),
        ];
    }
}
