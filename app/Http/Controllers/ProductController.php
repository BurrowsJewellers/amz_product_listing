<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFieldValue;
use App\Models\ProductType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{

    public function index() {
        if (request()->ajax()) {
            return datatables()->eloquent(Product::query())
            ->addColumn('action', function($row){
                $btn = '<a href="'.route('product.edit', [$row->id]).'" class="edit btn btn-primary btn-sm">View</a>';
                return $btn;
            })
            ->rawColumns(['action'])
            ->toJson();
        }
        return view('product.index');
    }


    public function edit($id) {
        try {
            $product = Product::with(['categoryFields.value' => function($query) use($id) {
                $query->where('product_id', $id);
            }, 'productTypeFields.value'  => function($query) use($id) {
                $query->where('product_id', $id);
            }])->findOrFail($id);

            $brands = Brand::all();
            $categories = Category::all();
            $productTypes = ProductType::all();

            // return $product;
            return view('product.edit', compact('product', 'brands', 'categories', 'productTypes'));
        } catch (\Exception $e) {
            Log::debug('ProdicyController@edit '. $e->getMessage());
        }
    }


    public function save() {
        try {
            $product = Product::findOrFail(request()->id);
            $categoryHasChanged = $product->category_id !== intval(request()->category_id) ? true : false;

            $fillable = (new Product())->getFillable();

            $category = Category::with('fields')->findOrFail(request()->category_id);
            $productType = ProductType::with('fields')->findOrFail(request()->product_type_id);

            foreach ($category->fields as $field) {
                if (isset(request()->cf[$field->amz_name])) {
                    $categoryFieldValues[] = [
                        'category_field_id' => $field->id,
                        'amz_name' => $field->amz_name,
                        'value' => request()->cf[$field->amz_name],
                    ];
                }
            }

            $productTypeFieldValues = [];
            foreach ($productType->fields as $field) {
                if (isset(request()->ptf[$field->amz_name])) {
                    $categoryFieldValues[] = [
                        'product_type_field_id' => $field->id,
                        'amz_name' => $field->amz_name,
                        'value' => request()->ptf[$field->amz_name],
                    ];
                }
            }

            $merged = array_merge($productTypeFieldValues, $categoryFieldValues);

            DB::beginTransaction();
            $product->update(request()->only($fillable));

            if ($categoryHasChanged) {
                ProductFieldValue::where('product_id', $product->id)->delete();
            }

            if (!empty($merged)) {
                foreach ($merged as $value) {
                    ProductFieldValue::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'category_field_id' => isset($value['category_field_id']) ? $value['category_field_id'] : null,
                            'product_type_field_id' => isset($value['product_type_field_id']) ? $value['product_type_field_id'] : null,
                        ],
                        [
                            'value' => $value['value'],
                        ]
                    );
                }
            }

            DB::commit();
            return redirect('products')->with('success', 'Product details saved successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::debug('ProdicyController@edit '. $e->getMessage() . ' at line '. $e->getLine());
            return back()->with('error', 'Counld not save product details.');
        }

    }


}
