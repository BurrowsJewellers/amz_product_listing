<div>
    <div class="card">
        <div class="card-header">
            <strong>Recent Sync Failures</strong>
        </div>
        <div class="card-body">
            <!-- Filters -->
            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label">Flag Value</label>
                    <select wire:model.live="filters.flag" class="form-select">
                        <option value="">All Flags</option>
                        <option value="1">Flag 1 (First Attempt)</option>
                        <option value="2">Flag 2 (Retry Needed)</option>
                        <option value="3">Flag 3 (Repeated Failure)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Operation Type</label>
                    <select wire:model.live="filters.operation_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="price">Price</option>
                        <option value="inventory">Inventory</option>
                        <option value="price_inventory">Price & Inventory</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From Date</label>
                    <input type="date" wire:model.live="filters.date_from" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To Date</label>
                    <input type="date" wire:model.live="filters.date_to" class="form-control">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button wire:click="resetFilters" class="btn btn-secondary w-100">
                        Reset Filters
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th wire:click="sortBy('id')" style="cursor: pointer;">
                                ID
                                @if($sortField === 'id')
                                    <i class="cil-arrow-{{ $sortDirection === 'asc' ? 'top' : 'bottom' }}"></i>
                                @endif
                            </th>
                            <th wire:click="sortBy('created_at')" style="cursor: pointer;">
                                Date
                                @if($sortField === 'created_at')
                                    <i class="cil-arrow-{{ $sortDirection === 'asc' ? 'top' : 'bottom' }}"></i>
                                @endif
                            </th>
                            <th>SKU</th>
                            <th>Operation</th>
                            <th>Flag</th>
                            <th>Error</th>
                            <th>Job Name</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($failures as $failure)
                        <tr>
                            <td>{{ $failure->id }}</td>
                            <td>
                                <small>{{ $failure->created_at->format('Y-m-d H:i:s') }}</small>
                                <br>
                                <small class="text-muted">{{ $failure->created_at->diffForHumans() }}</small>
                            </td>
                            <td>
                                <code>{{ $failure->item_identifier }}</code>
                            </td>
                            <td>
                                @if($failure->operation_type === 'price')
                                    <span class="badge bg-primary">Price</span>
                                @elseif($failure->operation_type === 'inventory')
                                    <span class="badge bg-info">Inventory</span>
                                @else
                                    <span class="badge bg-secondary">Price & Inventory</span>
                                @endif
                            </td>
                            <td>
                                @if($failure->flag_value == 1)
                                    <span class="badge bg-warning">Flag 1</span>
                                @elseif($failure->flag_value == 2)
                                    <span class="badge bg-danger">Flag 2</span>
                                @elseif($failure->flag_value == 3)
                                    <span class="badge bg-dark">Flag 3</span>
                                @else
                                    <span class="badge bg-secondary">{{ $failure->flag_value }}</span>
                                @endif
                            </td>
                            <td>
                                <small class="text-truncate d-inline-block" style="max-width: 200px;">
                                    {{ $failure->error_message }}
                                </small>
                            </td>
                            <td>
                                <small>{{ $failure->job_name }}</small>
                            </td>
                            <td>
                                <button
                                    wire:click="showDetails({{ $failure->id }})"
                                    class="btn btn-sm btn-primary">
                                    View Details
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted">
                                No failure logs found.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="mt-3">
                {{ $failures->links() }}
            </div>
        </div>
    </div>
</div>
