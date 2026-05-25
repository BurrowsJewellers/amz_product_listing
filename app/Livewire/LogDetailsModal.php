<?php

namespace App\Livewire;

use App\Models\SyncOperationLog;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class LogDetailsModal extends Component
{
    public $show = false;

    public $logId = null;

    public $log = null;

    public $activeTab = 'overview';

    public $history = [];

    protected $listeners = ['showLogDetails'];

    public function render()
    {
        return view('livewire.log-details-modal');
    }

    /**
     * Show log details
     */
    public function showLogDetails($logId)
    {
        try {
            // Validate log ID
            if (! is_numeric($logId) || $logId <= 0) {
                Log::warning('Invalid log ID for details modal', ['id' => $logId]);

                return;
            }

            $this->logId = $logId;
            $this->show = true;
            $this->activeTab = 'overview';
            $this->loadLog();
            $this->loadHistory();

            // If log not found, close modal
            if (! $this->log) {
                Log::warning('Log not found for details modal', ['id' => $logId]);
                $this->closeModal();
            }
        } catch (\Exception $e) {
            Log::error('Failed to show log details', [
                'error' => $e->getMessage(),
                'log_id' => $logId,
            ]);
            $this->closeModal();
        }
    }

    /**
     * Load log data
     */
    public function loadLog()
    {
        try {
            if ($this->logId) {
                $this->log = SyncOperationLog::with(['shopifyVariant.product', 'shopifyProduct'])
                    ->find($this->logId);
            }
        } catch (\Exception $e) {
            Log::error('Failed to load log data', [
                'error' => $e->getMessage(),
                'log_id' => $this->logId,
            ]);
            $this->log = null;
        }
    }

    /**
     * Load history for this item
     */
    public function loadHistory()
    {
        try {
            if ($this->log && $this->log->item_identifier) {
                $this->history = SyncOperationLog::where('item_identifier', $this->log->item_identifier)
                    ->where('marketplace', $this->log->marketplace)
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get()
                    ->toArray();
            }
        } catch (\Exception $e) {
            Log::error('Failed to load history', [
                'error' => $e->getMessage(),
                'item_identifier' => $this->log?->item_identifier,
            ]);
            $this->history = [];
        }
    }

    /**
     * Change active tab
     */
    public function setActiveTab($tab)
    {
        try {
            // Validate allowed tabs to prevent tampering
            $allowedTabs = ['overview', 'api_request', 'api_response', 'context_data', 'errors', 'history'];
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
        $this->logId = null;
        $this->log = null;
        $this->activeTab = 'overview';
        $this->history = [];
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

    /**
     * Get status badge class
     */
    public function getStatusBadgeClass(): string
    {
        if (! $this->log) {
            return 'bg-gray-100 text-gray-800';
        }

        return match ($this->log->status) {
            'success' => 'bg-green-100 text-green-800',
            'failed' => 'bg-red-100 text-red-800',
            'pending' => 'bg-yellow-100 text-yellow-800',
            'skipped' => 'bg-gray-100 text-gray-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }
}
