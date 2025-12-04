<?php

namespace App\Console\Commands\EWeb;

use App\Http\Controllers\EWebController;
use App\Http\Controllers\SyncJobController;
use App\Models\Brand;
use App\Models\Category;
use App\Models\EWebShortCode;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductFieldValue;
use App\Models\ProductImage;
use App\Services\RetailEdgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GetProductsFromEWebForAmazon extends Command
{
    private array $brandsArray = [];

    private ?Category $category = null;

    private ?Marketplace $marketplace = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'getProductsFromEWebAmazon';

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
        $jobType = 'getProductsFromEWebAmazon';
        $job = SyncJobController::getJob($jobType, $marketplace);

        // if ($job->isRunning()) {
        //     Log::info("$marketplace $jobType is already running.");
        //     return;
        // }

        Log::info("$marketplace $jobType started!");
        $job->update(['status' => 1]);

        try {
            $this->initializeRequirements();
            $activeItems = (new RetailEdgeService)->getAllActiveItems();

            $webOptionBoolean7FalseSkus = [];

            foreach ($activeItems as $item) {
                try {
                    $sku = $this->processSingleItem($item, $webOptionBoolean7FalseSkus);
                } catch (\Exception $e) {
                    report($e);
                    $this->error($e->getMessage());
                }
            }

            $this->handleInactiveProducts($webOptionBoolean7FalseSkus);
            $job->update(['status' => 0, 'message' => null]);
        } catch (\Exception $e) {
            report($e);
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
        }

        Log::info("$marketplace $jobType finished!");
    }

    private function initializeRequirements(): void
    {
        $this->marketplace = Marketplace::where('name', 'Amazon')->firstOrFail();
        $this->category = Category::where([
            'name' => 'Jewelry',
            'marketplace_id' => $this->marketplace->id,
        ])->with('fields')->firstOrFail();

        Brand::all()->each(function ($brand) {
            $this->brandsArray[$brand->brand_id] = [
                'id' => $brand->id,
                'name' => $brand->name,
            ];
        });
    }

    private function processSingleItem($item, array &$webOptionBoolean7FalseSkus): ?string
    {
        $skuParts = explode('-', $item->SKU);
        if (count($skuParts) !== 3) {
            return null;
        }

        $sku = $skuParts[1].'-'.$skuParts[2];
        $this->info("Processing SKU: $sku");

        if ($item->WebOptionBoolean7 !== true) {
            $webOptionBoolean7FalseSkus[] = $sku;

            return $sku;
        }

        if (! $this->validateItem($item)) {
            return $sku;
        }

        $productType = $this->getProductType($item);

        if (! $productType || ! $productType->code) {
            Log::info("Invalid product type or code for SKU: $sku");

            return $sku;
        }

        $productData = $this->prepareProductData($item, $sku, $productType);
        $fieldValues = $this->prepareFieldValues($item, $productType);
        $productImages = $this->prepareProductImages($item);

        $this->saveProductData($productData, $fieldValues, $productImages);

        return $sku;
    }

    private function validateItem($item): bool
    {
        if (empty(trim($item->ID1))) {
            $this->info('ID1 field is empty.');

            return false;
        }

        $eWebCodes = explode(' ', $item->ID1);
        if (! isset($eWebCodes[1])) {
            $this->info('Short code for Amazon not found.');

            return false;
        }

        return true;
    }

    private function getProductType($item): ?object
    {
        $eWebCodes = explode(' ', $item->ID1);
        $eWebCode = $eWebCodes[1];

        $shortCode = EWebShortCode::where('code', $eWebCode)
            ->with('productType.fields')
            ->first();

        if (! $shortCode) {
            $msg1 = "Short code {$eWebCode} not found in EWebShortCode for {$item->SKU}";
            $this->info($msg1);
            Log::info($msg1);

            return null;
        }

        $productType = $shortCode->productType;
        $productType->code = $eWebCode;

        return $shortCode->productType;
    }

    private function prepareProductData($item, string $sku, object $productType): array
    {
        $productData = [
            'sku' => $sku,
            'marketplace_id' => $this->marketplace->id,
            'title' => $item->ShortMarketingDescription,
            'brand_id' => $this->brandsArray[$item->BrandID]['id'] ?? null,
            'category_id' => $this->category->id,
            'product_type_id' => $productType->id,
            'description' => $item->MarketingDescription,
            'manufacturer' => $this->brandsArray[$item->BrandID]['name'] ?? null,
            'department_name' => $this->getDepartmentName($productType),
            'country_of_origin' => $this->getCountryOfOrigin($item->BrandID),
            'item_type_name' => $item->ShortMarketingDescription,
            'quantity' => intval($item->TotalAvailQOH),
            'retail_price' => number_format($item->RetailPrice, 2, '.', ''),
            'retail_price2' => number_format($item->RetailPrice2, 2, '.', ''),
            'real_design_number' => $item->RealDesignNum,
            'e_web_code' => $productType->code,
        ];

        $this->addBarcodeData($productData, $item);
        $this->addSizeData($productData, $productType);
        $this->addLengthData($productData, $item);

        return $productData;
    }

    private function addBarcodeData(array &$productData, $item): void
    {
        $barcode = trim($item->Barcode);
        $length = strlen($barcode);

        if ($length == 11 || $length == 12) {
            $productData['upc'] = $barcode;
        } elseif ($length == 13) {
            $productData['ean'] = $barcode;
        }
    }

    private function getDepartmentName(object $productType): string
    {
        if (! $productType || ! $productType->code || strlen($productType->code) < 2) {
            // return 'Womens';  // or whatever default makes sense
            throw new \Exception('Invalid product type or code');
        }

        return $productType->code[1] == 'W' ? 'Womens' : 'Mens';
    }

    private function getCountryOfOrigin(string $brandId): string
    {
        if (! isset($this->brandsArray[$brandId])) {
            Log::error("Brand id: $brandId not found in brandsArray.");

            return 'AU';
        }

        return match ($this->brandsArray[$brandId]['name']) {
            'Thoms Sabo' => 'GR',
            'Ania Haie' => 'UK',
            default => 'AU'
        };
    }

    private function prepareFieldValues($item, object $productType): array
    {
        $this->addDefaultFields($item, $productType);

        $categoryFieldValues = $this->prepareCategoryFieldValues($item, $productType);
        $productTypeFieldValues = $this->prepareProductTypeFieldValues($item, $productType);

        return array_merge($productTypeFieldValues, $categoryFieldValues);
    }

    private function addDefaultFields($item, object $productType): void
    {
        $item->TargetGender = $productType->code[1] == 'W' ? 'female' : 'male';
        $item->SupplierDeclaredMaterialRegulation = 'not_applicable';
        $item->MovementType = 'Quartz';
        $item->AgeRangeDescription = 'Adult';
        $item->WarrantyType = 'Manufacturer';
        $item->TargetAudienceBase = strtolower($this->getDepartmentName($productType));
    }

    private function prepareCategoryFieldValues($item, object $productType): array
    {
        $values = [];
        foreach ($this->category->fields as $field) {
            if (property_exists($item, $field->e_web_name)) {
                $value = $item->{$field->e_web_name};
                if ($field->e_web_name == 'SMetalType' && $value == 'N/A') {
                    $value = 'No Metal';
                }

                $values[] = [
                    'category_id' => $this->category->id,
                    'product_type_id' => $productType->id,
                    'category_field_id' => $field->id,
                    'value' => $value,
                ];
            }
        }

        return $values;
    }

    private function prepareProductTypeFieldValues($item, object $productType): array
    {
        $values = [];
        foreach ($productType->fields as $field) {
            if (property_exists($item, $field->e_web_name)) {
                $value = $item->{$field->e_web_name};
                if ($field->e_web_name == 'SStoneType' && $value == 'N/A') {
                    $value = 'No Gemstone';
                }

                $values[] = [
                    'category_id' => $this->category->id,
                    'product_type_id' => $productType->id,
                    'product_type_field_id' => $field->id,
                    'value' => $value,
                ];
            }
        }

        return $values;
    }

    private function prepareProductImages($item): array
    {
        $images = [];
        if (! isset($item->Images->ItemImage) || empty($item->Images->ItemImage)) {
            return $images;
        }

        if (is_object($item->Images->ItemImage)) {
            $images[] = $this->formatImageData($item->Images->ItemImage);
        } elseif (is_array($item->Images->ItemImage)) {
            foreach ($item->Images->ItemImage as $image) {
                $images[] = $this->formatImageData($image);
            }
        }

        return $images;
    }

    private function formatImageData($image): array
    {
        return [
            'e_web_index' => $image->Index,
            'width' => $image->Width,
            'height' => $image->Height,
            'url' => htmlspecialchars_decode($image->URL),
        ];
    }

    private function saveProductData(array $productData, array $fieldValues, array $productImages): void
    {
        DB::beginTransaction();

        try {
            $product = Product::updateOrCreate(
                [
                    'sku' => $productData['sku'],
                    'marketplace_id' => $productData['marketplace_id'],
                ],
                $productData
            );

            $this->updateProductStatus($product);
            $this->saveFieldValues($product, $fieldValues);
            $this->saveProductImages($product, $productImages);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function updateProductStatus(Product $product): void
    {
        $updates = [];

        if ($product->wasChanged('quantity')) {
            $updates['inventory_feed_status'] = 0;
        }

        if ($product->wasChanged('retail_price') || $product->wasChanged('retail_price2')) {
            $updates['price_feed_status'] = 0;
            Log::debug("{$product->sku} retail_price or retail_price2 changed.");
        }

        if (! empty($updates)) {
            $product->update($updates);
        }
    }

    private function saveFieldValues(Product $product, array $fieldValues): void
    {
        foreach ($fieldValues as $value) {
            ProductFieldValue::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'category_field_id' => $value['category_field_id'] ?? null,
                    'product_type_field_id' => $value['product_type_field_id'] ?? null,
                ],
                [
                    'category_id' => $value['category_id'],
                    'product_type_id' => $value['product_type_id'],
                    'value' => $value['value'],
                ]
            );
        }
    }

    private function saveProductImages(Product $product, array $productImages): void
    {
        foreach ($productImages as $image) {
            ProductImage::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'e_web_index' => $image['e_web_index'],
                ],
                [
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'url' => $image['url'],
                ]
            );
        }
    }

    private function handleInactiveProducts(array $inactiveSkus): void
    {
        if (empty($inactiveSkus)) {
            return;
        }

        Product::whereIn('sku', $inactiveSkus)
            ->where('quantity', '>', 0)
            ->update([
                'quantity' => 0,
                'inventory_feed_status' => 0,
            ]);
    }

    private function addSizeData(array &$productData, object $productType): void
    {
        if ($productType->code === 'AWNE') {
            $productData['size_name'] = 'Standard';
        } elseif ($productType->code === 'AWEA') {
            $productData['size_name'] = 'Small';
        }
    }

    private function addLengthData(array &$productData, $item): void
    {
        if (property_exists($item, 'Length')) {
            $productData['item_length_numeric'] = str_replace('cm', '', $item->Length);
            $productData['item_length_numeric_unit'] = 'centimeters';
        }
    }

    public function handleBackup()
    {
        $marketplace = 'EWeb';
        $jobType = 'getProductsFromEWeb';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (! $job->isRunning()) {
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
                        if (! count($skuParts) === 3) {
                            continue;
                        }

                        $sku = $skuParts[1].'-'.$skuParts[2];

                        $this->info('Retail Edge SKU '.$item->SKU);
                        $this->info('Formatted SKU '.$sku);

                        if ($item->WebOptionBoolean7 !== true) {
                            $webOptionBoolean7FalseSkuArray[] = $sku;

                            continue;
                        }

                        if (trim($item->ID1) == '') {
                            $this->info('ID1 field is empty.');

                            continue;
                        }

                        $eWebCodes = explode(' ', $item->ID1);

                        if (! isset($eWebCodes[1])) {
                            $this->info('Short code for Amazon not found.');

                            continue;
                        }

                        $eWebCode = $eWebCodes[1];

                        $shortCode = EWebShortCode::where('code', $eWebCode)->with('productType.fields')->first();

                        if (! $shortCode) {
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
                        if (isset($item->Images) && isset($item->Images->ItemImage) && ! empty($item->Images->ItemImage)) {
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

                            if (! empty($newData)) {
                                $product->update($newData);
                                $product = $product->refresh();
                            }

                            if (! empty($merged)) {
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

                            if (! empty($productImages)) {
                                foreach ($productImages as $productImage) {
                                    ProductImage::updateOrCreate(
                                        [
                                            'product_id' => $product->id,
                                            'e_web_index' => $productImage['e_web_index'],
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
                        Log::error("SKU : $sku Error : ".$e->getFile().' : '.$e->getMessage().' Line : '.$e->getLine());
                    }
                }

                if (! empty($webOptionBoolean7FalseSkuArray)) {
                    foreach ($webOptionBoolean7FalseSkuArray as $sku) {
                        if ($product = Product::where('sku', $sku)->first()) {
                            if ($product->quantity > 0) {
                                $product->update([
                                    'quantity' => 0,
                                    'inventory_feed_status' => 0,
                                ]);
                            }
                        }
                    }
                }

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                Log::error('Error : '.$e->getFile().' : '.$e->getMessage().' Line : '.$e->getLine());
            }
            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
