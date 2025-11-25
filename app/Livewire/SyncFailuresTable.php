<?php

namespace App\Livewire;

use App\Models\SyncFailureLog;
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

    public function render()
    {
        $failures = $this->getFailures();

        return view('livewire.sync-failures-table', [
            'failures' => $failures,
        ]);
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
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
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
        $this->selectedFailureId = $failureId;
        $this->dispatch('showFailureDetails', failureId: $failureId);
    }

    /**
     * Refresh failures (called from parent)
     */
    public function refreshFailures()
    {
        $this->resetPage();
    }

    /**
     * Update when filters change
     */
    public function updated($propertyName)
    {
        if (str_starts_with($propertyName, 'filters.')) {
            $this->resetPage();
        }
    }
}
