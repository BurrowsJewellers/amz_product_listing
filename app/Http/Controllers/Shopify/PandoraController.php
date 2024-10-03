<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Models\PandoraList;
use App\Models\RetailEdgeProduct;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Illuminate\Http\Request;

class PandoraController extends Controller
{
    public function uploadData(Request $request)
    {
        try {
            $retailEdgeProduct = RetailEdgeProduct::where('real_design_number', $request->design_no)->first();

            if (!$retailEdgeProduct) {
                throw new \Exception("RetailEdge Product not found with real_design_number {$request->design_no}");
            }

            $variant = ShopifyProductVariant::where('sku', $retailEdgeProduct->sku)->first();

            if (!$variant) {
                throw new \Exception("Shopify variant not found with SKU {$retailEdgeProduct->sku}");
            }

            $imagesArray = explode(",", implode(",", $request->image_links));
            $imagesJson = json_encode($imagesArray, JSON_UNESCAPED_SLASHES);

            $pandoraProduct = PandoraList::updateOrCreate(
                [
                    'design_no' => $retailEdgeProduct->real_design_number,
                ],
                [
                    'sku' => $retailEdgeProduct->sku,
                    'search_response' => "From Chrome Extension",
                    'product_name' => $request->product_name,
                    'product_description' => $request->product_description,
                    'product_url' => $request->product_url,
                    'product_response' => "From Chrome Extension",
                    'discontinued' => 0,
                    'images' => $imagesJson,
                ]
            );

            $images = json_decode($pandoraProduct->images);

            foreach ($images as $imageUrl) {
                (new ShopifyService)->uploadImages($variant, $imageUrl);
            }

            return response()
                ->json(['message' => 'Data uploaded successfully'])
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With');
        } catch (\Exception $e) {
            report($e);
            return response()->json('failed', 500);
        }
    }
}
