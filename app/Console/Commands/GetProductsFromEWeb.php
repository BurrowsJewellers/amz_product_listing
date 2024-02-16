<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\EWebController;
use App\Models\Brand;
use App\Models\Category;
use App\Models\EWebShortCode;
use App\Models\Marketplace;
use App\Models\ProductFieldValue;
use App\Models\Product;
use App\Models\ProductImage;

class GetProductsFromEWeb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'getProductsFromEWeb';

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
        $marketplace = 'EWeb';
        $jobType = 'getProductsFromEWeb';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $eWeb = new EWebController;
                $resp = $eWeb->call('GetAllActiveItems');
                $activeItems = $resp->GetAllActiveItemsResult->ActiveItem;

                $marketplaceObj = Marketplace::where('name', 'Amazon')->first();
                $category = Category::where(['name' => 'Jewelry', 'marketplace_id' => $marketplaceObj->id])->with('fields')->first();
                $brands = Brand::all();
                $brandsArray = [];

                foreach ($brands as $brand) {
                    $brandsArray[$brand->brand_id]['id'] = $brand->id;
                    $brandsArray[$brand->brand_id]['name'] = $brand->name;
                }

                $productType = null;

                $webOptionBoolean7FalseSkuArray = [];

                foreach ($activeItems as $item) {
                    try {
                        $skuParts = explode('-', $item->SKU);
                        if (!count($skuParts) === 3) {
                            continue;
                        }

                        $sku = $skuParts[1] . "-" . $skuParts[2];

                        $this->info('Retail Edge SKU ' . $item->SKU);
                        $this->info('Formatted SKU ' . $sku);

                        if ($item->WebOptionBoolean7 !== true) {
                            $webOptionBoolean7FalseSkuArray[] = $sku;
                            continue;
                        }

                        if (trim($item->ID1) == '') {
                            $this->info('ID1 field is empty.');
                            continue;
                        }

                        $eWebCodes = explode(" ", $item->ID1);

                        if (!isset($eWebCodes[1])) {
                            $this->info('Short code for Amazon not found.');
                            continue;
                        }

                        $eWebCode = $eWebCodes[1];

                        $shortCode = EWebShortCode::where('code', $eWebCode)->with('productType.fields')->first();

                        if (!$shortCode) {
                            $this->info('Short code not found in EWebShortCode.');
                            continue;
                        }

                        $productType = $shortCode->productType;

                        $productData = [];

                        $productData['title'] = $item->ShortMarketingDescription;
                        // $productData['asin'] = null;

                        $barcode = trim($item->Barcode);

                        if (strlen($barcode) == 11 || strlen($barcode) == 12) {
                            $productData['upc'] = $barcode;
                        } elseif (strlen($barcode) == 13) {
                            $productData['ean'] = $barcode;
                        }

                        $productData['brand_id'] = isset($brandsArray[$item->BrandID]['id']) ? $brandsArray[$item->BrandID]['id'] : null;
                        $productData['category_id'] = $category->id;
                        $productData['product_type_id'] = $productType->id;
                        $productData['description'] = $item->MarketingDescription;
                        $productData['manufacturer'] = isset($brandsArray[$item->BrandID]['id']) ? $brandsArray[$item->BrandID]['name'] : null;

                        $productData['department_name'] = $shortCode->code[1] == 'W' ? 'Womens' : 'Mens';

                        if ($shortCode->code == 'AWNE') {
                            $productData['size_name'] = 'Standard';
                        } elseif ($shortCode->code == 'AWEA') {
                            $productData['size_name'] = 'Small';
                        }

                        $countryOfOrigin = 'AU';
                        if (isset($brandsArray[$item->BrandID])) {
                            if ($brandsArray[$item->BrandID]['name'] == 'Thoms Sabo') {
                                $countryOfOrigin = 'GR';
                            } elseif ($brandsArray[$item->BrandID]['name'] == 'Ania Haie') {
                                $countryOfOrigin = 'UK';
                            } else {
                                $countryOfOrigin = 'AU';
                            }
                        } else {
                            Log::error("Brand id : $item->BrandID, for sku: $sku not found in brandsArray.");
                        }

                        $productData['country_of_origin'] = $countryOfOrigin;
                        $productData['item_type_name'] = $item->ShortMarketingDescription;
                        $productData['quantity'] = intval($item->TotalAvailQOH);
                        $productData['retail_price'] = number_format($item->RetailPrice, 2);
                        $productData['retail_price2'] = number_format($item->RetailPrice2, 2);
                        $productData['real_design_number'] = $item->RealDesignNum;
                        $productData['e_web_code'] = $shortCode->code;

                        $otherFields = [];

                        // Loop through the ItemsIDSs and add them in the main item object
                        foreach ($item->ISDs->ItemISD as $other) {
                            $keyName = str_replace(['.', ' ', ',', '_', '\''], [], $other->Name);
                            $otherFields[] = $keyName;
                            $item->{$keyName} = $other->Value;
                        }

                        /** Add the required field which are missing in eWeb API */
                        // if (!property_exists($item, 'TargetGender')) {
                        $item->TargetGender = $shortCode->code[1] == 'W' ? 'female' : 'male';
                        // }

                        // if (!property_exists($item, 'SupplierDeclaredMaterialRegulation')) {
                        $item->SupplierDeclaredMaterialRegulation = 'not_applicable';
                        // }

                        // $item->RingSize = 'Adjustable';

                        $item->MovementType = 'Quartz';
                        $item->AgeRangeDescription = 'Adult';
                        $item->WarrantyType = 'Manufacturer';
                        $item->TargetAudienceBase = strtolower($productData['department_name']);

                        if (property_exists($item, 'Length')) {
                            $productData['item_length_numeric'] = str_replace('cm', '', $item->Length);
                            $productData['item_length_numeric_unit'] = 'centimeters';
                        }

                        $categoryFieldValues = [];
                        foreach ($category->fields as $field) {
                            if (property_exists($item, $field->e_web_name)) {

                                $fValue = $item->{$field->e_web_name};
                                if ($field->e_web_name == 'SMetalType' && $item->{$field->e_web_name} == 'N/A') {
                                    $fValue = 'No Metal';
                                }

                                $categoryFieldValues[] = [
                                    'category_id' => $category->id,
                                    'product_type_id' => $productType->id,
                                    'category_field_id' => $field->id,
                                    // 'amz_name' => $field->amz_name,
                                    'value' => $fValue,
                                ];
                            }
                        }

                        // dd($categoryFieldValues);

                        $productTypeFieldValues = [];
                        foreach ($productType->fields as $field) {
                            if (property_exists($item, $field->e_web_name)) {

                                $fValue = $item->{$field->e_web_name};
                                if ($field->e_web_name == 'SStoneType' && $item->{$field->e_web_name} == 'N/A') {
                                    $fValue = 'No Gemstone';
                                }

                                $productTypeFieldValues[] = [
                                    'category_id' => $category->id,
                                    'product_type_id' => $productType->id,
                                    'product_type_field_id' => $field->id,
                                    // 'amz_name' => $field->amz_name,
                                    'value' => $fValue,
                                ];
                            }
                        }

                        $merged = array_merge($productTypeFieldValues, $categoryFieldValues);

                        // dd($merged);

                        $productImages = [];

                        // Log::debug(print_r($item->Images, true));
                        if (isset($item->Images) && isset($item->Images->ItemImage) && !empty($item->Images->ItemImage)) {
                            if (is_object($item->Images->ItemImage)) {
                                $productImages[] = [
                                    'e_web_index' => $item->Images->ItemImage->Index,
                                    'width' => $item->Images->ItemImage->Width,
                                    'height' => $item->Images->ItemImage->Height,
                                    'url' => htmlspecialchars_decode($item->Images->ItemImage->URL),
                                ];
                            } elseif (is_array($item->Images->ItemImage)) {
                                foreach ($item->Images->ItemImage as $image) {
                                    $productImages[] = [
                                        'e_web_index' => $image->Index,
                                        'width' => $image->Width,
                                        'height' => $image->Height,
                                        'url' => htmlspecialchars_decode($image->URL),
                                    ];
                                }
                            }
                        }

                        DB::beginTransaction();

                        try {
                            $product = Product::updateOrCreate(
                                [
                                    'sku' => $sku,
                                    'marketplace_id' => $marketplaceObj->id,
                                ],
                                $productData
                            );

                            $newData = [];
                            if ($product->wasChanged('quantity')) {
                                $newData['inventory_feed_status'] = 0;
                            }

                            if ($product->wasChanged('retail_price') || $product->wasChanged('retail_price2')) {
                                $newData['price_feed_status'] = 0;
                                Log::debug("$product->sku retail_price or retail_price2 changed.");
                            }

                            if (!empty($newData)) {
                                $product->update($newData);
                                $product = $product->refresh();
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
                                            'category_id' => $value['category_id'],
                                            'product_type_id' => $value['product_type_id'],
                                            'value' => $value['value'],
                                        ]
                                    );
                                }
                            }

                            if (!empty($productImages)) {
                                foreach ($productImages as $productImage) {
                                    ProductImage::updateOrCreate(
                                        [
                                            'product_id' => $product->id,
                                            'e_web_index' => $productImage['e_web_index']
                                        ],
                                        [
                                            'width' => $productImage['width'],
                                            'height' => $productImage['height'],
                                            'url' => $productImage['url'],
                                        ]
                                    );
                                }
                            }

                            DB::commit();
                        } catch (\Exception $e) {
                            var_dump($e->getMessage());
                            DB::rollBack();
                        }
                    } catch (\Exception $e) {
                        Log::error("SKU : $sku Error : " . $e->getFile() . ' : ' . $e->getMessage() . ' Line : ' . $e->getLine());
                    }
                }

                if (!empty($webOptionBoolean7FalseSkuArray)) {
                    foreach ($webOptionBoolean7FalseSkuArray as $sku) {
                        if ($product = Product::where('sku', $sku)->first()) {
                            if ($product->quantity > 0) {
                                $product->update([
                                    'quantity' => 0,
                                    'inventory_feed_status' => 0
                                ]);
                            }
                        }
                    }
                }

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() . ' Line : ' . $e->getLine());
            }
            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
