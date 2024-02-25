<?php

namespace App\Console\Commands\EWeb;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Models\RetailEdgeProduct;
use App\Models\RetailEdgeProductImage;
use App\Services\RetailEdgeService;

class GetProductsFromEWeb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'getProductsFromEWebMain';

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
        $jobType = 'getProductsFromEWebMain';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $activeItems = (new RetailEdgeService)->getAllActiveItems();

                RetailEdgeProduct::truncate();
                RetailEdgeProductImage::truncate();
                foreach ($activeItems as $item) {
                    try {
                        if (!preg_match('/^\d{3}-\d{3}-\d{5}$/', $item->SKU)) {
                            continue;
                        }

                        $skuArray = array_map('trim', explode('-', $item->SKU));
                        $sku = $skuArray[1] . "-" . $skuArray[2];

                        $item->OldKey = trim($item->OldKey);
                        $item->ID3 = trim($item->ID3);

                        // Loop through the ItemsIDSs and add them in the main item object
                        foreach ($item->ISDs->ItemISD as $other) {
                            $keyName = str_replace(['.', ' ', ',', '_', '\''], [], $other->Name);
                            $item->{$keyName} = trim($other->Value);
                        }

                        RetailEdgeProduct::create(
                            [
                                'sku' => $sku,
                                'title' => trim($item->ShortMarketingDescription),
                                'marketing_description' => $item->MarketingDescription,
                                'brand_id' => trim($item->BrandID),
                                'retail_price1' => $item->RetailPrice,
                                'retail_price2' => $item->RetailPrice2,
                                'quantity' => intval($item->TotalAvailQOH),
                                'id1' => trim($item->ID1),
                                'id2' => trim($item->ID2),
                                'id3' => trim($item->ID3),
                                'id4' => trim($item->ID4),
                                'old_key' => trim($item->OldKey),
                                'is_valid_child' => preg_match('/^\d{3}-\d{5}$/', $item->OldKey) ? true : false,
                                'real_design_number' => trim($item->RealDesignNum),
                                'pendant_style' => isset($item->PendantStyle) ? $item->PendantStyle : null,
                                's_web_menu' => isset($item->SWebMenu) ? $item->SWebMenu : null,
                                's_metal_type' => isset($item->SMetalType) ? $item->SMetalType : null,
                                's_stone_type' => isset($item->SStoneType) ? $item->SStoneType : null,
                                's_cat' => isset($item->SCat) ? $item->SCat : null,
                                's_sub_cat' => isset($item->SSubCat) ? $item->SSubCat : null,
                                'web_option_boolean1' => $item->WebOptionBoolean1,
                                'web_option_boolean2' => $item->WebOptionBoolean2,
                                'web_option_boolean3' => $item->WebOptionBoolean3,
                                'web_option_boolean4' => $item->WebOptionBoolean4,
                                'web_option_boolean5' => $item->WebOptionBoolean5,
                                'web_option_boolean6' => $item->WebOptionBoolean6,
                                'web_option_boolean7' => $item->WebOptionBoolean6,
                                'web_option_boolean8' => $item->WebOptionBoolean8,
                            ]
                        );


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

                        if (!empty($productImages)) {
                            foreach ($productImages as $productImage) {
                                RetailEdgeProductImage::create(
                                    [
                                        'sku' => $sku,
                                        'e_web_index' => $productImage['e_web_index'],
                                        'width' => $productImage['width'],
                                        'height' => $productImage['height'],
                                        'url' => $productImage['url'],
                                    ]
                                );
                            }
                        }
                    } catch (\Exception $e) {
                        report($e);
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
