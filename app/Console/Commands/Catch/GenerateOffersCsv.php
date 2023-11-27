<?php

namespace App\Console\Commands\Catch;

use App\Http\Controllers\SyncJobController;
use App\Models\Catch\CatchImport;
use App\Models\Catch\CatchProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateOffersCsv extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catchGenerateOffersCsv';

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
        $marketplace = 'Catch';
        $jobType = 'catchGenerateOffersCsv';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $count = CatchProduct::where(['exists_on_catch' => 1, 'offer_csv_generated' => 0])->count();

                while($count){
                    $limit = 500;

                    $products = CatchProduct::with(['eWebCode', 'brand'])->where(['exists_on_catch' => 1, 'offer_csv_generated' => 0])->limit($limit)->get();

                    $row = $rows = $productIds = [];

                    $rows[] = [
                        'sku',
                        'product-id',
                        'product-id-type',
                        'description',
                        'internal-description',
                        'price',
                        'price-additional-info',
                        'quantity',
                        'min-quantity-alert',
                        'state',
                        'available-start-date',
                        'available-end-date',
                        'logistic-class',
                        'discount-price',
                        'discount-start-date',
                        'discount-end-date',
                        'leadtime-to-ship',
                        'update-delete',
                        'quantity-multiplier',
                        'purchase-limit',
                        'club-catch-eligible',
                        'tax-au',
                        'click-and-collect-eligible',
                    ];

                    foreach($products as $product){
                        try {
                            $this->info($product->id);
                            array_push($productIds, $product->id);

                            $row = [];

                            $discountPrice = '';

                            if ($product->discount_price > 0) {
                                $discountPrice = $product->discount_price;
                            }

                            $row = [
                                $product->sku, // sku
                                $product->product_reference_value, // product_id
                                $product->product_reference_type, // product_id_type
                                "", // description
                                $product->title, // internal_description
                                $product->price, // price
                                $product->price_additional_info, // price_additional_info
                                $product->quantity, // quantity
                                $product->min_quantity_alert, // min_quantity_alert
                                11, // state (condition)
                                $product->available_start_date, // available_start_date
                                $product->available_end_date, // available_end_date
                                $product->logistic_class, // logistic_class
                                $discountPrice,
                                $product->discount_start_date, // discount_start_date
                                $product->discount_end_date, // discount_end_date
                                $product->leadtime_to_ship, // leadtime_to_ship
                                $product->update_delete, // update_delete
                                $product->quantity_multiplier, // quantity_multiplier
                                $product->purchase_limit, // purchase_limit
                                $product->club_catch_eligible ? 'true' : 'false', // club_catch_eligible
                                $product->tax_au, // tax_au
                                $product->click_and_collect_eligible ? 'true' : 'false', // click_and_collect_eligible
                            ];

                            $rows[] = $row;
                        } catch (\Exception $e) {
                            report($e);
                        }
                    }

                    if (!empty($productIds)) {
                        $filename = 'catch_offers_' . time() . '.csv';

                        $stream = fopen('php://temp', 'w');

                        foreach ($rows as $row) {
                            fputcsv($stream, $row, ";");
                        }

                        rewind($stream);

                        $disk = 'local';

                        // Save the CSV file to storage
                        if (Storage::disk($disk)->put($filename, stream_get_contents($stream))) {
                            fclose($stream);
                            Log::info("CSV file saved to $disk/$filename");

                            $update = CatchProduct::whereIn('id', $productIds)->update(['published' => 1, 'offer_csv_generated' => 1]);

                            $import = CatchImport::create([
                                'import_type' => 'offer',
                                'file_name' => $filename,
                            ]);
                        } else {
                            fclose($stream);
                            throw new \Exception("Failed to save CSV file to $disk/$filename");
                        }
                    }

                    $count = CatchProduct::where(['exists_on_catch' => 1, 'offer_csv_generated' => 0])->count();
                }

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e) {
                report($e);
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
            }

            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }


    public function getHeaders(){
        return [
            'category',
            'internal_sku',
            'title',
            'product_reference_value',
            'product_reference_type',
            'product_description',
            'brand',
            'condition',
            'product_quantity_multiplier',
            'colour',
            'keywords',
            'gender',
            'material',
            'variant_id',
            'variant_colour_value',
            'variant_size_value',
            'image_size_chart',
            'image_1',
            'image_2',
            'image_3',
            'image_4',
            'image_5',
            'image_6',
            'image_7',
            'image_8',
            'image_9',
            'image_10',
            'variant_image_1',
            'variant_image_2',
            'variant_image_3',
            'variant_image_4',
            'variant_image_5',
            'variant_image_6',
            'variant_image_7',
            'variant_image_8',
            'variant_image_9',
            'variant_image_10',
            'weight',
            'weight_unit',
            'width',
            'width_unit',
            'length',
            'length_unit',
            'height',
            'height_unit',
            'model_number',
            'season',
            'adult',
            'restriction',
            'gift_type',
            'accessories_material',
            'apparel_type',
            'contains_button_cell_batteries',
            'clearance',
            'clearance_stream',
            'metal_type',
            'stone_type',
            'display_type',
            'watch_case_diameter',
            'watch_shape',
            'water_resistance',
            'watch_case_diameter_unit',
            'bracelet_type',
            'earring_style',
            'sku',
            'product_id',
            'product_id_type',
            'description',
            'internal_description',
            'price',
            'price_additional_info',
            'quantity',
            'min_quantity_alert',
            'state',
            'available_start_date',
            'available_end_date',
            'logistic_class',
            'discount_price',
            'discount_start_date',
            'discount_end_date',
            'leadtime_to_ship',
            'update_delete',
            'quantity_multiplier',
            'purchase_limit',
            'club_catch_eligible',
            'tax_au',
            'click_and_collect_eligible',
        ];
    }
}
