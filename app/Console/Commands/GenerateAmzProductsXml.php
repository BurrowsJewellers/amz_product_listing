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
        $marketplace = 'Amazon';
        $jobType = 'generateAmzProductsXml';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $count = Product::where(['xml_generated' => 0, 'published' => 0])
                ->where(function($query){
                    $query->whereNotNull('ean');
                    $query->orWhereNotNull('upc');
                    $query->orWhereNotNull('asin');
                })
                ->count();

                $this->info($count);
                while($count){
                    $limit = 100;

                    $products = Product::with(['fields' => function($query) {
                        $query->with(['category', 'productType', 'categoryField', 'productTypeField']);
                    }, 'brand', 'category', 'productType', 'eWebCode'])->where(['xml_generated' => 0, 'published' => 0])
                    ->where(function($query){
                        $query->whereNotNull('ean');
                        $query->orWhereNotNull('upc');
                        $query->orWhereNotNull('asin');
                    })
                    ->limit($limit)->get();

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
                            $this->info('SKU '. $product->sku);

                            $standardProductIDType = null;
                            $standardProductIDValue = null;

                            if ($product->asin) {
                                $standardProductIDType = 'ASIN';
                                $standardProductIDValue = $product->asin;
                            } elseif ($product->ean) {
                                // $standardProductIDType = 'EAN';
                                // $standardProductIDValue = $product->ean;
                                $standardProductIDType = 'UPC';
                                $standardProductIDValue = '0'.$product->ean;
                            } elseif ($product->upc) {
                                $standardProductIDType = 'UPC';
                                $standardProductIDValue = $product->upc;
                            }

                            if (!$standardProductIDType) {
                                $this->error('standardProductIDType is not set.');
                                continue;
                            }

                            array_push($productIds, $product->id);
            
                            $message = $envelop->appendChild($dom->createElement('Message'));
            
                            $message->appendChild($dom->createElement('MessageID', $product->id));
                            $message->appendChild($dom->createElement('OperationType', 'Update'));

                            $elementProd = $dom->createElement('Product');
                            
                            $elementProd->appendChild($dom->createElement('SKU', $product->sku));

                            $standardProductID = $dom->createElement('StandardProductID');

                            $standardProductID->appendChild($dom->createElement('Type', $standardProductIDType));
                            $standardProductID->appendChild($dom->createElement('Value', $standardProductIDValue));

                            $elementProd->appendChild($standardProductID);

                            $elementCondition = $dom->createElement('Condition');
                            $elementCondition->appendChild($dom->createElement('ConditionType', 'New'));

                            $elementProd->appendChild($elementCondition);
                            $message->appendChild($elementProd);

                            $elementDescriptionData = $dom->createElement('DescriptionData');
                            $elementDescriptionData->appendChild($dom->createElement('Title', htmlspecialchars($product->title)));
                            $elementDescriptionData->appendChild($dom->createElement('Brand', $product->brand->name));

                            $productDescription = str_replace("Product Description:", '', $product->description);

                            $elementDescription = $dom->createElement('Description');
                            $elementDescription->appendChild($dom->createCDATASection($productDescription));
                            $elementDescriptionData->appendChild($elementDescription);

                            $dataBulletPoint1 = $productDescription;

                            if (strlen($dataBulletPoint1) > 500) {
                                $dataBulletPoint1 = substr($dataBulletPoint1, 0, 490) . '...';
                            }

                            $elementBulletPoint1 = $dom->createElement('BulletPoint');
                            $elementBulletPoint1->appendChild($dom->createCDATASection($dataBulletPoint1));
                            $elementDescriptionData->appendChild($elementBulletPoint1);

                            $elementDescriptionData->appendChild($dom->createElement('Manufacturer', $product->brand->name));
                            $elementDescriptionData->appendChild($dom->createElement('MfrPartNumber', $product->real_design_number));
                            $elementDescriptionData->appendChild($dom->createElement('IsGiftWrapAvailable', "true"));
                            $elementDescriptionData->appendChild($dom->createElement('RecommendedBrowseNode', $product->eWebCode->amz_recommended_browse_node));
                            $elementDescriptionData->appendChild($dom->createElement('MerchantShippingGroupName', $product->retail_price2 > 100 ? 'Over $100' : 'Sub $100 order'));

                            // Battery
                            $elementBattery = $dom->createElement('Battery');
                            $elementBattery->appendChild($dom->createElement('AreBatteriesIncluded', $product->eWebCode->button_cell === 1 ? "true" : "false"));
                            $elementBattery->appendChild($dom->createElement('AreBatteriesRequired', $product->eWebCode->button_cell === 1 ? "true" : "false"));

                            $elementDescriptionData->appendChild($elementBattery);

                            $elementDescriptionData->appendChild($dom->createElement('SupplierDeclaredDGHZRegulation', 'storage'));
                            $elementDescriptionData->appendChild($dom->createElement('DepartmentName', $product->department_name));
                            $elementDescriptionData->appendChild($dom->createElement('SizeName', $product->size_name));
                            $elementDescriptionData->appendChild($dom->createElement('CountryOfOrigin', $product->country_of_origin));
                            $elementDescriptionData->appendChild($dom->createElement('ItemTypeName', substr(htmlspecialchars($product->item_type_name), 0, 47) . '...'));

                            $elementProd->appendChild($elementDescriptionData);

                            $elementProductData = $dom->createElement('ProductData');

                            if ($product->category) {
                                $elementCategoryName = $dom->createElement($product->category->name);
                            }

                            if ($product->productType) {
                                $elementProductTypeName = $dom->createElement($product->productType->name);
                            }

                            foreach ($product->fields as $productField) {
                                if (!$productField->categoryField && $productField->productTypeField) {
                                    $elementProductTypeName->appendChild($dom->createElement($productField->productTypeField->amz_name, $productField->value));
                                }
                                
                                // elseif ($productField->categoryField && !$productField->productTypeField) {
                                //     $elementCategoryName->appendChild($dom->createElement($productField->categoryField->amz_name, $productField->value));
                                // }
                            }

                            $elementProductType = $dom->createElement('ProductType');
                            $elementProductType->appendChild($elementProductTypeName);

                            $elementCategoryName->appendChild($elementProductType);

                            foreach ($product->fields as $productField) {
                                // if (!$productField->categoryField && $productField->productTypeField) {
                                //     $elementProductTypeName->appendChild($dom->createElement($productField->productTypeField->amz_name, $productField->value));
                                // } else
                                
                                if ($productField->categoryField && !$productField->productTypeField) {
                                    $elementCategoryName->appendChild($dom->createElement($productField->categoryField->amz_name, $productField->value));
                                }
                            }

                            $elementProductData->appendChild($elementCategoryName);

                            $elementProd->appendChild($elementProductData);
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
                    $count = Product::where(['xml_generated' => 0, 'published' => 0])
                    ->where(function($query){
                        $query->whereNotNull('ean');
                        $query->orWhereNotNull('upc');
                        $query->orWhereNotNull('asin');
                    })
                    ->count();
                }
                $job->update(['status' => 0, 'message' => null]);
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
