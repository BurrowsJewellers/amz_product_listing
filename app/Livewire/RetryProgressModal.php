<?php

namespace App\Livewire;

use App\Models\SyncRetryJob;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class RetryProgressModal extends Component
{
    public $show = false;

    public $retryJobId = null;

    public $retryJob = null;

    protected $listeners = ['retryJobStarted', 'refreshProgress'];

    public function render()
    {
        return view('livewire.retry-progress-modal');
    }

    /**
     * Load retry job data
     */
    public function loadRetryJob()
    {
        try {
            if ($this->retryJobId) {
                $this->retryJob = SyncRetryJob::find($this->retryJobId);

                if (! $this->retryJob) {
                    Log::warning('Retry job not found', ['job_id' => $this->retryJobId]);
                    $this->closeModal();

                    return;
                }

                // Auto-close modal when job completes
                if ($this->retryJob->isCompleted()) {
                    $this->dispatch('retryJobCompleted');
                    $this->dispatch('refreshDashboard');
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to load retry job', [
                'error' => $e->getMessage(),
                'job_id' => $this->retryJobId,
            ]);
            $this->retryJob = null;
        }
    }

    /**
     * Handle retry job started event
     */
    public function retryJobStarted($jobId)
    {
        try {
            // Validate job ID
            if (! is_numeric($jobId) || $jobId <= 0) {
                Log::warning('Invalid retry job ID', ['id' => $jobId]);

                return;
            }

            $this->retryJobId = $jobId;
            $this->show = true;
            $this->loadRetryJob();
        } catch (\Exception $e) {
            Log::error('Failed to start retry job tracking', [
                'error' => $e->getMessage(),
                'job_id' => $jobId,
            ]);
            $this->closeModal();
        }
    }

    /**
     * Refresh progress (called by polling)
     */
    public function refreshProgress()
    {
        try {
            $this->loadRetryJob();
        } catch (\Exception $e) {
            Log::error('Failed to refresh retry progress', [
                'error' => $e->getMessage(),
                'job_id' => $this->retryJobId,
            ]);
            // Silently fail on refresh - don't close modal
        }
    }

    /**
     * Close modal
     */
    public function closeModal()
    {
        $this->show = false;
        $this->retryJobId = null;
        $this->retryJob = null;
    }

    /**
     * Get progress percentage
     */
    public function getProgressPercentageProperty()
    {
        return $this->retryJob ? $this->retryJob->getProgressPercentage() : 0;
    }

    /**
     * Get success rate
     */
    public function getSuccessRateProperty()
    {
        return $this->retryJob ? $this->retryJob->getSuccessRate() : 0;
    }
}
