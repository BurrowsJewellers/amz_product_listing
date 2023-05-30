<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\AmzFeedController;
use App\Http\Controllers\SyncJobController;
use App\Models\Product;

class GenerateAmzProductsXml extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generateAmzProductsXml';

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
        // $product = Product::with(['fields', 'brand', 'category', 'productType', 'categoryFields', 'productTypeFields'])->where(['xml_generated' => 0, 'published' => 0])->find(1);
        // dd($product);


        $marketplace = 'Amazon';
        $jobType = 'generateAmzProductsXml';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            // $job->update(['status' => 1]);

            try {
                $count = Product::where(['xml_generated' => 0, 'published' => 0])->count();

                $this->info($count);
                while($count){
                    $limit = 1;

                    $products = Product::with(['fields', 'brand', 'category', 'productType', 'categoryFields', 'productTypeFields'])->where(['xml_generated' => 0, 'published' => 0])->limit($limit)->get();

                    if($products->count()) {
                        $merchantId = config('amazon.merchand_id');
            
                        $dom = new \DOMDocument('1.0', 'utf-8');
            
                        $envelop = $dom->createElement("AmazonEnvelope");
                        $envelop->setAttribute('xsi:noNamespaceSchemaLocation', 'amzn-envelope.xsd');
            
                        $header = $dom->createElement('Header');
                        $header->appendChild($dom->createElement('DocumentVersion', 1.01));
                        $header->appendChild($dom->createElement('MerchantIdentifier', $merchantId));
                        
                        $envelop->appendChild($header);
            
                        $envelop->appendChild($dom->createElement('MessageType', 'Product'));
                        $envelop->appendChild($dom->createElement('PurgeAndReplace', 'false'));
            
                        $productIds = [];
                        foreach($products as $product){
                            array_push($productIds, $product->id);
            
                            $message = $envelop->appendChild($dom->createElement('Message'));
            
                            $message->appendChild($dom->createElement('MessageID', $product->id));
                            $message->appendChild($dom->createElement('OperationType', 'Update'));

                            $elementProd = $dom->createElement('Product');
                            
                            $elementProd->appendChild($dom->createElement('SKU', $product->sku));

                            $standardProductIDType = null;
                            $standardProductIDValue = null;

                            if ($product->asin) {
                                $standardProductIDType = 'ASIN';
                                $standardProductIDValue = $product->asin;
                            } elseif ($product->ean) {
                                $standardProductIDType = 'EAN';
                                $standardProductIDValue = $product->ean;
                            } elseif ($product->upc) {
                                $standardProductIDType = 'UPC';
                                $standardProductIDValue = $product->upc;
                            }

                            $standardProductID = $dom->createElement('StandardProductID');

                            $standardProductID->appendChild($dom->createElement('Type', $standardProductIDType));
                            $standardProductID->appendChild($dom->createElement('Value', $standardProductIDValue));

                            $elementProd->appendChild($standardProductID);


                            $elementCondition = $dom->createElement('Condition');
                            $elementCondition->appendChild($dom->createElement('ConditionType', 'New'));

                            $elementProd->appendChild($elementCondition);
                            $message->appendChild($elementProd);

                            $elementDescriptionData = $dom->createElement('DescriptionData');
                            $elementDescriptionData->appendChild($dom->createElement('Title', $product->title));
                            $elementDescriptionData->appendChild($dom->createElement('Brand', $product->brand->name));
                            $elementDescriptionData->appendChild($dom->createElement('Description', '<![CDATA['.$product->description.']]>'));
                            $elementDescriptionData->appendChild($dom->createElement('BulletPoint', '<![CDATA['. $product->title .']]>'));

                            $elementDescriptionData->appendChild($dom->createElement('Manufacturer', $product->brand->name));
                            $elementDescriptionData->appendChild($dom->createElement('RecommendedBrowseNode', '5131129051'));
                            $elementDescriptionData->appendChild($dom->createElement('DepartmentName', $product->department_name));
                            $elementDescriptionData->appendChild($dom->createElement('SizeName', $product->size_name));
                            $elementDescriptionData->appendChild($dom->createElement('CountryOfOrigin', $product->country_of_origin));
                            $elementDescriptionData->appendChild($dom->createElement('ItemTypeName', $product->item_type_name));

                            $elementProd->appendChild($elementDescriptionData);

                            $elementProductData = $dom->createElement('ProductData');

                            if ($product->category) {
                                $elementCategoryName = $dom->createElement($product->category->name);
                            }

                            if ($product->productType) {
                                $elementProductTypeName = $dom->createElement($product->productType->name);
                            }



                        }
            
                        $xmlRoot = $dom->appendChild($envelop);
                        $xmlRoot->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', "http://www.w3.org/2001/XMLSchema-instance");
            
                        $dom->formatOutput = true;
                        $xml = $dom->saveXML();
            
                        if(!empty($productIds)){
                            $feedController = new AmzFeedController();
                            $feedController->createAmzFeed($xml, 'POST_PRODUCT_DATA', $productIds);
                        }
                    }
                    $count = Product::where(['xml_generated' => 0, 'published' => 0])->count();
                }

            } catch (\Exception $e){
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
            }

            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
