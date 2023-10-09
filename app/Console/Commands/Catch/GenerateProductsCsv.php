<?php

namespace App\Console\Commands\Catch;

use App\Http\Controllers\SyncJobController;
use App\Models\Catch\CatchProduct;
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
                $count = CatchProduct::where(['published' => 0, 'submitted' => 0, 'exists_on_catch' => 0, 'csv_generated' => 0])->count();

                while($count){
                    $limit = 500;

                    $products = CatchProduct::with(['eWebCode', 'brand'])->where(['published' => 0, 'submitted' => 0, 'exists_on_catch' => 0, 'csv_generated' => 0])->limit($limit)->get();

                    $row = $rows = $productIds = [];

                    $rows[] = [
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

                    foreach($products as $product){
                        try {
                            $this->info($product->id);
                            array_push($productIds, $product->id);

                            $row = [];

                            $row = [
                                $product->eWebCode->classification_path, // category
                                $product->sku, // internal_sku
                                $product->title, // title
                                $product->product_reference_value, // product_reference_value
                                $product->product_reference_type, // product_reference_type
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
                                'image_1', // image_1
                                'image_2', // image_2
                                'image_3', // image_3
                                'image_4', // image_4
                                'image_5', // image_5
                                'image_6', // image_6
                                'image_7', // image_7
                                'image_8', // image_8
                                'image_9', // image_9
                                'image_10', // image_10
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
                                $product->contains_button_cell_batteries, // contains_button_cell_batteries
                                $product->clearance, // clearance
                                $product->clearance_stream, // clearance_stream
                                $product->metal_type, // metal_type
                                $product->stone_type, // stone_type
                                $product->display_type, // display_type
                                $product->watch_case_diameter, // watch_case_diameter
                                $product->watch_shape, // watch_shape
                                $product->water_resistance, // water_resistance
                                $product->watch_case_diameter_unit, // watch_case_diameter_unit
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
                                $product->state, // state
                                $product->available_start_date, // available_start_date
                                $product->available_end_date, // available_end_date
                                $product->logistic_class, // logistic_class
                                $product->discount_price, // discount_price
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

                    if(!empty($productIds)){
                        $feedController = new AmzFeedController();
                        $feedController->createAmzFeed($xml, 'POST_PRODUCT_IMAGE_DATA', $productIds);
                    }


                    Storage::disk('local')->put($fileName, $contents);



                    $count = CatchProduct::where(['published' => 0, 'submitted' => 0, 'exists_on_catch' => 0, 'csv_generated' => 0])->count();
                }

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e){
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
