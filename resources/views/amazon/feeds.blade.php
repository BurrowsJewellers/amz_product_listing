@extends('layouts.app')

@section('content')

<div class="card">
    <div class="card-body">
        @if(session()->has('success'))
        <div class="alert alert-success alert-dismissible" role="alert">
            {{ session()->get('success') }}
            <button type="button" class="btn-close" data-coreui-dismiss="alert" aria-label="Close"></button>
        </div>
        @endif
        <table id="amzFeeds" class="table table-striped" style="width:100%">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Amazon Feed ID</th>
                    <th>Type</th>
                    <th>Processing Status</th>
                    <th>Feed XML</th>
                    <th>Response XML</th>
                    <th>Created at</th>
                    <th>Last Updated at</th>
                </tr>
            </thead>
            <tbody>
            </tbody>
        </table>
    </div>
</div>
@endsection

@section('scripts')
<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function() {
        "use strict"

        var table = $("#amzFeeds").DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: "{{ route('amazon.feeds') }}",
                method: 'GET',
                data: function(newData) {
                    // newData.cid = $('#cid').val();
                },
            },
            columns: [{
                    name: 'id',
                    data: 'id'
                },
                {
                    name: 'feed_id',
                    data: 'feed_id'
                },
                {
                    name: 'type',
                    data: 'type'
                },
                {
                    name: 'processing_status',
                    data: 'processing_status'
                },
                {
                    name: 'feed_xml',
                    data: 'feed_xml'
                },
                {
                    name: 'response_xml',
                    data: 'response_xml'
                },
                {
                    name: 'created_at',
                    data: 'created_at'
                },
                {
                    name: 'updated_at',
                    data: 'updated_at'
                },
            ],

            aLengthMenu: [
                [10, 25, 50, 100],
                [10, 25, 50, 100]
            ],

            order: [
                [0, "desc"]
            ],
            initComplete: function(settings, json) {
                $(".dt-buttons .btn").removeClass("btn-secondary")

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
@endsection