<?php

namespace App\Livewire;

use App\Models\SyncFailureLog;
use Livewire\Component;

class FailureDetailsModal extends Component
{
    public $show = false;

    public $failureId = null;

    public $failure = null;

    public $activeTab = 'overview';

    public $retryHistory = [];

    protected $listeners = ['showFailureDetails'];

    public function render()
    {
        return view('livewire.failure-details-modal');
    }

    /**
     * Show failure details
     */
    public function showFailureDetails($failureId)
    {
        $this->failureId = $failureId;
        $this->show = true;
        $this->activeTab = 'overview';
        $this->loadFailure();
        $this->loadRetryHistory();
    }

    /**
     * Load failure data
     */
    public function loadFailure()
    {
        if ($this->failureId) {
            $this->failure = SyncFailureLog::with('variant.product')->find($this->failureId);
        }
    }

    /**
     * Load retry history for this variant
     */
    public function loadRetryHistory()
    {
        if ($this->failure && $this->failure->variant_id) {
            $this->retryHistory = SyncFailureLog::where('variant_id', $this->failure->variant_id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->toArray();
        }
    }

    /**
     * Change active tab
     */
    public function setActiveTab($tab)
    {
        $this->activeTab = $tab;
    }

    /**
     * Close modal
     */
    public function closeModal()
    {
        $this->show = false;
        $this->failureId = null;
        $this->failure = null;
        $this->activeTab = 'overview';
        $this->retryHistory = [];
    }

    /**
     * Get formatted JSON for display
     */
    public function getFormattedJson($data)
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
