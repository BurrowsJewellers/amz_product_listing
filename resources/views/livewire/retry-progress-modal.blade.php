<div>
    @if($show && $retryJob)
    <div class="modal fade show" style="display: block;" tabindex="-1" wire:poll.2s="refreshProgress">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Retry Job Progress</h5>
                    @if($retryJob->isCompleted())
                    <button type="button" class="btn-close" wire:click="closeModal"></button>
                    @endif
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <strong>Job ID:</strong> #{{ $retryJob->id }}<br>
                        <strong>Status:</strong>
                        @if($retryJob->status === 'processing')
                            <span class="badge bg-info">Processing</span>
                        @elseif($retryJob->status === 'completed')
                            <span class="badge bg-success">Completed</span>
                        @elseif($retryJob->status === 'failed')
                            <span class="badge bg-danger">Failed</span>
                        @else
                            <span class="badge bg-secondary">{{ $retryJob->status }}</span>
                        @endif
                        <br>
                        <strong>Triggered by:</strong> {{ $retryJob->triggered_by }}<br>
                        <strong>Started:</strong> {{ $retryJob->started_at->format('Y-m-d H:i:s') }}
                        ({{ $retryJob->started_at->diffForHumans() }})
                    </div>

                    @if($retryJob->total_items > 0)
                    <div class="mb-3">
                        <h6>Overall Progress</h6>
                        <div class="progress mb-2" style="height: 30px;">
                            <div class="progress-bar progress-bar-striped {{ $retryJob->isCompleted() ? '' : 'progress-bar-animated' }}"
                                 role="progressbar"
                                 style="width: {{ $this->progressPercentage }}%">
                                {{ number_format($this->progressPercentage, 1) }}%
                            </div>
                        </div>
                        <div class="text-center">
                            <strong>{{ $retryJob->processed_items }} / {{ $retryJob->total_items }}</strong> items processed
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <div class="fs-4 fw-semibold">{{ $retryJob->successful_items }}</div>
                                    <div>Successful</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-danger text-white">
                                <div class="card-body text-center">
                                    <div class="fs-4 fw-semibold">{{ $retryJob->failed_items }}</div>
                                    <div>Failed</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if($retryJob->processed_items > 0)
                    <div class="mt-3">
                        <small class="text-muted">
                            Success Rate: {{ number_format($this->successRate, 1) }}%
                        </small>
                    </div>
                    @endif
                    @endif

                    @if($retryJob->status === 'failed' && $retryJob->error_message)
                    <div class="alert alert-danger mt-3">
                        <strong>Error:</strong> {{ $retryJob->error_message }}
                    </div>
                    @endif

                    @if($retryJob->isCompleted())
                    <div class="alert alert-info mt-3">
                        <strong>Job completed at:</strong> {{ $retryJob->completed_at->format('Y-m-d H:i:s') }}
                        ({{ $retryJob->completed_at->diffForHumans() }})
                    </div>
                    @endif
                </div>
                <div class="modal-footer">
                    @if($retryJob->isCompleted())
                    <button type="button" class="btn btn-secondary" wire:click="closeModal">Close</button>
                    @else
                    <button type="button" class="btn btn-secondary" disabled>
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        Processing...
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="modal-backdrop fade show"></div>
    @endif
</div>
