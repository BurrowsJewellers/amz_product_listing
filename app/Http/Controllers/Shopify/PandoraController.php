<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Services\ShopifyService;
use Illuminate\Http\Request;

class PandoraController extends Controller
{
    public function uploadData(Request $request)
    {
        try {
            (new ShopifyService)->uploadImages($request);

            return response()
                ->json(['message' => 'Data uploaded successfully'])
                ->header('Access-Control-Allow-Origin', '*') // or specify a specific domain
                ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With');
        } catch (\Exception $e) {
            report($e);
            return response()->json('failed', 500);
        }
    }
}
