@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <h1 class="mb-4">Sync Monitoring Dashboard</h1>

    @livewire('sync-monitoring-dashboard')

    @livewire('sync-logs-table')

    @livewire('retry-progress-modal')

    @livewire('log-details-modal')
</div>
@endsection
