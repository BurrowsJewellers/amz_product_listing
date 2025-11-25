<?php

namespace App\Livewire;

use App\Models\SyncFailureLog;
use Illuminate\Support\Facades\Log;
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
        try {
            // Validate failure ID
            if (! is_numeric($failureId) || $failureId <= 0) {
                Log::warning('Invalid failure ID for details modal', ['id' => $failureId]);

                return;
            }

            $this->failureId = $failureId;
            $this->show = true;
            $this->activeTab = 'overview';
            $this->loadFailure();
            $this->loadRetryHistory();

            // If failure not found, close modal
            if (! $this->failure) {
                Log::warning('Failure not found for details modal', ['id' => $failureId]);
                $this->closeModal();
            }
        } catch (\Exception $e) {
            Log::error('Failed to show failure details', [
                'error' => $e->getMessage(),
                'failure_id' => $failureId,
            ]);
            $this->closeModal();
        }
    }

    /**
     * Load failure data
     */
    public function loadFailure()
    {
        try {
            if ($this->failureId) {
                $this->failure = SyncFailureLog::with(['variant.product'])
                    ->find($this->failureId);
            }
        } catch (\Exception $e) {
            Log::error('Failed to load failure data', [
                'error' => $e->getMessage(),
                'failure_id' => $this->failureId,
            ]);
            $this->failure = null;
        }
    }

    /**
     * Load retry history for this variant
     */
    public function loadRetryHistory()
    {
        try {
            if ($this->failure && $this->failure->variant_id) {
                $this->retryHistory = SyncFailureLog::where('variant_id', $this->failure->variant_id)
                    ->orderBy('created_at', 'desc')
                    ->limit(10) // Limit to prevent memory issues
                    ->get()
                    ->toArray();
            }
        } catch (\Exception $e) {
            Log::error('Failed to load retry history', [
                'error' => $e->getMessage(),
                'variant_id' => $this->failure?->variant_id,
            ]);
            $this->retryHistory = [];
        }
    }

    /**
     * Change active tab
     */
    public function setActiveTab($tab)
    {
        try {
            // Validate allowed tabs to prevent tampering
            $allowedTabs = ['overview', 'api_request', 'api_response', 'data_comparison', 'error_location', 'retry_history'];
            if (! in_array($tab, $allowedTabs)) {
                Log::warning('Invalid tab attempted', ['tab' => $tab]);

                return;
            }

            $this->activeTab = $tab;
        } catch (\Exception $e) {
            Log::error('Failed to change tab', [
                'error' => $e->getMessage(),
                'tab' => $tab,
            ]);
        }
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
        try {
            if (empty($data)) {
                return '{}';
            }

            if (is_string($data)) {
                $decoded = json_decode($data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::warning('Invalid JSON in getFormattedJson', [
                        'error' => json_last_error_msg(),
                    ]);

                    return $data; // Return original string if invalid JSON
                }
                $data = $decoded;
            }

            $formatted = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($formatted === false) {
                Log::error('Failed to encode JSON', [
                    'error' => json_last_error_msg(),
                ]);

                return '{}';
            }

            return $formatted;
        } catch (\Exception $e) {
            Log::error('Exception in getFormattedJson', [
                'error' => $e->getMessage(),
            ]);

            return '{}';
        }
    }
}
