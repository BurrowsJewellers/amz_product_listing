@extends('layouts.app')

@section('content')

<div class="card">
    <div class="card-body">
        <h4>{{ $product->title }}</h4>
        @if(session()->has('success'))
            <div class="alert alert-success alert-dismissible" role="alert">
                {{ session()->get('success') }}
                <button type="button" class="btn-close" data-coreui-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <form action="{{ route('product.save') }}" method="post">
            @csrf
            <input type="hidden" name="id" value="{{ $product->id }}"/>
            <div class="mt-4">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="title" class="form-label">Product Title</label>
                        <input type="text" class="form-control" id="title" name="title" value="{{ $product->title }}" required>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="sku" class="form-label">SKU</label>
                        <input type="text" class="form-control" id="sku" name="sku" value="{{ $product->sku }}">
                    </div>

                    <div class="col-md-12 mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" required>{{ $product->description }}</textarea>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="asin" class="form-label">ASIN</label>
                        <input type="text" class="form-control" id="asin" name="asin" value="{{ $product->asin }}">
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="ean" class="form-label">EAN</label>
                        <input type="text" class="form-control" id="ean" name="ean" value="{{ $product->ean }}">
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="upc" class="form-label">UPC</label>
                        <input type="text" class="form-control" id="upc" name="upc" value="{{ $product->upc }}">
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="brand" class="form-label">Brand</label>
                        <select class="form-select" id="brand_id" name="brand_id" aria-label="Brand" required>
                            <option value=""> -- </option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}" @if($brand->id == $product->brand_id) selected @endif>{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="category" class="form-label">Category</label>
                        <select class="form-select" id="category_id" name="category_id" aria-label="Category" required>
                            <option value=""> -- </option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @if($category->id == $product->category_id) selected @endif>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="brand" class="form-label">Sub Category</label>
                        <select class="form-select" id="product_type_id" name="product_type_id" aria-label="Sub Category" required>
                            <option value=""> -- </option>
                            @foreach ($productTypes as $productType)
                                <option value="{{ $productType->id }}" @if($productType->id == $product->product_type_id) selected @endif>{{ $productType->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="department_name" class="form-label">Department Name</label>
                        <input type="text" class="form-control" id="department_name" name="department_name" value="{{ $product->department_name }}" required>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="size_name" class="form-label">Size Name</label>
                        <input type="text" class="form-control" id="size_name" name="size_name" value="{{ $product->size_name }}" required>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="country_of_origin" class="form-label">Country of Origin</label>
                        <input type="text" class="form-control" id="country_of_origin" name="country_of_origin" value="{{ $product->country_of_origin }}" required>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="item_type_name" class="form-label">Item Type Name</label>
                        <input type="text" class="form-control" id="item_type_name" name="item_type_name" value="{{ $product->item_type_name }}">
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="quantity" class="form-label">Quantity</label>
                        <input type="number" step="1" class="form-control" id="quantity" name="quantity" value="{{ $product->quantity }}" required>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="retail_price" class="form-label">Price</label>
                        <input type="number" step="0.01" class="form-control" id="retail_price" name="retail_price" value="{{ $product->retail_price }}" required>
                    </div>
                </div>

                <div class="row">
                    @foreach ($product->categoryFields as $catField) 
                        <div class="col-md-4 mb-3">
                            <label for="{{ $catField->amz_name }}" class="form-label">{{ $catField->amz_name }}</label>
                            <input type="text" class="form-control" id="{{ $catField->amz_name }}" name="cf[{{ $catField->amz_name }}]" value="{{ $catField->value->value }}">
                        </div>
                    @endforeach

                    @foreach ($product->productTypeFields as $typeField) 
                        <div class="col-md-4 mb-3">
                            <label for="{{ $typeField->amz_name }}" class="form-label">{{ $typeField->amz_name }}</label>
                            <input type="text" class="form-control" id="{{ $typeField->amz_name }}" name="ptf[{{ $typeField->amz_name }}]" value="{{ $typeField->value->value }}">
                        </div>
                    @endforeach
                </div>

                <div class="row">
                    <div class="col">
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script src="https://code.jquery.com/jquery-3.7.0.min.js" integrity="sha256-2Pmvv0kuTBOenSvLm6bvfBSSHrUJ+3A7x6P5Ebd07/g=" crossorigin="anonymous"></script>
<script>
    $(document).ready(function () {
        $('#category_id').change(function() {
            var category_id = $(this).val();
            if (category_id) {
                $.ajax({
                    type: "GET",
                    url: "{{url('/get/producttypes')}}?category_id="+ category_id,
                    success: function(res) {
                        if (res) {
                            $("#product_type_id").empty();
                            $("#product_type_id").append('<option value=""> Select </option>');
                            $.each(res, function(key, value) {
                                $("#product_type_id").append('<option value="' + value.id + '">' + value.name + '</option>');
                            });
                        } else {
                            $("#product_type_id").empty();
                        }
                    }
                });
            } else {
                $("#product_type_id").empty();
            }
        });
    });

</script>
@endsection
