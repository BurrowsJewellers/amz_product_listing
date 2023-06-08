<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\AmzFeedController;
use App\Http\Controllers\SyncJobController;
use App\Models\AmzFeed;
use App\Models\Product;

class GenerateAmzPriceXml extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generateAmzPriceXml';

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
        $jobType = 'generateAmzPriceXml';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                // delete the pending feeds
                $feeds = AmzFeed::where(['type' => 'POST_PRODUCT_PRICING_DATA', 'status' => 0])->get();

                foreach($feeds as $feed){
                    $delete = Storage::disk('local')->delete($feed->file_name);
                    $feed->update(['processing_status' => 'Feed deleted']);
                    $feed->delete();
                }

                $count = Product::where(['price_feed_status' => 0, 'published' => 1])->count();

                while($count){
                    $limit = 100;

                    $products = Product::where(['price_feed_status' => 0, 'published' => 1])->limit($limit)->get();

                    if($products->count()) {
                        $merchantId = config('spapi.merchand_id');

                        $dom = new \DOMDocument('1.0', 'utf-8');

                        $envelop = $dom->createElement("AmazonEnvelope");
                        $envelop->setAttribute('xsi:noNamespaceSchemaLocation', 'amzn-envelope.xsd');

                        $header = $dom->createElement('Header');
                        $header->appendChild($dom->createElement('DocumentVersion', 1.01));
                        $header->appendChild($dom->createElement('MerchantIdentifier', $merchantId));

                        $envelop->appendChild($header);

                        $envelop->appendChild($dom->createElement('MessageType', 'Price'));

                        $productIds = [];
                        foreach($products as $product){
                            array_push($productIds, $product->id);

                            $message = $envelop->appendChild($dom->createElement('Message'));

                            $message->appendChild($dom->createElement('MessageID', $product->id));
                            // $message->appendChild($dom->createElement('OperationType', 'Update'));
                            $price = $message->appendChild($dom->createElement('Price'));

                            $price->appendChild($dom->createElement('SKU', $product->sku));

                            $standardPrice = $dom->createElement('StandardPrice', $product->standard_price);
                            $standardPrice->setAttribute('currency', 'AUD');
                            $price->appendChild($standardPrice);
                        }

                        $xmlRoot = $dom->appendChild($envelop);
                        $xmlRoot->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', "http://www.w3.org/2001/XMLSchema-instance");

                        $dom->formatOutput = true;
                        $xml = $dom->saveXML();

                        if(!empty($productIds)){
                            $feedController = new AmzFeedController();
                            $feedController->createAmzFeed($xml, 'POST_PRODUCT_PRICING_DATA', $productIds);
                        }
                    }

                    $count = Product::where(['price_feed_status' => 0, 'published' => 1])->count();
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
