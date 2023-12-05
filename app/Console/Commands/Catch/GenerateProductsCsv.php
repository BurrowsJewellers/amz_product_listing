<?php

namespace App\Console\Commands\Catch;

use App\Http\Controllers\SyncJobController;
use App\Models\Catch\CatchImport;
use App\Models\Catch\CatchProduct;
use App\Models\Catch\CatchProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateProductsCsv extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catchGenerateProductsCsv';

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
        $jobType = 'catchGenerateProductsCsv';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $count = CatchProduct::where(['published' => 0, 'exists_on_catch' => 0, 'product_csv_generated' => 0])->count();

                while($count){
                    $limit = 500;

                    $products = CatchProduct::with(['eWebCode', 'brand'])->where(['published' => 0, 'exists_on_catch' => 0, 'product_csv_generated' => 0])
                    ->limit($limit)->get();

                    $row = $rows = $productIds = [];

                    $rows[] = [
                        'category',
                        'internal-sku',
                        'title',
                        'product-reference-value',
                        'product-reference-type',
                        'product-description',
                        'brand',
                        'condition',
                        'product-quantity-multiplier',
                        'colour',
                        'keywords',
                        'gender',
                        'material',
                        'variant-id',
                        'variant-colour-value',
                        'variant-size-value',
                        'image-size-chart',
                        'image-1',
                        'image-2',
                        'image-3',
                        'image-4',
                        'image-5',
                        'image-6',
                        'image-7',
                        'image-8',
                        'image-9',
                        'image-10',
                        'variant-image-1',
                        'variant-image-2',
                        'variant-image-3',
                        'variant-image-4',
                        'variant-image-5',
                        'variant-image-6',
                        'variant-image-7',
                        'variant-image-8',
                        'variant-image-9',
                        'variant-image-10',
                        'weight',
                        'weight-unit',
                        'width',
                        'width-unit',
                        'length',
                        'length-unit',
                        'height',
                        'height-unit',
                        'model-number',
                        'season',
                        'adult',
                        'restriction',
                        'gift-type',
                        'accessories-material',
                        'apparel-type',
                        'contains-button-cell-batteries',
                        'clearance',
                        'clearance-stream',
                        'metal-type',
                        'stone-type',
                        'display-type',
                        'watch-case-diameter',
                        'watch-shape',
                        'water-resistance',
                        'watch-case-diameter-unit',
                        'bracelet-type',
                        'earring-style',
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

                            $imagesArray = [];
                            $imagesArray = CatchProductImage::where('catch_product_id', $product->id)->limit(10)->get()->toArray();

                            $row = [];

                            $row = [
                                $product->eWebCode->classification_path, // category
                                $product->sku, // internal_sku
                                $product->title, // title
                                $product->product_reference_value, // product_reference_value
                                strtolower($product->product_reference_type), // product_reference_type
                                $product->product_description, // product_description
                                $product->brand?->name, // brand
                                $product->condition, // condition
                                $product->product_quantity_multiplier, // product_quantity_multiplier
                                $product->colour, // colour
                                $product->keywords, // keywords
                                $product->gender, // gender
                                $product->material, // material
                                $product->variant_id, // variant_id
                                $product->variant_colour_value, // variant_colour_value
                                $product->variant_size_value, // variant_size_value
                                null, // image_size_chart
                                isset($imagesArray[0]) ? $imagesArray[0]['url'] : '', // image_1
                                isset($imagesArray[1]) ? $imagesArray[1]['url'] : '', // image_2
                                isset($imagesArray[2]) ? $imagesArray[2]['url'] : '', // image_3
                                isset($imagesArray[3]) ? $imagesArray[3]['url'] : '', // image_4
                                isset($imagesArray[4]) ? $imagesArray[4]['url'] : '', // image_5
                                isset($imagesArray[5]) ? $imagesArray[5]['url'] : '', // image_6
                                isset($imagesArray[6]) ? $imagesArray[6]['url'] : '', // image_7
                                isset($imagesArray[7]) ? $imagesArray[7]['url'] : '', // image_8
                                isset($imagesArray[8]) ? $imagesArray[8]['url'] : '', // image_9
                                isset($imagesArray[9]) ? $imagesArray[9]['url'] : '', // image_10
                                null, // variant_image_1
                                null, // variant_image_2
                                null, // variant_image_3
                                null, // variant_image_4
                                null, // variant_image_5
                                null, // variant_image_6
                                null, // variant_image_7
                                null, // variant_image_8
                                null, // variant_image_9
                                null, // variant_image_10
                                null, // weight
                                null, // weight_unit
                                null, // width
                                null, // width_unit
                                null, // length
                                null, // length_unit
                                null, // height
                                null, // height_unit
                                $product->model_number, // model_number
                                $product->season, // season
                                $product->adult, // adult
                                $product->restriction, // restriction
                                $product->gift_type, // gift_type
                                $product->accessories_material, // accessories_material
                                $product->apparel_type, // apparel_type
                                $product->contains_button_cell_batteries === 1 ? 'yes' : 'no', // contains_button_cell_batteries
                                $product->clearance === 1 ? 'yes' : 'no', // clearance
                                $product->clearance_stream, // clearance_stream
                                $product->metal_type, // metal_type
                                $product->stone_type, // stone_type
                                $product->display_type, // display_type
                                $product->watch_case_diameter, // watch_case_diameter
                                $product->watch_shape, // watch_shape
                                $product->water_resistance === 1 ? 'yes' : 'no', // water_resistance
                                strtoupper($product->watch_case_diameter_unit), // watch_case_diameter_unit
                                $product->bracelet_type, // bracelet_type
                                $product->earring_style, // earring_style
                                $product->sku, // sku
                                $product->product_reference_value, // product_id
                                $product->product_reference_type, // product_id_type
                                $product->product_description, // description
                                $product->title, // internal_description
                                $product->price, // price
                                $product->price_additional_info, // price_additional_info
                                $product->quantity, // quantity
                                $product->min_quantity_alert, // min_quantity_alert
                                11, // state (condition)
                                $product->available_start_date, // available_start_date
                                $product->available_end_date, // available_end_date
                                $product->logistic_class, // logistic_class
                                $product->discount_price < $product->price ? $product->discount_price : "", // discount_price
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
                        $filename = 'catch_products_' . time() . '.csv';

                        $stream = fopen('php://temp', 'w');

                        foreach ($rows as $row) {
                            fputcsv($stream, $row, ';');
                        }

                        rewind($stream);

                        $disk = 'local';

                        // Save the CSV file to storage
                        if (Storage::disk($disk)->put($filename, stream_get_contents($stream))) {
                            fclose($stream);
                            Log::info("CSV file saved to $disk/$filename");

                            $update = CatchProduct::whereIn('id', $productIds)->update(['product_csv_generated' => 1]);

                            $import = CatchImport::create([
                                'import_type' => 'product',
                                'file_name' => $filename,
                            ]);
                        } else {
                            fclose($stream);
                            throw new \Exception("Failed to save CSV file to $disk/$filename");
                        }
                    }

                    $count = CatchProduct::where(['published' => 0, 'exists_on_catch' => 0, 'product_csv_generated' => 0])->count();
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
