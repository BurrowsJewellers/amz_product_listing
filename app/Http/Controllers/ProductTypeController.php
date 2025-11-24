<?php

namespace App\Http\Controllers;

use App\Models\ProductType;
use Illuminate\Support\Facades\Log;

class ProductTypeController extends Controller
{
    public function getProductTypes()
    {
        try {
            return ProductType::select('id', 'name', 'category_id')->where('category_id', request('category_id'))->get();
        } catch (\Exception $e) {
            Log::debug('ProductTypeController@getProductTypes : '.$e->getMessage());

            return [];
        }
    }
    //
}
