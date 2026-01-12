<div>
    @if($show && $log)
    <div class="modal fade show" style="display: block;" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sync Operation Details - ID #{{ $log->id }}</h5>
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
                            <a class="nav-link {{ $activeTab === 'context_data' ? 'active' : '' }}"
                               wire:click="setActiveTab('context_data')"
                               style="cursor: pointer;">
                                Context Data
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $activeTab === 'errors' ? 'active' : '' }}"
                               wire:click="setActiveTab('errors')"
                               style="cursor: pointer;">
                                Errors
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $activeTab === 'history' ? 'active' : '' }}"
                               wire:click="setActiveTab('history')"
                               style="cursor: pointer;">
                                History ({{ count($history) }})
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
                                    <strong>Log ID:</strong> {{ $log->id }}<br>
                                    <strong>Marketplace:</strong>
                                    @if($log->marketplace === 'Shopify')
                                        <span class="badge bg-success">Shopify</span>
                                    @else
                                        <span class="badge bg-warning text-dark">Amazon</span>
                                    @endif
                                    <br>
                                    <strong>Item:</strong> <code>{{ $log->item_identifier }}</code><br>
                                    @if($log->item_title)
                                    <strong>Title:</strong> {{ $log->item_title }}<br>
                                    @endif
                                    <strong>Operation Type:</strong>
                                    <span class="badge bg-secondary">{{ ucwords(str_replace('_', ' ', $log->operation_type)) }}</span>
                                    <br>
                                    <strong>Status:</strong>
                                    @if($log->status === 'success')
                                        <span class="badge bg-success">Success</span>
                                    @elseif($log->status === 'failed')
                                        <span class="badge bg-danger">Failed</span>
                                    @elseif($log->status === 'pending')
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    @else
                                        <span class="badge bg-secondary">Skipped</span>
                                    @endif
                                </div>
                                <div class="col-md-6">
                                    <strong>Job Name:</strong> {{ $log->job_name }}<br>
                                    <strong>Created At:</strong> {{ $log->created_at->format('Y-m-d H:i:s') }}<br>
                                    <small class="text-muted">{{ $log->created_at->diffForHumans() }}</small>
                                    @if($log->from_value || $log->to_value)
                                    <br><br>
                                    <strong>Value Change:</strong><br>
                                    <code>{{ $log->from_value ?? 'null' }}</code> → <code>{{ $log->to_value ?? 'null' }}</code>
                                    @endif
                                    @if($log->retry_count > 0)
                                    <br><br>
                                    <strong>Retry Count:</strong> {{ $log->retry_count }}
                                    @endif
                                </div>
                            </div>
                            @if($log->message)
                            <div class="alert {{ $log->status === 'failed' ? 'alert-danger' : ($log->status === 'success' ? 'alert-success' : 'alert-info') }}">
                                <strong>Message:</strong><br>
                                {{ $log->message }}
                            </div>
                            @endif
                            @if($log->error_file && $log->error_line)
                            <div class="alert alert-warning mt-3">
                                <strong>Error Location:</strong><br>
                                <code>{{ $log->error_file }}:{{ $log->error_line }}</code>
                            </div>
                            @endif
                        </div>
                        @endif

                        <!-- API Request Tab -->
                        @if($activeTab === 'api_request')
                        <div class="tab-pane fade show active">
                            @if($log->api_request)
                            <pre class="bg-light p-3 rounded" style="max-height: 500px; overflow-y: auto;"><code>{{ $this->getFormattedJson($log->api_request) }}</code></pre>
                            @else
                            <div class="alert alert-info">No API request data available.</div>
                            @endif
                        </div>
                        @endif

                        <!-- API Response Tab -->
                        @if($activeTab === 'api_response')
                        <div class="tab-pane fade show active">
                            @if($log->api_response)
                            <pre class="bg-light p-3 rounded" style="max-height: 500px; overflow-y: auto;"><code>{{ $this->getFormattedJson($log->api_response) }}</code></pre>
                            @else
                            <div class="alert alert-info">No API response data available.</div>
                            @endif
                        </div>
                        @endif

                        <!-- Context Data Tab -->
                        @if($activeTab === 'context_data')
                        <div class="tab-pane fade show active">
                            @if($log->context_data)
                            <pre class="bg-light p-3 rounded" style="max-height: 500px; overflow-y: auto;"><code>{{ $this->getFormattedJson($log->context_data) }}</code></pre>
                            @else
                            <div class="alert alert-info">No context data available.</div>
                            @endif
                        </div>
                        @endif

                        <!-- Errors Tab -->
                        @if($activeTab === 'errors')
                        <div class="tab-pane fade show active">
                            @if($log->errors)
                            <pre class="bg-danger text-white p-3 rounded" style="max-height: 500px; overflow-y: auto;"><code>{{ $this->getFormattedJson($log->errors) }}</code></pre>
                            @else
                            <div class="alert alert-info">No error details available.</div>
                            @endif
                        </div>
                        @endif

                        <!-- History Tab -->
                        @if($activeTab === 'history')
                        <div class="tab-pane fade show active">
                            @if(count($history) > 0)
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Operation</th>
                                        <th>Status</th>
                                        <th>Message</th>
                                        <th>Job</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($history as $entry)
                                    <tr class="{{ $entry['id'] == $log->id ? 'table-active' : '' }}">
                                        <td>
                                            <small>{{ \Carbon\Carbon::parse($entry['created_at'])->format('Y-m-d H:i:s') }}</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">{{ ucwords(str_replace('_', ' ', $entry['operation_type'])) }}</span>
                                        </td>
                                        <td>
                                            @if($entry['status'] === 'success')
                                                <span class="badge bg-success">Success</span>
                                            @elseif($entry['status'] === 'failed')
                                                <span class="badge bg-danger">Failed</span>
                                            @elseif($entry['status'] === 'pending')
                                                <span class="badge bg-warning text-dark">Pending</span>
                                            @else
                                                <span class="badge bg-secondary">Skipped</span>
                                            @endif
                                        </td>
                                        <td>
                                            <small class="text-truncate d-inline-block" style="max-width: 200px;">
                                                {{ $entry['message'] ?? '-' }}
                                            </small>
                                        </td>
                                        <td>
                                            <small>{{ $entry['job_name'] }}</small>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            @else
                            <div class="alert alert-info">No history available for this item.</div>
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
