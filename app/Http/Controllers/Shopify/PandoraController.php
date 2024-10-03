<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Services\ShopifyService;
use Illuminate\Http\Request;

class PandoraController extends Controller
{
    public function uploadData(Request $request)
    {
        (new ShopifyService)->uploadImages($request);
    }
}
