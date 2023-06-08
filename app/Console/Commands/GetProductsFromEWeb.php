<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\EWebController;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Marketplace;
use App\Models\ProductFieldValue;
use App\Models\ProductType;
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

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $eWeb = new EWebController;
                $resp = $eWeb->call('GetAllActiveItems');
                $activeItems = $resp->GetAllActiveItemsResult->ActiveItem;

                $marketplace = Marketplace::where('name', 'Amazon')->first();
                $category = Category::where(['name' => 'Jewelry', 'marketplace_id' => $marketplace->id])->with('fields')->first();
                $productType = ProductType::where(['name' => 'Necklace', 'category_id' => $category->id])->with('fields')->first();
                $brands = Brand::all();

                foreach ($activeItems as $item) {
                    $this->info('SKU ' . $item->SKU);

                    try {
                        if ($item->WebOptionBoolean7 == true) {
                            $brandsArray = [];

                            foreach ($brands as $brand) {
                                $brandsArray[$brand->brand_id]['id'] = $brand->id;
                                $brandsArray[$brand->brand_id]['name'] = $brand->name;
                            }

                            $productData = [];

                            $productData['title'] = $item->ShortMarketingDescription;
                            $productData['asin'] = null;

                            $barcode = trim($item->Barcode);

                            if (strlen($barcode) == 11 || strlen($barcode) == 12) {
                                $productData['upc'] = $barcode;
                            } elseif (strlen($barcode) == 13) {
                                $productData['ean'] = $barcode;
                            } 

                            $productData['brand_id'] = $brandsArray[$item->BrandID]['id'];
                            // $productData['marketplace_id'] = $marketplace->id;
                            $productData['category_id'] = $category->id;
                            $productData['product_type_id'] = $productType->id;
                            $productData['description'] = $item->MarketingDescription;
                            $productData['manufacturer'] = $brandsArray[$item->BrandID]['id'];
                            $productData['recommended_browse_nodes'] = '5131129051';
                            $productData['department_name'] = 'Womens';
                            $productData['size_name'] = 'Standard';

                            if ($brandsArray[$item->BrandID]['name'] == 'Thoms Sabo') {
                                $countryOfOrigin = 'GR';
                            } elseif ($brandsArray[$item->BrandID]['name'] == 'Ania Haie') {
                                $countryOfOrigin = 'UK';
                            } else {
                                $countryOfOrigin = 'AU';
                            }

                            $productData['country_of_origin'] = $countryOfOrigin;
                            $productData['item_type_name'] = $item->ShortMarketingDescription;
                            $productData['quantity'] = intval($item->TotalAvailQOH);
                            $productData['standard_price'] = $item->RetailPrice2;

                            $otherFields = [];

                            // Loop through the ItemsIDSs and add them in the main item object
                            foreach ($item->ISDs->ItemISD as $other) {
                                $keyName = str_replace(['.', ' ', ',', '_', '\''], [], $other->Name);
                                $otherFields[] = $keyName;
                                $item->{$keyName} = $other->Value;
                            }

                            /** Add the required field which are missing in eWeb API */
                            if (!property_exists($item, 'TargetGender')) {
                                $item->TargetGender = 'female';
                            }

                            if (!property_exists($item, 'SupplierDeclaredMaterialRegulation')) {
                                $item->SupplierDeclaredMaterialRegulation = 'not_applicable';
                            }

                            $categoryFieldValues = [];
                            foreach ($category->fields as $field) {
                                if (property_exists($item, $field->e_web_name)) {
                                    $categoryFieldValues[] = [
                                        'category_id' => $category->id,
                                        'product_type_id' => $productType->id,
                                        'category_field_id' => $field->id,
                                        // 'amz_name' => $field->amz_name,
                                        'value' => $item->{$field->e_web_name},
                                    ];
                                }
                            }

                            // dd($categoryFieldValues);

                            $productTypeFieldValues = [];
                            foreach ($productType->fields as $field) {
                                if (property_exists($item, $field->e_web_name)) {
                                    $productTypeFieldValues[] = [
                                        'category_id' => $category->id,
                                        'product_type_id' => $productType->id,
                                        'product_type_field_id' => $field->id,
                                        // 'amz_name' => $field->amz_name,
                                        'value' => $item->{$field->e_web_name},
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
                                        'sku' => $item->SKU,
                                        'marketplace_id' => $marketplace->id,
                                    ],
                                    $productData
                                );

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
                        } else {
                            $this->error('WebOptionBoolean7 false');
                        }
                    } catch (\Exception $e) {
                        Log::error("SKU : $item->SKU Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
                    }
                }

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
            }
            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
