<?php

namespace App\Console\Commands\Catch;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\EWebController;
use App\Models\Brand;
use App\Models\Category;
use App\Models\EWebShortCode;
use App\Models\Marketplace;
use App\Models\Catch\CatchProduct;
use App\Models\Catch\CatchProductImage;

class GetProductsFromEWebCatch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'getProductsFromEWebCatch';

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
        $jobType = 'getProductsFromEWebCatch';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $eWeb = new EWebController;
                $resp = $eWeb->call('GetAllActiveItems');
                $activeItems = $resp->GetAllActiveItemsResult->ActiveItem;

                $marketplaceObj = Marketplace::where('name', 'Catch')->first();
                $category = Category::where(['name' => 'Jewelry', 'marketplace_id' => $marketplaceObj->id])->first();
                $brands = Brand::all();
                $brandsArray = [];

                foreach ($brands as $brand) {
                    $brandsArray[$brand->brand_id]['id'] = $brand->id;
                    $brandsArray[$brand->brand_id]['name'] = $brand->name;
                }

                $webOptionBoolean5FalseSkuArray = [];

                foreach ($activeItems as $item) {
                    try {
                        $skuParts = explode('-', $item->SKU);
                        if (!count($skuParts) === 3){
                            continue;
                        }

                        $sku = $skuParts[1]. "-" .$skuParts[2];

                        $this->info('Retail Edge SKU ' . $item->SKU);
                        $this->info('Formatted SKU ' . $sku);

                        if ($item->WebOptionBoolean5 !== true ) {
                            $webOptionBoolean5FalseSkuArray[] = $sku;
                            continue;
                        }

                        if(trim($item->ID1) == '') {
                            $this->info('ID1 field is empty.');
                            continue;
                        }

                        $eWebCodes = explode(" ", $item->ID1);

                        if(!isset($eWebCodes[0])) {
                            $this->info('Short code for Catch not found.');
                            continue;
                        }

                        $eWebCode = $eWebCodes[0];

                        $shortCode = EWebShortCode::where('code', $eWebCode)->first();

                        if(!$shortCode) {
                            $this->info('Short code '. $eWebCodes[0] .' not found in EWebShortCode.');
                            continue;
                        }

                        // Loop through the ItemsIDSs and add them in the main item object
                        foreach ($item->ISDs->ItemISD as $other) {
                            $keyName = str_replace(['.', ' ', ',', '_', '\''], [], $other->Name);
                            $otherFields[] = $keyName;
                            $item->{$keyName} = $other->Value;
                        }
                        
                        $productData = [];
                        
                        $barcode = trim($item->Barcode);
                        
                        $productData['sku'] = $sku;
                        $productData['title'] = $item->ShortMarketingDescription;
                        $productData['product_description'] = $item->MarketingDescription;
                        $productData['product_reference_value'] = $barcode;
                        $productData['brand_id'] = isset($brandsArray[$item->BrandID]['id']) ? $brandsArray[$item->BrandID]['id'] : null;
                        $productData['marketplace_id'] = $marketplaceObj->id;
                        $productData['category_id'] = $category->id;
                        $productData['e_web_code'] = $shortCode->code;

                        /**
                         * - New (or 11)
                         * Refurbished - Grade A (or 13)
                         * Refurbished - Grade B (or 14)
                         */

                        $productData['condition'] = 11;
                        $productData['keywords'] = $item->RealDesignNum;
                        $productData['gender'] = $shortCode->code[1] == 'W' ? 'Female' : 'Male';
                        $productData['model_number'] = $item->RealDesignNum;
                        $productData['contains_button_cell_batteries'] = $shortCode->button_cell;
                        $productData['metal_type'] = $item->SMetalType;
                        $productData['stone_type'] = $item->SMetalType;
                        $productData['earring_style'] = $item->SSubCat;
                        $productData['quantity'] = intval($item->TotalAvailQOH) > 0 ? intval($item->TotalAvailQOH) : 0;

                        $retailPrice = number_format($item->RetailPrice, 2);
                        $retailPrice2 = number_format($item->RetailPrice2, 2);

                        $price = max($retailPrice, $retailPrice2);
                        $discountPrice = min($retailPrice, $retailPrice2);

                        $productData['price'] = $price > 0 ? $price : null;
                        $productData['discount_price'] = $discountPrice < $price ? $discountPrice : null;
                        
                        $productData['logistic_class'] = 'FREE';
                        $productData['leadtime_to_ship'] = 2;
                        $productData['update_delete'] = 'UPDATE';
                        $productData['club_catch_eligible'] = 0;
                        $productData['tax_au'] = 10;
                        $productData['click_and_collect_eligible'] = 0;

                        $productImages = [];

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
                            $product = CatchProduct::updateOrCreate(
                                [
                                    'sku' => $sku,
                                ],
                                $productData
                            );

                            $wasRecentlyCreated = $product->wasRecentlyCreated;
                            
                            $newData = [];
                            if ($product->wasChanged('quantity') || $product->wasChanged('price') || $product->wasChanged('discount_price')) {
                                $newData['offer_csv_generated'] = 0;
                                $newData['offer_csv_submitted'] = 0;
                            }

                            if (!empty($newData)) {
                                $product->update($newData);
                                $product = $product->refresh();
                            }

                            if ($wasRecentlyCreated) {
                                $productReferenceType = strlen($barcode) == 11 || strlen($barcode) == 12 ? 'UPC' : 'EAN';
                                $product->update(['product_reference_type' => $productReferenceType]);
                                $product = $product->refresh();
                            }

                            if (!empty($productImages)) {
                                foreach ($productImages as $productImage) {
                                    CatchProductImage::updateOrCreate(
                                        [
                                            'catch_product_id' => $product->id,
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
                            report($e);
                            DB::rollBack();
                        }
                    } catch (\Exception $e) {
                        report($e);
                    }
                }

                if (!empty($webOptionBoolean5FalseSkuArray)) {
                    foreach ($webOptionBoolean5FalseSkuArray as $sku) {
                        if($product = CatchProduct::where('sku', $sku)->first()) {
                            if ($product->quantity > 0) {
                                $product->update([
                                    'quantity' => 0,
                                    'offer_csv_generated' => 0,
                                    'offer_csv_submitted' => 0,
                                ]);
                            }
                        }
                    }
                }

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                report($e);
            }
            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
