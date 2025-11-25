@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <h1 class="mb-4">Sync Monitoring Dashboard</h1>

    @livewire('sync-monitoring-dashboard')

    @livewire('sync-failures-table')

    @livewire('retry-progress-modal')

    @livewire('failure-details-modal')
</div>
@endsection

@push('scripts')
@livewireScripts
@endpush
