<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sync Log Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to keep sync failure logs before automatic cleanup.
    | Set to 0 to disable automatic cleanup.
    |
    */
    'log_retention_days' => env('SYNC_LOG_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Cleanup Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable automatic cleanup of old sync logs.
    |
    */
    'cleanup_enabled' => env('SYNC_CLEANUP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Retry Job Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to keep completed retry job records.
    |
    */
    'retry_job_retention_days' => env('SYNC_RETRY_JOB_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Refresh Interval
    |--------------------------------------------------------------------------
    |
    | How often (in milliseconds) the dashboard should poll for updates
    | when a retry job is running.
    |
    */
    'dashboard_refresh_interval' => env('SYNC_DASHBOARD_REFRESH_MS', 2000),

    /*
    |--------------------------------------------------------------------------
    | Retry Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limiting for manual retry operations to prevent API overload.
    | delay_between_items: milliseconds between each variant update
    | max_concurrent: maximum concurrent retry jobs allowed
    |
    */
    'retry_rate_limiting' => [
        'delay_between_items' => env('SYNC_RETRY_DELAY_MS', 500),
        'max_concurrent_jobs' => env('SYNC_MAX_CONCURRENT_RETRIES', 1),
    ],
];
