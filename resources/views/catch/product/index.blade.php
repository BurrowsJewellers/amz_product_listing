@extends('layouts.app')

@section('style')
<style>

table.dataTable tr.dt-hasChild td.dt-control:before {
    content: "▼";
}

table.dataTable td.dt-control:before {
    display: inline-block;
    color: rgba(0, 0, 0, 0.5);
    content: "►";
}

table.dataTable td.dt-control {
    text-align: center;
    cursor: pointer;
}

</style>

@endsection

@section('content')

<div class="card">
    <div class="card-body">
        @if(session()->has('success'))
            <div class="alert alert-success alert-dismissible" role="alert">
                {{ session()->get('success') }}
                <button type="button" class="btn-close" data-coreui-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif
        <table id="products" class="table table-striped" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th>Action</th>
                    <th>ID</th>
                    <th>Title</th>
                    <th>SKU</th>
                    <th>EAN</th>
                    <th>Price</th>
                    <th>Inventory</th>
                    <th>Has Error</th>
                    <!-- <th>Status</th> -->
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
        $(document).ready(function () {
            "use strict"

            function format(d) {
                if (d.message !== "") {
                    return (
                        '<dl>' +
                            '<dt>'+ d.message +'</dt>' +
                        '</dl>'
                    );
                } else {
                    return ;
                }
            }

            var table = $("#products").DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ route('catch.products') }}",
                    method: 'GET',
                    data: function(newData){
                        // newData.cid = $('#cid').val();
                    },
                },
                columns: [
                    {className: 'dt-control', orderable: false, data: null, defaultContent: ''},                    
                    {data: 'action', name: 'action', orderable: false, searchable: false},
                    {data: 'id', name: 'id'},
                    {data: 'title', name: 'title'},
                    {data: 'sku', name: 'sku'},
                    {data: 'product_reference_value', name: 'product_reference_value'},
                    {data: 'price', name: 'price'},
                    {data: 'quantity', name: 'quantity'},
                    {data: 'message', name: 'message', render: function ( data, type, row, meta ) { return data ? 'Yes' : 'No'; }},
                ],

                columnDefs: [
                    { 
                        targets: 1, 
                        render: function(value){
                            return value;
                            // return moment(created_at).format('DD MMM, YYYY');
                        } 
                    },
                ],

                aLengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],

                order: [[1, "desc"]],
                initComplete: function (settings, json) {
                    $(".dt-buttons .btn").removeClass("btn-secondary")

                $(".dataTables_filter input")
                    .unbind()
                    .bind("input", function(e) {
                        if(this.value.length >= 3 || e.keyCode == 13) {
                            table.search(this.value).draw();
                        }
                        if(this.value == "") {
                            table.search("").draw();
                        }
                        return;
                    });
                }
            });

            table.on('click', 'td.dt-control', function (e) {
                let tr = e.target.closest('tr');
                let row = table.row(tr);
            
                if (row.child.isShown()) {
                    // This row is already open - close it
                    row.child.hide();
                }
                else {
                    // Open this row
                    row.child(format(row.data())).show();
                }
            });
        });
    </script>
@endsection