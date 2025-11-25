<?php

namespace App\Livewire;

use App\Models\SyncRetryJob;
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
        if ($this->retryJobId) {
            $this->retryJob = SyncRetryJob::find($this->retryJobId);

            // Auto-close modal when job completes
            if ($this->retryJob && $this->retryJob->isCompleted()) {
                $this->dispatch('retryJobCompleted');
                $this->dispatch('refreshDashboard');
            }
        }
    }

    /**
     * Handle retry job started event
     */
    public function retryJobStarted($jobId)
    {
        $this->retryJobId = $jobId;
        $this->show = true;
        $this->loadRetryJob();
    }

    /**
     * Refresh progress (called by polling)
     */
    public function refreshProgress()
    {
        $this->loadRetryJob();
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
