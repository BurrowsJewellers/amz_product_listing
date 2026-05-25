# Sync Monitoring Dashboard - Production Checklist

## Pre-Deployment Checklist

### 1. Database
- [ ] Run migrations: `php artisan migrate`
- [ ] Verify tables created: `sync_failure_logs`, `sync_retry_jobs`
- [ ] Check database indexes are in place
- [ ] Backup database before deployment

### 2. Environment Configuration
Add the following to your `.env` file:

```env
# Sync Monitoring Configuration
SYNC_LOG_RETENTION_DAYS=7
SYNC_CLEANUP_ENABLED=true
SYNC_RETRY_JOB_RETENTION_DAYS=30
SYNC_DASHBOARD_REFRESH_MS=2000
SYNC_RETRY_DELAY_MS=500
SYNC_MAX_CONCURRENT_RETRIES=1

# Queue Configuration (CRITICAL)
QUEUE_CONNECTION=database  # Must NOT be 'sync' in production
```

### 3. Queue Worker
- [ ] Ensure queue worker is running: `php artisan queue:work --queue=default --tries=3`
- [ ] Set up supervisor/systemd to keep queue worker running
- [ ] Monitor queue worker logs for failures
- [ ] Configure max job execution time (default: 1 hour for RetryFailedSyncsJob)

Example supervisor config (`/etc/supervisor/conf.d/laravel-worker.conf`):
```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/storage/logs/worker.log
stopwaitsecs=3600
```

### 4. Scheduler
- [ ] Add to crontab: `* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1`
- [ ] Verify cleanup command is scheduled: `php artisan schedule:list`
- [ ] Test cleanup command: `php artisan sync:cleanup-logs --dry-run`

### 5. Livewire Assets
- [ ] Publish Livewire assets if not already done: `php artisan vendor:publish --tag=livewire:assets`
- [ ] Ensure Livewire scripts are loaded (already in layout)

### 6. Permissions
- [ ] Ensure `storage/logs` is writable by web server
- [ ] Check database file permissions (if using SQLite)

### 7. Authorization (Optional but Recommended)
Consider adding role-based access control:

**Option A: Middleware**
Create `app/Http/Middleware/AdminMiddleware.php` and apply to route:
```php
Route::get('/sync-monitoring', [SyncMonitoringController::class, 'index'])
    ->middleware(['auth', 'admin', 'throttle:60,1'])
    ->name('sync_monitoring.index');
```

**Option B: Policy**
In `SyncMonitoringController`:
```php
public function index()
{
    $this->authorize('viewSyncMonitoring');
    return view('admin.sync-monitoring.index');
}
```

### 8. Performance Considerations
- [ ] Review dashboard refresh interval (2000ms default)
- [ ] Adjust pagination limit if needed (25 items default)
- [ ] Monitor database query performance
- [ ] Consider adding database query caching for stats

### 9. Error Monitoring
- [ ] Configure error tracking (Sentry, Bugsnag, etc.)
- [ ] Set up log rotation for Laravel logs
- [ ] Monitor `storage/logs/laravel.log` for sync errors
- [ ] Set up alerts for repeated job failures

### 10. Security
- [x] Rate limiting applied (60 req/min)
- [x] Authentication required
- [x] Input validation implemented
- [x] SQL injection protection (whitelisted fields)
- [x] XSS protection (Blade auto-escaping)
- [x] Comprehensive error handling
- [ ] Consider adding CSRF token validation for sensitive actions
- [ ] Review user permissions (add admin check if needed)

## Post-Deployment Verification

### 1. Dashboard Access
- [ ] Navigate to `/sync-monitoring`
- [ ] Verify stats cards display correctly
- [ ] Check that failure table loads

### 2. Retry Functionality
- [ ] Click "Retry All Failed Items" (if failures exist)
- [ ] Verify progress modal opens
- [ ] Check queue job processes in background
- [ ] Confirm job completion notification

### 3. Failure Details
- [ ] Click "View Details" on a failure
- [ ] Verify all tabs load:
  - Overview
  - API Request
  - API Response
  - Data Comparison
  - Error Location
  - Retry History
- [ ] Check JSON formatting is correct

### 4. Filters and Sorting
- [ ] Test flag filter (1, 2, 3)
- [ ] Test operation type filter
- [ ] Test date range filters
- [ ] Try sorting by ID and date
- [ ] Verify pagination works

### 5. Background Processes
- [ ] Verify queue worker is processing jobs
- [ ] Check scheduler is running cleanup
- [ ] Monitor job execution times
- [ ] Check for any stuck jobs

### 6. Logging
- [ ] Check `storage/logs/laravel.log` for any errors
- [ ] Verify sync failures are being logged
- [ ] Confirm cleanup is running daily

## Maintenance

### Daily
- Monitor queue worker status
- Check for any failed jobs
- Review sync failure trends

### Weekly
- Review error logs
- Check database growth
- Verify cleanup is working

### Monthly
- Review and adjust retention periods
- Analyze failure patterns
- Optimize query performance if needed
- Review and update rate limits if needed

## Troubleshooting

### Dashboard Not Loading
1. Check web server error logs
2. Verify Livewire is installed: `composer show livewire/livewire`
3. Clear cache: `php artisan cache:clear && php artisan view:clear`
4. Check database connection

### Retry Button Not Working
1. Verify queue worker is running: `ps aux | grep queue:work`
2. Check queue connection in `.env` (must be 'database', not 'sync')
3. Review failed jobs: `php artisan queue:failed`
4. Check Laravel logs for errors

### No Failures Showing
1. Verify migrations ran successfully
2. Check if UpdatePriceInventoryBatch is running
3. Confirm SyncFailureLogger is being used
4. Check if cleanup deleted all logs (adjust retention if needed)

### Progress Modal Not Updating
1. Check polling interval (default 2000ms)
2. Verify JavaScript console for errors
3. Ensure Livewire assets are published
4. Check browser DevTools Network tab

### High Memory Usage
1. Reduce pagination limit in SyncFailuresTable
2. Decrease retry history limit in FailureDetailsModal
3. Increase cleanup frequency
4. Add database query caching

## Rollback Plan

If issues occur after deployment:

1. **Stop Queue Worker**: `supervisorctl stop laravel-worker`
2. **Revert Migrations** (if needed):
   ```bash
   php artisan migrate:rollback --step=3
   ```
3. **Revert Code**:
   ```bash
   git checkout main
   ```
4. **Clear Cache**:
   ```bash
   php artisan cache:clear
   php artisan config:clear
   php artisan view:clear
   ```
5. **Restart Services**:
   ```bash
   supervisorctl start laravel-worker
   sudo systemctl reload php-fpm
   ```

## Support Contacts

- Developer: [Your Name]
- Database Admin: [DB Admin Contact]
- DevOps: [DevOps Contact]
- Emergency: [Emergency Contact]

## Additional Resources

- Livewire Docs: https://livewire.laravel.com
- Laravel Queue Docs: https://laravel.com/docs/11.x/queues
- Laravel Scheduler Docs: https://laravel.com/docs/11.x/scheduling
- Supervisor Docs: http://supervisord.org/

---

**Last Updated**: 2025-11-25
**Version**: 1.0.0
