<?php

namespace App\Livewire;

use App\Jobs\RetryFailedSyncsJob;
use App\Models\ShopifyProductVariant;
use App\Models\SyncFailureLog;
use App\Models\SyncRetryJob;
use App\Services\SyncFailureLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class SyncMonitoringDashboard extends Component
{
    public $stats = [];

    public $activeRetryJobs = [];

    public $showRetryModal = false;

    protected $listeners = ['retryJobStarted', 'refreshDashboard'];

    public function mount()
    {
        try {
            $this->loadStats();
            $this->loadActiveRetryJobs();
        } catch (\Exception $e) {
            Log::error('SyncMonitoringDashboard mount failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Failed to load dashboard. Please refresh the page.',
            ]);
        }
    }

    public function render()
    {
        return view('livewire.sync-monitoring-dashboard');
    }

    /**
     * Load dashboard statistics
     */
    public function loadStats()
    {
        try {
            // Get variant counts with flag issues
            $variantsWithFlag2 = ShopifyProductVariant::where(function ($query) {
                $query->where('price_requires_update', 2)
                    ->orWhere('inventory_requires_update', 2);
            })->count();

            $variantsWithFlag3 = ShopifyProductVariant::where(function ($query) {
                $query->where('price_requires_update', 3)
                    ->orWhere('inventory_requires_update', 3);
            })->count();

            $this->stats = [
                'total_failures' => SyncFailureLog::count(),
                'failures_flag_2' => $variantsWithFlag2,
                'failures_flag_3' => $variantsWithFlag3,
                'failures_today' => SyncFailureLog::whereDate('created_at', today())->count(),
                'failures_this_week' => SyncFailureLog::where('created_at', '>=', now()->subWeek())->count(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to load dashboard stats', [
                'error' => $e->getMessage(),
            ]);
            $this->stats = [
                'total_failures' => 0,
                'failures_flag_2' => 0,
                'failures_flag_3' => 0,
                'failures_today' => 0,
                'failures_this_week' => 0,
            ];
        }
    }

    /**
     * Load active retry jobs
     */
    public function loadActiveRetryJobs()
    {
        try {
            $this->activeRetryJobs = SyncRetryJob::where('status', 'processing')
                ->orderBy('started_at', 'desc')
                ->limit(10) // Limit to prevent memory issues
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to load active retry jobs', [
                'error' => $e->getMessage(),
            ]);
            $this->activeRetryJobs = [];
        }
    }

    /**
     * Start retry for all failed syncs
     */
    public function retryAllFailed()
    {
        try {
            // Validate user is authenticated
            if (! Auth::check()) {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'You must be logged in to retry failed syncs.',
                ]);

                return;
            }

            // Check if queue is configured
            if (config('queue.default') === 'sync') {
                Log::warning('Queue is set to sync driver - retry jobs will run synchronously');
            }

            // Check if there's already a retry job running
            $runningJob = SyncRetryJob::where('status', 'processing')->first();
            if ($runningJob) {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'A retry job is already running. Please wait for it to complete.',
                ]);

                return;
            }

            // Count items to retry
            $itemsToRetry = ShopifyProductVariant::where(function ($query) {
                $query->where('price_requires_update', 2)
                    ->orWhere('inventory_requires_update', 2)
                    ->orWhere('price_requires_update', 3)
                    ->orWhere('inventory_requires_update', 3);
            })->count();

            if ($itemsToRetry === 0) {
                $this->dispatch('notify', [
                    'type' => 'info',
                    'message' => 'No failed items to retry.',
                ]);

                return;
            }

            // Create new retry job record
            $retryJob = SyncRetryJob::create([
                'job_type' => 'manual_retry_all',
                'triggered_by' => Auth::user()->name ?? Auth::user()->email ?? 'System',
                'status' => 'pending',
            ]);

            // Dispatch the job
            RetryFailedSyncsJob::dispatch($retryJob->id, [2, 3], $retryJob->triggered_by);

            Log::info('Manual retry job started', [
                'retry_job_id' => $retryJob->id,
                'triggered_by' => $retryJob->triggered_by,
                'items_count' => $itemsToRetry,
            ]);

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => "Retry job started successfully. Processing {$itemsToRetry} item(s).",
            ]);

            $this->showRetryModal = true;
            $this->loadActiveRetryJobs();
            $this->dispatch('retryJobStarted', jobId: $retryJob->id);
        } catch (\Exception $e) {
            Log::error('Failed to start retry job', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user' => Auth::id(),
            ]);

            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Failed to start retry job. Please try again or contact support.',
            ]);
        }
    }

    /**
     * Refresh dashboard data (called by polling)
     */
    public function refreshDashboard()
    {
        try {
            $this->loadStats();
            $this->loadActiveRetryJobs();
        } catch (\Exception $e) {
            Log::error('Failed to refresh dashboard', [
                'error' => $e->getMessage(),
            ]);
            // Silently fail on refresh - don't notify user
        }
    }
}
