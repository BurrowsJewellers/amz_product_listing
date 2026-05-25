<div>
    <div class="card">
        <div class="card-header">
            <strong>Sync Operation Logs</strong>
        </div>
        <div class="card-body">
            <!-- Search -->
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Search SKU / Title</label>
                    <input type="text"
                           wire:model.live.debounce.300ms="search"
                           class="form-control"
                           placeholder="Search by SKU or product title...">
                </div>
            </div>

            <!-- Filters -->
            <div class="row mb-3">
                <div class="col-md-2">
                    <label class="form-label">Marketplace</label>
                    <select wire:model.live="filters.marketplace" class="form-select">
                        <option value="">All Marketplaces</option>
                        @foreach($this->marketplaces as $marketplace)
                            <option value="{{ $marketplace }}">{{ $marketplace }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select wire:model.live="filters.status" class="form-select">
                        <option value="">All Statuses</option>
                        @foreach($this->statuses as $status)
                            <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Operation Type</label>
                    <select wire:model.live="filters.operation_type" class="form-select">
                        <option value="">All Types</option>
                        @foreach($this->operationTypes as $type)
                            <option value="{{ $type }}">{{ ucwords(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
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
                            <th wire:click="sortBy('marketplace')" style="cursor: pointer;">
                                Marketplace
                                @if($sortField === 'marketplace')
                                    <i class="cil-arrow-{{ $sortDirection === 'asc' ? 'top' : 'bottom' }}"></i>
                                @endif
                            </th>
                            <th>Item</th>
                            <th wire:click="sortBy('operation_type')" style="cursor: pointer;">
                                Operation
                                @if($sortField === 'operation_type')
                                    <i class="cil-arrow-{{ $sortDirection === 'asc' ? 'top' : 'bottom' }}"></i>
                                @endif
                            </th>
                            <th wire:click="sortBy('status')" style="cursor: pointer;">
                                Status
                                @if($sortField === 'status')
                                    <i class="cil-arrow-{{ $sortDirection === 'asc' ? 'top' : 'bottom' }}"></i>
                                @endif
                            </th>
                            <th>Message</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                        <tr>
                            <td>{{ $log->id }}</td>
                            <td>
                                <small>{{ $log->created_at->format('Y-m-d H:i:s') }}</small>
                                <br>
                                <small class="text-muted">{{ $log->created_at->diffForHumans() }}</small>
                            </td>
                            <td>
                                @if($log->marketplace === 'Shopify')
                                    <span class="badge bg-success">Shopify</span>
                                @else
                                    <span class="badge bg-warning text-dark">Amazon</span>
                                @endif
                            </td>
                            <td>
                                <code>{{ $log->item_identifier }}</code>
                                @if($log->item_title)
                                    <br><small class="text-muted">{{ Str::limit($log->item_title, 30) }}</small>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-secondary">{{ ucwords(str_replace('_', ' ', $log->operation_type)) }}</span>
                            </td>
                            <td>
                                @if($log->status === 'success')
                                    <span class="badge bg-success">Success</span>
                                @elseif($log->status === 'failed')
                                    <span class="badge bg-danger">Failed</span>
                                @elseif($log->status === 'pending')
                                    <span class="badge bg-warning text-dark">Pending</span>
                                @else
                                    <span class="badge bg-secondary">Skipped</span>
                                @endif
                            </td>
                            <td>
                                <small class="text-truncate d-inline-block" style="max-width: 200px;">
                                    {{ $log->message ?? '-' }}
                                </small>
                            </td>
                            <td>
                                <button
                                    wire:click="showDetails({{ $log->id }})"
                                    class="btn btn-sm btn-primary">
                                    View Details
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted">
                                No sync operation logs found.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="mt-3">
                {{ $logs->links('pagination::bootstrap-5') }}
            </div>
        </div>
    </div>
</div>
