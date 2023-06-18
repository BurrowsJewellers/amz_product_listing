<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\AmzFeedController;
use App\Http\Controllers\SyncJobController;
use App\Models\AmzFeed;
use App\Models\Product;

class GenerateAmzImagesXml extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generateAmzImagesXml';

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
        $jobType = 'generateAmzImagesXml';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                // delete the pending feeds
                // $feeds = AmzFeed::where(['type' => 'POST_PRODUCT_IMAGE_DATA', 'processing_status' => 0])->get();

                // foreach($feeds as $feed){
                //     $delete = Storage::disk('local')->delete($feed->file_name);
                //     $feed->update(['processing_status' => 'Feed deleted']);
                //     $feed->delete();
                // }

                $count = Product::where(['image_feed_status' => 0, 'published' => 1])->count();

                while($count){
                    $limit = 100;

                    $products = Product::with('images')->where(['image_feed_status' => 0, 'published' => 1])->limit($limit)->get();

                    if($products->count()) {
                        $merchantId = config('spapi.merchand_id');
        
                        $dom = new \DOMDocument('1.0', 'utf-8');
        
                        $envelop = $dom->createElement("AmazonEnvelope");
                        $envelop->setAttribute('xsi:noNamespaceSchemaLocation', 'amzn-envelope.xsd');
        
                        $header = $dom->createElement('Header');
                        $header->appendChild($dom->createElement('DocumentVersion', 1.01));
                        $header->appendChild($dom->createElement('MerchantIdentifier', $merchantId));
                        
                        $envelop->appendChild($header);
        
                        $envelop->appendChild($dom->createElement('MessageType', 'ProductImage'));
        
                        $productIds = [];
                        foreach($products as $product){
                            array_push($productIds, $product->id);
                            
                            if(isset($product->images) && $product->images->count()) {
                                $i = 1;
                                foreach($product->images as $image){
                                    $mainImageUrl = null;
                                    $message = $envelop->appendChild($dom->createElement('Message'));
        
                                    $message->appendChild($dom->createElement('MessageID', $product->id));
                                    $message->appendChild($dom->createElement('OperationType', 'Update'));
                                    $productImage = $message->appendChild($dom->createElement('ProductImage'));
        
                                    if($image->e_web_index == 1){
                                        $imageType = 'Main';
                                        $mainImageUrl = $image->url;
                                    } else{
                                        $imageType = 'PT'.$i;
                                    }
                                    $productImage->appendChild($dom->createElement('SKU', $product->sku));
                                    $productImage->appendChild($dom->createElement('ImageType', $imageType));
        
                                    // $elementImageLocation = $dom->createElement('ImageLocation');
                                    $productImage->ImageLocation = $image->url;
                                    $productImage->appendChild($dom->createElement('ImageLocation', htmlspecialchars($image->url)));
                                    $i++;
                                }
        
                                /**
                                 * Swatch image
                                 */
                                if($mainImageUrl){
                                    $message = $envelop->appendChild($dom->createElement('Message'));
            
                                    $message->appendChild($dom->createElement('MessageID', random_int(888888, 999999)));
                                    $message->appendChild($dom->createElement('OperationType', 'Update'));
                                    $productImage = $message->appendChild($dom->createElement('ProductImage'));
            
                                    $productImage->appendChild($dom->createElement('SKU', $product->sku));
                                    $productImage->appendChild($dom->createElement('ImageType', 'Swatch'));
            
                                    // $productImage->appendChild($dom->createElement('ImageLocation', $mainImageUrl));
                                    $productImage->ImageLocation = $mainImageUrl;
                                }
                            }
                        }

                        $xmlRoot = $dom->appendChild($envelop);
                        $xmlRoot->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', "http://www.w3.org/2001/XMLSchema-instance");

                        $dom->formatOutput = true;
                        $xml = $dom->saveXML();

                        if(!empty($productIds)){
                            $feedController = new AmzFeedController();
                            $feedController->createAmzFeed($xml, 'POST_PRODUCT_IMAGE_DATA', $productIds);
                        }
                    }

                    $count = Product::where(['image_feed_status' => 0, 'published' => 1])->count();
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
