<div wire:poll.{{ config('sync.dashboard_refresh_interval', 2000) }}ms="refreshDashboard">
    <!-- Overview Stats -->
    <div class="row mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <div class="fs-4 fw-semibold">{{ $stats['total_operations'] ?? 0 }}</div>
                    <div>Total Operations</div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <div class="fs-4 fw-semibold">{{ $stats['total_successful'] ?? 0 }}</div>
                    <div>Successful ({{ $stats['success_rate'] ?? 0 }}%)</div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card text-white bg-danger">
                <div class="card-body">
                    <div class="fs-4 fw-semibold">{{ $stats['total_failed'] ?? 0 }}</div>
                    <div>Failed Operations</div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <div class="fs-4 fw-semibold">{{ $stats['operations_today'] ?? 0 }}</div>
                    <div>Operations Today</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Marketplace Breakdown -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <strong>Shopify</strong>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-4 text-center">
                            <div class="fs-4 fw-semibold text-primary">{{ $stats['shopify']['total'] ?? 0 }}</div>
                            <small>Total</small>
                        </div>
                        <div class="col-4 text-center">
                            <div class="fs-4 fw-semibold text-success">{{ $stats['shopify']['successful'] ?? 0 }}</div>
                            <small>Success</small>
                        </div>
                        <div class="col-4 text-center">
                            <div class="fs-4 fw-semibold text-danger">{{ $stats['shopify']['failed'] ?? 0 }}</div>
                            <small>Failed</small>
                        </div>
                    </div>
                    @if(isset($stats['shopify']['success_rate']))
                    <div class="progress mt-3" style="height: 20px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: {{ $stats['shopify']['success_rate'] }}%">
                            {{ $stats['shopify']['success_rate'] }}% Success Rate
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <strong>Amazon</strong>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-4 text-center">
                            <div class="fs-4 fw-semibold text-primary">{{ $stats['amazon']['total'] ?? 0 }}</div>
                            <small>Total</small>
                        </div>
                        <div class="col-4 text-center">
                            <div class="fs-4 fw-semibold text-success">{{ $stats['amazon']['successful'] ?? 0 }}</div>
                            <small>Success</small>
                        </div>
                        <div class="col-4 text-center">
                            <div class="fs-4 fw-semibold text-danger">{{ $stats['amazon']['failed'] ?? 0 }}</div>
                            <small>Failed</small>
                        </div>
                    </div>
                    @if(isset($stats['amazon']['success_rate']))
                    <div class="progress mt-3" style="height: 20px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: {{ $stats['amazon']['success_rate'] }}%">
                            {{ $stats['amazon']['success_rate'] }}% Success Rate
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Flag Status (Items needing attention) -->
    <div class="row mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <div class="fs-4 fw-semibold">{{ $stats['failures_flag_2'] ?? 0 }}</div>
                    <div>Items with Flag 2 (Retry Needed)</div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card text-white bg-dark">
                <div class="card-body">
                    <div class="fs-4 fw-semibold">{{ $stats['failures_flag_3'] ?? 0 }}</div>
                    <div>Items with Flag 3 (Repeated Failure)</div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card text-white bg-secondary">
                <div class="card-body">
                    <div class="fs-4 fw-semibold">{{ $stats['failed_today'] ?? 0 }}</div>
                    <div>Failed Today</div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <div class="fs-4 fw-semibold">{{ $stats['operations_this_week'] ?? 0 }}</div>
                    <div>This Week</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <strong>Actions</strong>
                </div>
                <div class="card-body">
                    <button
                        wire:click="retryAllFailed"
                        class="btn btn-primary btn-lg"
                        wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="retryAllFailed">
                            <i class="cil-reload"></i> Retry All Failed Items
                        </span>
                        <span wire:loading wire:target="retryAllFailed">
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            Starting...
                        </span>
                    </button>
                    <small class="d-block mt-2 text-muted">
                        This will retry all items with flags 2 and 3 in the background.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Retry Jobs -->
    @if(count($activeRetryJobs) > 0)
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <strong>Active Retry Jobs</strong>
                </div>
                <div class="card-body">
                    @foreach($activeRetryJobs as $job)
                    <div class="alert alert-info mb-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Job #{{ $job['id'] }}</strong> - Triggered by: {{ $job['triggered_by'] }}
                                <br>
                                <small>Started: {{ \Carbon\Carbon::parse($job['started_at'])->diffForHumans() }}</small>
                            </div>
                            <div class="text-end">
                                @if($job['total_items'] > 0)
                                <div class="progress" style="width: 200px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated"
                                         role="progressbar"
                                         style="width: {{ ($job['processed_items'] / $job['total_items']) * 100 }}%">
                                        {{ $job['processed_items'] }} / {{ $job['total_items'] }}
                                    </div>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
