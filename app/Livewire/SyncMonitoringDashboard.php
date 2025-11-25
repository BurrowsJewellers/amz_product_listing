<?php

namespace App\Livewire;

use App\Jobs\RetryFailedSyncsJob;
use App\Models\ShopifyProductVariant;
use App\Models\SyncFailureLog;
use App\Models\SyncRetryJob;
use App\Services\SyncFailureLogger;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SyncMonitoringDashboard extends Component
{
    public $stats = [];

    public $activeRetryJobs = [];

    public $showRetryModal = false;

    protected $listeners = ['retryJobStarted', 'refreshDashboard'];

    public function mount()
    {
        $this->loadStats();
        $this->loadActiveRetryJobs();
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
        $failureLogger = app(SyncFailureLogger::class);

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
    }

    /**
     * Load active retry jobs
     */
    public function loadActiveRetryJobs()
    {
        $this->activeRetryJobs = SyncRetryJob::where('status', 'processing')
            ->orderBy('started_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Start retry for all failed syncs
     */
    public function retryAllFailed()
    {
        // Check if there's already a retry job running
        $runningJob = SyncRetryJob::where('status', 'processing')->first();
        if ($runningJob) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'A retry job is already running. Please wait for it to complete.',
            ]);

            return;
        }

        // Create new retry job record
        $retryJob = SyncRetryJob::create([
            'job_type' => 'manual_retry_all',
            'triggered_by' => Auth::user()->name ?? 'System',
            'status' => 'pending',
        ]);

        // Dispatch the job
        RetryFailedSyncsJob::dispatch($retryJob->id, [2, 3], $retryJob->triggered_by);

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Retry job started successfully. Check progress below.',
        ]);

        $this->showRetryModal = true;
        $this->loadActiveRetryJobs();
        $this->dispatch('retryJobStarted', jobId: $retryJob->id);
    }

    /**
     * Refresh dashboard data (called by polling)
     */
    public function refreshDashboard()
    {
        $this->loadStats();
        $this->loadActiveRetryJobs();
    }
}
