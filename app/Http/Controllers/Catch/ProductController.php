<?php

namespace App\Http\Controllers\Catch;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Catch\CatchProduct;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    private $api;

    public function __construct()
    {
        $this->api = MiraklShopApiClient::getShopApiClient();        
    }

    public function index() {
        if (request()->ajax()) {
            return datatables()->eloquent(CatchProduct::query())
            ->addColumn('action', function($row){
                $btn = '<a href="'.route('catch.product.edit', [$row->id]).'" class="edit btn btn-primary btn-sm">View</a>';
                return $btn;
            })
            ->editColumn('message', function($row){
                // return $row->message == null ? 'No' : 'Yes';
                if ($row->message) {
                    // $html = '<button type="button" class="btn btn-secondary" data-coreui-toggle="tooltip" data-coreui-placement="top" title="'. $row->message. '">View Error</button>';
                    $html = $row->message;
                } else {
                    $html = '';
                }
                return $html;
            })
            ->rawColumns(['action', 'message'])
            // ->make(true);
            ->toJson();
        }
        return view('catch.product.index');
    }


    public function edit($id) {
        try {
            $product = CatchProduct::with(['categoryFields.value' => function($query) use($id) {
                $query->where('product_id', $id);
            }, 'productTypeFields.value'  => function($query) use($id) {
                $query->where('product_id', $id);
            }])->findOrFail($id);

            $brands = Brand::all();
            $categories = Category::all();
            // $productTypes = ProductType::all();

            // return $product;
            return view('catch.product.edit', compact('product', 'brands', 'categories', 'productTypes'));
        } catch (\Exception $e) {
            report($e);
        }
    }


    public function save() {
        try {
            return redirect('catch.products')->with('success', 'Product details saved successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return back()->with('error', 'Counld not save product details.');
        }
    }

}
