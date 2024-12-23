<?php

namespace App\Console\Commands;

use App\Http\Controllers\ConfigController;
use App\Http\Controllers\EWebController;
use Illuminate\Console\Command;
use App\Http\Controllers\AmzFeedController;
use App\Models\Brand;
use App\Models\RetailEdgeProduct;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Services\RetailEdgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TestEWeb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'testEWeb';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('fetching products');

        // $activeItems = (new RetailEdgeService)->getAllActiveItems();

        $this->info('fetched');

        $activeItems = json_decode(Storage::get('retail_edge.json'));

        // dd($activeItems[1]);

        $brands = Brand::all();

        $brandsArray = [];

        foreach ($brands as $brand) {
            $brandsArray[$brand->brand_id]['id'] = $brand->id;
            $brandsArray[$brand->brand_id]['name'] = $brand->name;
        }

        foreach ($activeItems as $item) {
            try {
                if (!preg_match('/^\d{3}-\d{3}-\d{5}$/', $item->SKU)) {
                    continue;
                }

                $skuArray = array_map('trim', explode('-', $item->SKU));
                $sku = $skuArray[1] . "-" . $skuArray[2];

                $item->OldKey = trim($item->OldKey);
                $item->ID3 = trim($item->ID3);

                $isParent = $item->WebOptionBoolean3;
                $isChild = preg_match('/^\d{3}-\d{5}$/', $item->OldKey) ? true : false;

                // $this->info('Retail Edge SKU ' . $item->SKU);
                // $this->info('Formatted SKU ' . $sku);

                if (!preg_match('/^vt.*[0-9]$/', $item->ID3)) {
                }


                $barcode = trim($item->Barcode);

                $isParent = $item->WebOptionBoolean3;

                $skuParts = explode('-', $item->SKU);
                if (!count($skuParts) === 3) {
                    // continue;
                }

                $sku = $skuParts[1] . "-" . $skuParts[2];

                $this->info('Retail Edge SKU ' . $item->SKU);
                // $this->info('Formatted SKU ' . $sku);

                if (empty(trim($item->ID3)) && !$isParent) {
                    // $this->info('ID3 field is empty.');
                    // continue;
                }

                $vts = explode("-", $item->ID3);

                if (empty($vts)) {
                    // continue;
                }

                $isValidParentChild = preg_match('/^\d{3}-\d{5}$/', $item->OldKey) ? true : false;

                $barcode = trim($item->Barcode);

                // Loop through the ItemsIDSs and add them in the main item object
                foreach ($item->ISDs->ItemISD as $other) {
                    $keyName = str_replace(['.', ' ', ',', '_', '\''], [], $other->Name);
                    $item->{$keyName} = $other->Value;
                }

                $vtSize = $vtColor = $vtMaterial = $vtStyle = false;

                $variantTypes = ['vt1' => 'size', 'vt2' => 'color', 'vt3' => 'material', 'vt4' => 'style'];
                $shopifyVariantTitle = '';
                $productData = [];
                $optionIndex = 1;

                $productData['vendor'] = isset($brandsArray[$item->BrandID]['id']) ? $brandsArray[$item->BrandID]['name'] : null;

                foreach ($vts as $vt) {
                    $vt = strtolower(trim($vt));

                    if (isset($variantTypes[$vt])) {
                        $variantType = $variantTypes[$vt];

                        $productData["option{$optionIndex}_type"] = $variantType;

                        if ($vt == 'vt3') {
                            $productData["option{$optionIndex}"] = $item->SMetalType;
                            $shopifyVariantTitle = $item->SMetalType;
                            $optionIndex++;
                        }

                        if ($vt == 'vt4') {
                            $productData["option{$optionIndex}"] = $item->PendantStyle;
                            $shopifyVariantTitle = $item->PendantStyle;
                            $optionIndex++;
                        }
                    }
                }

                $productData['title'] = $item->ShortMarketingDescription;
                $productData['description'] = $item->MarketingDescription;
                $productData['quantity'] = intval($item->TotalAvailQOH);
                $productData['old_key'] = $item->OldKey;

                $retailPrice = number_format($item->RetailPrice, 2);
                $retailPrice2 = number_format($item->RetailPrice2, 2);

                $price = floatval(min($retailPrice, $retailPrice2));
                $compareAtPrice = floatval(max($retailPrice, $retailPrice2));

                if ($compareAtPrice < $price) {
                    $price = $compareAtPrice;
                }

                if (abs($compareAtPrice) === abs($price)) {
                    $compareAtPrice = null;
                }

                if ($compareAtPrice <= 0) {
                    $compareAtPrice = null;
                }

                $productData['price'] = $price > 0 ? $price : null;
                $productData['compare_at_price'] = $compareAtPrice;

                $productData['real_design_number'] = $item->RealDesignNum;

                /*
                RetailEdgeProduct::updateOrCreate(
                    [
                        'sku' => $sku,
                    ],
                    [
                        'title' => $productData['title'],
                        'marketing_description' => $productData['description'],
                        'retail_price_1' => $item->RetailPrice,
                        'retail_price_2' => $item->RetailPrice2,
                        'quantity' => intval($item->TotalAvailQOH),
                        'id_1' => $item->ID1,
                        'id_2' => $item->ID2,
                        'id_3' => $item->ID3,
                        'id_4' => $item->ID4,
                        'old_key' => $item->OldKey,
                        'real_design_number' => $item->RealDesignNum,
                        'pendant_style' => isset($item->PendantStyle) ? $item->PendantStyle : null,
                        's_web_menu' => isset($item->SWebMenu) ? $item->SWebMenu : null,
                        's_metal_type' => isset($item->SMetalType) ? $item->SMetalType : null,
                        's_stone_type' => isset($item->SStoneType) ? $item->SStoneType : null,
                        's_cat' => isset($item->SCat) ? $item->SCat : null,
                        's_sub_cat' => isset($item->SSubCat) ? $item->SSubCat : null,
                        'web_option_boolean_1' => $item->WebOptionBoolean1,
                        'web_option_boolean_2' => $item->WebOptionBoolean2,
                        'web_option_boolean_3' => $item->WebOptionBoolean3,
                        'web_option_boolean_4' => $item->WebOptionBoolean4,
                        'web_option_boolean_5' => $item->WebOptionBoolean5,
                        'web_option_boolean_6' => $item->WebOptionBoolean6,
                        'web_option_boolean_7' => $item->WebOptionBoolean6,
                        'web_option_boolean_8' => $item->WebOptionBoolean8,
                    ]
                );
                */

                $shopifyProductVariant = ShopifyProductVariant::where('sku', $sku)->first();

                DB::beginTransaction();

                $shopifyProduct = null;

                if ($shopifyProductVariant) {
                    $shopifyProduct = ShopifyProduct::find($shopifyProductVariant->shopify_product_id)->first();
                    if ($isParent) {
                        $shopifyProduct->title = $productData['title'];
                        $shopifyProduct->save();
                    }

                    $shopifyProductVariant->update(
                        [
                            'old_key' => $productData['old_key'],
                            'title' => $shopifyVariantTitle,
                            'price' => $productData['price'],
                            'compare_at_price' => $productData['compare_at_price'],
                            'option1_type' => $productData['option1_type'],
                            'option1' => $productData['option1'],
                            'option2_type' => $productData['option2_type'],
                            'option2' => $productData['option2'],
                            'option3_type' => $productData['option3_type'],
                            'option3' => $productData['option3'],
                            'barcode' => $barcode,
                            'inventory_quantity' => $productData['quantity'],
                        ]
                    );
                } else {
                    if ($isValidParentChild) {
                    }


                    // dd($productData);
                    if ($isParent) {
                        $shopifyProduct = ShopifyProduct::create(
                            [
                                'title' => $productData['title'],
                                'vendor' => $productData['vendor'],
                                // 'product_type' => '',
                                // 'handle' => '',
                                // 'tags' => '',
                                // 'status' => '',
                            ]
                        );

                        $shopifyProductVariant = ShopifyProductVariant::create(
                            [
                                'shopify_product_id' => $shopifyProduct->id,
                                'sku' => $sku,
                                'old_key' => $productData['old_key'],
                                'title' => $shopifyVariantTitle,
                                'price' => $price,
                                'compare_at_price' => $compareAtPrice,
                                'position' => 1,
                                'option1_type' => $productData['option1_type'],
                                'option1' => $productData['option1'],
                                'option2_type' => $productData['option2_type'],
                                'option2' => $productData['option2'],
                                'option3_type' => $productData['option3_type'],
                                'inventory_quantity' => $productData['quantity'],
                            ]
                        );
                    }
                }

                if ($shopifyProductVariant) {
                    if ($shopifyProductVariant->wasChanged()) {
                        $shopifyProductVariant->update(['requires_update' => 1]);
                    }
                }

                DB::commit();
            } catch (\Exception $e) {
                report($e);
                dd($e);
            }
        }



        exit;
        $c = new AmzFeedController();

        $c->updateMessage();
        exit;


        $eWeb = new EWebController;
        $params = ["SKU" => "001-024-05122"];
        // $resp = $eWeb->call('GetItemImagesBySKU', $params);
        $resp = $eWeb->call('GetActiveItemBySKU', $params);
        // $params = ["SKU" => "001-022-04646"];
        // $resp = $eWeb->call('GetActiveItemQOHBySKU', $params);
        dd($resp);
    }
}
