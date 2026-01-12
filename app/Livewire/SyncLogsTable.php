<?php

namespace App\Livewire;

use App\Models\SyncOperationLog;
use App\Services\SyncLogger;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithPagination;

class SyncLogsTable extends Component
{
    use WithPagination;

    public $selectedLogId = null;

    public $search = '';

    public $filters = [
        'marketplace' => '',
        'status' => '',
        'operation_type' => '',
        'date_from' => '',
        'date_to' => '',
    ];

    public $sortField = 'created_at';

    public $sortDirection = 'desc';

    protected $listeners = ['refreshLogs'];

    /**
     * Get available operation types for filter dropdown
     */
    public function getOperationTypesProperty(): array
    {
        return SyncLogger::getOperationTypes();
    }

    /**
     * Get available statuses for filter dropdown
     */
    public function getStatusesProperty(): array
    {
        return SyncLogger::getStatuses();
    }

    /**
     * Get available marketplaces for filter dropdown
     */
    public function getMarketplacesProperty(): array
    {
        return SyncLogger::getMarketplaces();
    }

    /**
     * Validation rules for filters
     */
    protected function rules(): array
    {
        $operationTypes = implode(',', SyncLogger::getOperationTypes());
        $statuses = implode(',', SyncLogger::getStatuses());
        $marketplaces = implode(',', SyncLogger::getMarketplaces());

        return [
            'filters.marketplace' => "nullable|in:,{$marketplaces}",
            'filters.status' => "nullable|in:,{$statuses}",
            'filters.operation_type' => "nullable|in:,{$operationTypes}",
            'filters.date_from' => 'nullable|date',
            'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
        ];
    }

    public function render()
    {
        try {
            $logs = $this->getLogs();

            return view('livewire.sync-logs-table', [
                'logs' => $logs,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to render sync logs table', [
                'error' => $e->getMessage(),
                'filters' => $this->filters,
            ]);

            // Return empty paginator instead of collection to avoid ->links() error
            return view('livewire.sync-logs-table', [
                'logs' => SyncOperationLog::query()->where('id', 0)->paginate(25),
            ]);
        }
    }

    /**
     * Get filtered and sorted logs
     */
    private function getLogs()
    {
        $query = SyncOperationLog::query();

        // Apply search filter
        if (! empty($this->search)) {
            $query->where(function ($q) {
                $q->where('item_identifier', 'like', '%'.$this->search.'%')
                    ->orWhere('item_title', 'like', '%'.$this->search.'%');
            });
        }

        // Apply filters
        if (! empty($this->filters['marketplace'])) {
            $query->where('marketplace', $this->filters['marketplace']);
        }

        if (! empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }

        if (! empty($this->filters['operation_type'])) {
            $query->where('operation_type', $this->filters['operation_type']);
        }

        if (! empty($this->filters['date_from'])) {
            $query->where('created_at', '>=', $this->filters['date_from']);
        }

        if (! empty($this->filters['date_to'])) {
            $query->where('created_at', '<=', $this->filters['date_to']);
        }

        // Apply sorting
        $query->orderBy($this->sortField, $this->sortDirection);

        return $query->paginate(25);
    }

    /**
     * Sort by field
     */
    public function sortBy($field)
    {
        try {
            // Validate allowed sort fields to prevent SQL injection
            $allowedFields = ['id', 'created_at', 'marketplace', 'status', 'operation_type', 'item_identifier'];
            if (! in_array($field, $allowedFields)) {
                Log::warning('Invalid sort field attempted', ['field' => $field]);

                return;
            }

            if ($this->sortField === $field) {
                $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                $this->sortField = $field;
                $this->sortDirection = 'asc';
            }
        } catch (\Exception $e) {
            Log::error('Failed to sort logs table', [
                'error' => $e->getMessage(),
                'field' => $field,
            ]);
        }
    }

    /**
     * Reset filters
     */
    public function resetFilters()
    {
        $this->search = '';
        $this->filters = [
            'marketplace' => '',
            'status' => '',
            'operation_type' => '',
            'date_from' => '',
            'date_to' => '',
        ];
        $this->resetPage();
    }

    /**
     * Show log details
     */
    public function showDetails($logId)
    {
        try {
            // Validate log ID
            if (! is_numeric($logId) || $logId <= 0) {
                Log::warning('Invalid log ID provided', ['id' => $logId]);

                return;
            }

            // Verify the log exists
            $log = SyncOperationLog::find($logId);
            if (! $log) {
                Log::warning('Log not found', ['id' => $logId]);

                return;
            }

            $this->selectedLogId = $logId;
            $this->dispatch('showLogDetails', logId: $logId);
        } catch (\Exception $e) {
            Log::error('Failed to show log details', [
                'error' => $e->getMessage(),
                'log_id' => $logId,
            ]);
        }
    }

    /**
     * Refresh logs (called from parent)
     */
    public function refreshLogs()
    {
        try {
            $this->resetPage();
        } catch (\Exception $e) {
            Log::error('Failed to refresh logs table', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update when filters change
     */
    public function updated($propertyName)
    {
        try {
            if ($propertyName === 'search') {
                $this->resetPage();

                return;
            }

            if (str_starts_with($propertyName, 'filters.')) {
                // Validate filters before applying
                $this->validate();
                $this->resetPage();
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Reset invalid filter
            $filterKey = str_replace('filters.', '', $propertyName);
            $this->filters[$filterKey] = '';
            Log::warning('Invalid filter value', [
                'property' => $propertyName,
                'errors' => $e->errors(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update filters', [
                'error' => $e->getMessage(),
                'property' => $propertyName,
            ]);
        }
    }
}
