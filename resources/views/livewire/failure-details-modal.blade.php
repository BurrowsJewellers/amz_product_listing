<div>
    @if($show && $failure)
    <div class="modal fade show" style="display: block;" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sync Failure Details - ID #{{ $failure->id }}</h5>
                    <button type="button" class="btn-close" wire:click="closeModal"></button>
                </div>
                <div class="modal-body">
                    <!-- Tabs -->
                    <ul class="nav nav-tabs mb-3">
                        <li class="nav-item">
                            <a class="nav-link {{ $activeTab === 'overview' ? 'active' : '' }}"
                               wire:click="setActiveTab('overview')"
                               style="cursor: pointer;">
                                Overview
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $activeTab === 'api_request' ? 'active' : '' }}"
                               wire:click="setActiveTab('api_request')"
                               style="cursor: pointer;">
                                API Request
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $activeTab === 'api_response' ? 'active' : '' }}"
                               wire:click="setActiveTab('api_response')"
                               style="cursor: pointer;">
                                API Response
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $activeTab === 'data_comparison' ? 'active' : '' }}"
                               wire:click="setActiveTab('data_comparison')"
                               style="cursor: pointer;">
                                Data Comparison
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $activeTab === 'error_location' ? 'active' : '' }}"
                               wire:click="setActiveTab('error_location')"
                               style="cursor: pointer;">
                                Error Location
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $activeTab === 'retry_history' ? 'active' : '' }}"
                               wire:click="setActiveTab('retry_history')"
                               style="cursor: pointer;">
                                Retry History ({{ count($retryHistory) }})
                            </a>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content">
                        <!-- Overview Tab -->
                        @if($activeTab === 'overview')
                        <div class="tab-pane fade show active">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Log ID:</strong> {{ $failure->id }}<br>
                                    <strong>Marketplace:</strong> {{ $failure->marketplace }}<br>
                                    <strong>SKU:</strong> <code>{{ $failure->item_identifier }}</code><br>
                                    <strong>Operation Type:</strong>
                                    @if($failure->operation_type === 'price')
                                        <span class="badge bg-primary">Price</span>
                                    @elseif($failure->operation_type === 'inventory')
                                        <span class="badge bg-info">Inventory</span>
                                    @else
                                        <span class="badge bg-secondary">Price & Inventory</span>
                                    @endif
                                    <br>
                                    <strong>Flag Value:</strong>
                                    @if($failure->flag_value == 1)
                                        <span class="badge bg-warning">Flag 1</span>
                                    @elseif($failure->flag_value == 2)
                                        <span class="badge bg-danger">Flag 2</span>
                                    @elseif($failure->flag_value == 3)
                                        <span class="badge bg-dark">Flag 3</span>
                                    @else
                                        <span class="badge bg-secondary">{{ $failure->flag_value }}</span>
                                    @endif
                                </div>
                                <div class="col-md-6">
                                    <strong>Job Name:</strong> {{ $failure->job_name }}<br>
                                    <strong>Created At:</strong> {{ $failure->created_at->format('Y-m-d H:i:s') }}<br>
                                    <small class="text-muted">{{ $failure->created_at->diffForHumans() }}</small>
                                    @if($failure->variant)
                                    <br><br>
                                    <strong>Variant ID:</strong> {{ $failure->variant_id }}<br>
                                    @if($failure->variant->product)
                                    <strong>Product:</strong> {{ $failure->variant->product->title }}<br>
                                    @endif
                                    @endif
                                </div>
                            </div>
                            <div class="alert alert-danger">
                                <strong>Error Message:</strong><br>
                                {{ $failure->error_message }}
                            </div>
                        </div>
                        @endif

                        <!-- API Request Tab -->
                        @if($activeTab === 'api_request')
                        <div class="tab-pane fade show active">
                            @if($failure->api_request)
                            <pre class="bg-light p-3 rounded" style="max-height: 500px; overflow-y: auto;"><code>{{ $this->getFormattedJson($failure->api_request) }}</code></pre>
                            @else
                            <div class="alert alert-info">No API request data available.</div>
                            @endif
                        </div>
                        @endif

                        <!-- API Response Tab -->
                        @if($activeTab === 'api_response')
                        <div class="tab-pane fade show active">
                            @if($failure->api_response)
                            <h6>Full API Response</h6>
                            <pre class="bg-light p-3 rounded" style="max-height: 300px; overflow-y: auto;"><code>{{ $this->getFormattedJson($failure->api_response) }}</code></pre>

                            @if($failure->user_errors)
                            <h6 class="mt-3">User Errors</h6>
                            <pre class="bg-danger text-white p-3 rounded" style="max-height: 200px; overflow-y: auto;"><code>{{ $this->getFormattedJson($failure->user_errors) }}</code></pre>
                            @endif

                            @if($failure->graphql_errors)
                            <h6 class="mt-3">GraphQL Errors</h6>
                            <pre class="bg-danger text-white p-3 rounded" style="max-height: 200px; overflow-y: auto;"><code>{{ $this->getFormattedJson($failure->graphql_errors) }}</code></pre>
                            @endif
                            @else
                            <div class="alert alert-info">No API response data available.</div>
                            @endif
                        </div>
                        @endif

                        <!-- Data Comparison Tab -->
                        @if($activeTab === 'data_comparison')
                        <div class="tab-pane fade show active">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Current Data (in DB)</h6>
                                    @if($failure->current_data)
                                    <pre class="bg-light p-3 rounded"><code>{{ $this->getFormattedJson($failure->current_data) }}</code></pre>
                                    @else
                                    <div class="alert alert-info">No current data available.</div>
                                    @endif
                                </div>
                                <div class="col-md-6">
                                    <h6>Target Data (attempted update)</h6>
                                    @if($failure->target_data)
                                    <pre class="bg-light p-3 rounded"><code>{{ $this->getFormattedJson($failure->target_data) }}</code></pre>
                                    @else
                                    <div class="alert alert-info">No target data available.</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- Error Location Tab -->
                        @if($activeTab === 'error_location')
                        <div class="tab-pane fade show active">
                            @if($failure->error_file && $failure->error_line)
                            <div class="alert alert-warning">
                                <strong>File:</strong> <code>{{ $failure->error_file }}</code><br>
                                <strong>Line:</strong> <code>{{ $failure->error_line }}</code>
                            </div>
                            @else
                            <div class="alert alert-info">No error location information available.</div>
                            @endif
                        </div>
                        @endif

                        <!-- Retry History Tab -->
                        @if($activeTab === 'retry_history')
                        <div class="tab-pane fade show active">
                            @if(count($retryHistory) > 0)
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Operation</th>
                                        <th>Flag</th>
                                        <th>Error</th>
                                        <th>Job</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($retryHistory as $log)
                                    <tr class="{{ $log['id'] == $failure->id ? 'table-active' : '' }}">
                                        <td>
                                            <small>{{ \Carbon\Carbon::parse($log['created_at'])->format('Y-m-d H:i:s') }}</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">{{ $log['operation_type'] }}</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning">{{ $log['flag_value'] }}</span>
                                        </td>
                                        <td>
                                            <small class="text-truncate d-inline-block" style="max-width: 200px;">
                                                {{ $log['error_message'] }}
                                            </small>
                                        </td>
                                        <td>
                                            <small>{{ $log['job_name'] }}</small>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            @else
                            <div class="alert alert-info">No retry history available for this variant.</div>
                            @endif
                        </div>
                        @endif
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeModal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-backdrop fade show"></div>
    @endif
</div>
