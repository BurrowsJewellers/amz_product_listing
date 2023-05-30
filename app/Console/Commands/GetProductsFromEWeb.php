<?php

namespace App\Console\Commands;

use App\Http\Controllers\EWebController;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductFieldValue;
use App\Models\ProductType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        try {
            $this->info('Calling GetAllActiveItems');
            $eWeb = new EWebController;
            $resp = $eWeb->call('GetAllActiveItems');
            $activeItems = $resp->GetAllActiveItemsResult->ActiveItem;
            // dd($getAllActiveItemsResult);

            // $params = ["SKU" => "001-022-04646"];
            // $resp = $eWeb->call('GetActiveItemBySKU', $params);
            // dd($resp);
            // $item = $resp->GetActiveItemBySKUResult;
            // dd($resp->GetActiveItemBySKUResult);

            $marketplace = Marketplace::where('name', 'Amazon')->first();
            $category = Category::where(['name' => 'Jewelry', 'marketplace_id' => $marketplace->id])->with('fields')->first();
            $productType = ProductType::where(['name' => 'Necklace', 'category_id' => $category->id])->with('fields')->first();
            $brands = Brand::all();

            foreach ($activeItems as $item) {
                // dd($item);
                $this->info($item->SKU);

                if ($item->WebOptionBoolean7 == true) {
                    $brandsArray = [];
        
                    foreach ($brands as $brand) {
                        $brandsArray[$brand->brand_id]['id'] = $brand->id;
                        $brandsArray[$brand->brand_id]['name'] = $brand->name;
                    }
        
                    // dd($brandsArray);
                    // dd($brandsArray['1-63']['name']);
                    
                    $productData = [];
                    // $productData['sku'] = $item->SKU;
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
                    foreach ($item->ISDs->ItemISD as  $other) {
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
    
                    
                    // exit;
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
    
                        DB::commit();
                    } catch (\Exception $e) {
                        var_dump($e->getMessage());
                        DB::rollBack();
                    }
    
                } else {
                    $this->error('WebOptionBoolean7 false');
                }
            }
        } catch (\Exception $e) {
            $msg = 'getBrandsFromEWeb : '. $e->getMessage() . ' - Line : '. $e->getLine();
            Log::debug($msg);
            dd($msg);
        }

    }
}
