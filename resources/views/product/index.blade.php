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
        <table id="products" class="table table-striped" style="width:100%">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>ID</th>
                    <th>Title</th>
                    <th>SKU</th>
                    <th>EAN</th>
                    <th>ASIN</th>
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

            var table = $("#products").DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ route('products') }}",
                    method: 'GET',
                    data: function(newData){
                        // newData.cid = $('#cid').val();
                    },
                },
                columns: [
                    {data: 'action', name: 'action', orderable: false, searchable: false},
                    {data: 'id', name: 'id'},
                    {data: 'title', name: 'title'},
                    {data: 'sku', name: 'sku'},
                    {data: 'ean', name: 'ean'},
                    {data: 'asin', name: 'asin'},
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



        });
    </script>
@endsection