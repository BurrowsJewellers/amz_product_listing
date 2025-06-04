@extends('layouts.app') {{-- Assuming a main layout file, adjust if different, e.g., layouts.admin --}}

@push('styles')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    {{-- If your app primarily uses Tailwind and not Bootstrap for layout,
         you might prefer the default DataTables CSS or a Tailwind-specific DataTables theme.
         For now, using Bootstrap 5 styling to match the JS. --}}
    <style>
        /* Minimal styling to ensure table is visible and functional if main layout is sparse */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_processing,
        .dataTables_wrapper .dataTables_paginate {
            padding: 0.5em;
        }

        .table {
            width: 100%;
            margin-bottom: 1rem;
            color: #212529;
        }

        .table th,
        .table td {
            padding: 0.75rem;
            vertical-align: top;
            border-top: 1px solid #dee2e6;
        }

        .table thead th {
            vertical-align: bottom;
            border-bottom: 2px solid #dee2e6;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, .05);
        }
    </style>
@endpush

@section('content')
    <div class="card">
        <div class="card-body">
            {{-- <h2 class="text-2xl font-semibold leading-tight">Price & Inventory Change Logs</h2> --}}

            <table class="min-w-full leading-normal table table-striped" id="price-inventory-logs-table" style="width:100%;">
                <thead>
                    <tr>
                        <th
                            class="px-3 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            ID</th>
                        <th
                            class="px-3 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Timestamp</th>
                        <th
                            class="px-3 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Marketplace</th>
                        <th
                            class="px-3 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Item ID</th>
                        <th
                            class="px-3 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Change Type</th>
                        <th
                            class="px-3 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            From</th>
                        <th
                            class="px-3 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            To</th>
                        <th
                            class="px-3 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Status</th>
                        <th
                            class="px-3 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Job</th>
                        <th
                            class="px-3 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Message</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- DataTables will populate this --}}
                </tbody>
            </table>

        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            "use strict";

            var table = $("#price-inventory-logs-table").DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ route('price_inventory_logs.index') }}",
                    method: 'GET',
                    data: function(d) {}
                },
                columns: [{
                        data: 'id',
                        name: 'id'
                    },
                    {
                        data: 'created_at',
                        name: 'created_at'
                    },
                    {
                        data: 'marketplace',
                        name: 'marketplace'
                    },
                    {
                        data: 'item_identifier',
                        name: 'item_identifier'
                    },
                    {
                        data: 'change_type',
                        name: 'change_type'
                    },
                    {
                        data: 'from_value',
                        name: 'from_value'
                    },
                    {
                        data: 'to_value',
                        name: 'to_value'
                    },
                    {
                        data: 'status',
                        name: 'status',
                        orderable: true,
                        searchable: true
                    }, // searchable if controller handles it
                    {
                        data: 'job_name',
                        name: 'job_name'
                    },
                    {
                        data: 'message',
                        name: 'message',
                        orderable: false,
                        searchable: false
                    } // Message might be too long for easy search/order
                ],
                order: [
                    [1, "desc"]
                ], // Order by created_at desc by default
                aLengthMenu: [
                    [25, 50, 100, -1],
                    [25, 50, 100, "All"]
                ],
                initComplete: function(settings, json) {
                    $(".dataTables_filter input")
                        .unbind()
                        .bind("input", function(e) {
                            if (this.value.length >= 3 || e.keyCode == 13) {
                                table.search(this.value).draw();
                            }
                            if (this.value == "") {
                                table.search("").draw();
                            }
                            return;
                        });
                }
            });
        });
    </script>
@endpush
