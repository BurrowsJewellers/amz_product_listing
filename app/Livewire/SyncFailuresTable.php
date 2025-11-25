<?php

namespace App\Livewire;

use App\Models\SyncFailureLog;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithPagination;

class SyncFailuresTable extends Component
{
    use WithPagination;

    public $selectedFailureId = null;

    public $filters = [
        'flag' => '',
        'operation_type' => '',
        'date_from' => '',
        'date_to' => '',
    ];

    public $sortField = 'created_at';

    public $sortDirection = 'desc';

    protected $listeners = ['refreshFailures'];

    /**
     * Validation rules for filters
     */
    protected $rules = [
        'filters.flag' => 'nullable|in:,1,2,3',
        'filters.operation_type' => 'nullable|in:,price,inventory,price_inventory',
        'filters.date_from' => 'nullable|date',
        'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
    ];

    public function render()
    {
        try {
            $failures = $this->getFailures();

            return view('livewire.sync-failures-table', [
                'failures' => $failures,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to render sync failures table', [
                'error' => $e->getMessage(),
                'filters' => $this->filters,
            ]);

            return view('livewire.sync-failures-table', [
                'failures' => collect(),
            ]);
        }
    }

    /**
     * Get filtered and sorted failures
     */
    private function getFailures()
    {
        $query = SyncFailureLog::with('variant');

        // Apply filters
        if (! empty($this->filters['flag'])) {
            $query->where('flag_value', $this->filters['flag']);
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
            $allowedFields = ['id', 'created_at', 'flag_value', 'operation_type'];
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
            Log::error('Failed to sort failures table', [
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
        $this->filters = [
            'flag' => '',
            'operation_type' => '',
            'date_from' => '',
            'date_to' => '',
        ];
        $this->resetPage();
    }

    /**
     * Show failure details
     */
    public function showDetails($failureId)
    {
        try {
            // Validate failure ID
            if (! is_numeric($failureId) || $failureId <= 0) {
                Log::warning('Invalid failure ID provided', ['id' => $failureId]);

                return;
            }

            // Verify the failure exists
            $failure = SyncFailureLog::find($failureId);
            if (! $failure) {
                Log::warning('Failure not found', ['id' => $failureId]);

                return;
            }

            $this->selectedFailureId = $failureId;
            $this->dispatch('showFailureDetails', failureId: $failureId);
        } catch (\Exception $e) {
            Log::error('Failed to show failure details', [
                'error' => $e->getMessage(),
                'failure_id' => $failureId,
            ]);
        }
    }

    /**
     * Refresh failures (called from parent)
     */
    public function refreshFailures()
    {
        try {
            $this->resetPage();
        } catch (\Exception $e) {
            Log::error('Failed to refresh failures table', [
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
