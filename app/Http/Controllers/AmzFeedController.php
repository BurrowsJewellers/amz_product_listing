<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request as Req;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SellingPartnerApi\Model\FeedsV20210630\CreateFeedDocumentSpecification;
use SellingPartnerApi\Model\FeedsV20210630\CreateFeedSpecification;
use SellingPartnerApi\Api\FeedsV20210630Api as FeedsApi;
use SellingPartnerApi\Document;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use App\Models\AmzFeed;
use App\Models\Product;
use App\Models\AmzRequestedReport;
use App\Http\Controllers\AmzConfigController;

class AmzFeedController extends Controller
{
    public $types;
    public $processingStatus;

    public function __construct(){
        $this->types = [
            'POST_PRODUCT_DATA' => 'POST_PRODUCT_DATA',
            'POST_PRODUCT_IMAGE_DATA' => 'POST_PRODUCT_IMAGE_DATA',
            'POST_INVENTORY_AVAILABILITY_DATA' => 'POST_INVENTORY_AVAILABILITY_DATA',
            'POST_PRODUCT_PRICING_DATA' => 'POST_PRODUCT_PRICING_DATA',
            // 'POST_PRODUCT_RELATIONSHIP_DATA' => 'POST_PRODUCT_RELATIONSHIP_DATA',
            // 'POST_ORDER_FULFILLMENT_DATA' => 'POST_ORDER_FULFILLMENT_DATA',
        ];

        $this->processingStatus = [
            'XML_GENERATED',
            'IN_QUEUE',
            'IN_PROGRESS',
            'DONE',
            'FATAL',
            'CANCELLED',
        ];
    }

    public function createAmzFeed($data, $type, array $ids = null){
        try {
            if(!array_key_exists($type, $this->types)){
                $error = "Error in AmzFeedController : $type is not in types array.";
                Log::error($error);
                exit($error);
            }

            $fileName = $type . '_'. time().'.xml';

            $data = mb_convert_encoding($data, 'UTF-8');

            $dom = new \DOMDocument;
            $dom->preserveWhiteSpace = FALSE;
            $dom->loadXML($data);
            $dom->formatOutput = TRUE;

            $data = $dom->saveXML(null, LIBXML_NOEMPTYTAG);

            $storage = Storage::disk('local')->put($fileName, $data);

            if($storage){
                $feed = AmzFeed::create([
                    'type'      => $type,
                    'file_name' => $fileName,
                    'status'    => 0,
                ]);

                if($ids){
                    if($type == 'POST_PRODUCT_DATA'){
                        $updateData = ['xml_generated' => 1, 'amz_feed_id' => $feed->id];
                    } elseif($type == 'POST_PRODUCT_IMAGE_DATA'){
                        $updateData = ['image_feed_status' => 1];
                    } elseif($type == 'POST_INVENTORY_AVAILABILITY_DATA'){
                        $updateData = ['inventory_feed_status' => 1];
                    } elseif($type == 'POST_PRODUCT_PRICING_DATA'){
                        $updateData = ['price_feed_status' => 1];
                    }

                    $update = Product::whereIn('id', $ids)->update($updateData);
                }
            } else {
                $error = "Error in AmzFeedController : Could not store file $fileName.";
                Log::error($error);
            }
        } catch (\Exception $e) {
            Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
        }
    }

    public function submitFeed($type){
        try {
            if(!array_key_exists($type, $this->types)){
                $error = "Error in AmzFeedController : $type is not in types array.";
                Log::error($error);
                exit($error);
            }

            $feeds = AmzFeed::where(['type' => $type, 'status' => 0])->get();

            if($feeds->count()){
                $feedType = $this->types[$type];

                $config = (new AmzConfigController())->getConfig();

                $feedsApiInstance = new FeedsApi($config);

                $contentType = 'text/xml; charset=UTF-8';
                $createFeedDocSpec = new CreateFeedDocumentSpecification(['content_type' => $contentType]);

                foreach($feeds as $feed){
                    $exists = Storage::disk('local')->exists($feed->file_name);

                    if($exists){
                        $xml = Storage::disk('local')->get($feed->file_name);

                        $feedDocumentInfo = $feedsApiInstance->createFeedDocument($createFeedDocSpec);
                        $feedDocumentId = $feedDocumentInfo->getFeedDocumentId();
                        $url = $feedDocumentInfo->getUrl();
    
                        if($feedDocumentId && $url){
                            // $client = new Client(['exceptions' => false]);
                            $client = new Client();
        
                            $request = new Request(
                                'PUT',
                                $url,
                                array('Content-Type' => $contentType),
                                $xml
                            );
        
                            $response = $client->send($request);
                            $statusCode = $response->getStatusCode();
        
                            if($statusCode == 200){
                                $specifications = [
                                    'feed_type'             => $feedType,
                                    'marketplace_ids'       => ['ATVPDKIKX0DER'],
                                    'input_feed_document_id'=> $feedDocumentId,
                                    'feed_options'          => null
                                ];

                                $body = new CreateFeedSpecification($specifications);

                                $response = $feedsApiInstance->createFeed($body);

                                $feedId = $response->getFeedId();

                                $id = $feed->id;

                                $feed = $feed->update(['feed_id' => $feedId, 'status' => 1]);

                                if($feed){
                                    $feed = AmzFeed::where('id', $id)->first();
                                    $newName = $feedId.'_'.$feed->type.'.xml';
                                    $move = Storage::disk('local')->move($feed->file_name, $newName);
                                    if($move){
                                        $feed->update(['file_name' => $newName]);
                                    }
                                }

                                sleep(150);
                            
                                // Storage::disk('local')->put('result.json', $response);
                            } else {
                                Log::error('Error in AmzFeedController : ' . $response->getBody()->getContents());
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
        }
    }

    public function checkFeedStatus(){
        $feeds = AmzFeed::whereNotNull('feed_id')->where(['status' => 1])->get();

        if($feeds->count()){
            $config = (new AmzConfigController())->getConfig();
            $feedsApiInstance = new FeedsApi($config);

            foreach($feeds as $feed){
                $feedType = constant("SellingPartnerApi\FeedType::$feed->type");

                $getFeed = $feedsApiInstance->getFeed($feed->feed_id);
                $processingStatus = $getFeed->getProcessingStatus();
                if($processingStatus == 'DONE'){
                    $feedResultDocumentId = $getFeed->getResultFeedDocumentId();
                    $feedResultDocument = $feedsApiInstance->getFeedDocument($feedResultDocumentId);
                    
                    $docToDownload = new Document($feedResultDocument, $feedType);
                    $contents = $docToDownload->download();  // The raw report data
                    // $data = $docToDownload->getData();
                    // dd($data);
        
                    $fileName = $feed->feed_id .'_response.xml';
                    Storage::disk('local')->put($fileName, $contents);
                    $feed->update(['status' => 2, 'response_file_name' => $fileName, 'processing_status' => $processingStatus]);
                    sleep(5);
                } else{
                    $feed->update(['processing_status' => $processingStatus]);
                }
            }
        }

        $this->updateMessage();
    }

    public function updateMessage(){
        $feed = AmzFeed::where(['type' => 'POST_PRODUCT_DATA', 'status' => 2])->orderBy('id', 'desc')->first();

        if($feed){
            if(Storage::exists($feed->response_file_name)) {
                $contents = Storage::disk('local')->get($feed->response_file_name);
                $data = simplexml_load_string($contents);

                if($data){
                    try {
                        $feed->update(['status' => 3]);
    
                        $feed = $feed->refresh();
    
                        $ProcessingReport = $data->Message->ProcessingReport;
            
                        $MessagesWithError      = (int) $ProcessingReport->ProcessingSummary->MessagesWithError;
                        $MessagesWithWarning    = (int) $ProcessingReport->ProcessingSummary->MessagesWithWarning;
            
                        if($MessagesWithError > 0 || $MessagesWithWarning > 0){
                            foreach($ProcessingReport->Result as $r){
                                $sku        = $r->AdditionalInfo->SKU;
                                $message    = html_entity_decode($r->ResultDescription, ENT_QUOTES | ENT_HTML5);
                                
                                $product = Product::where('sku', $sku)->first();
            
                                if($product){
                                    $update = $product->update([
                                        // 'message'   => "$product->message\n\n $message",
                                        'message'   => "$message",
                                    ]);
                                }
                            }
                        }
    
                        $feed->update(['status' => 4]);
                    } catch (\Exception $e){
                        Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
                    }
                }
            }
        }
    }

    public function amazonFeeds(){
        return view('feeds.amazon.amazonFeeds');
    }

    public function amazonFeedsData(Req $request){
        $feeds = AmzFeed::query();
        return datatables()->of($feeds)
                    ->addColumn('feed_xml', function($feed){
                        $link = route('amazon_feed.amazon.feeds.download', ['id' => $feed->id, 'type' => 'feed']);
                        return '<a href="'.$link.'">Download</a>';
                    })
                    ->addColumn('response_xml', function($feed){
                        if($feed->response_file_name){
                            $link = route('amazon_feed.amazon.feeds.download', ['id' => $feed->id, 'type' => 'response']);
                            return '<a href="'.$link.'">Download</a>';
                        } else {
                            return '';
                        }
                    })
                    ->editColumn('created_at', function($feed){
                        return $feed->created_at->format('Y-m-d');
                    })

                    ->rawColumns(['feed_xml', 'response_xml'])
                    ->toJson();
    }


    public function downloadFile(Req $request){
        if($request->filled('id') && $request->filled('type')){
            $feed = AmzFeed::where('id', $request->id)->first();

            $file = null;
            if($request->type == 'feed'){
                $file = $feed->file_name;
            } elseif($request->type == 'response'){
                $file = $feed->response_file_name;
            }

            if(!Storage::disk('local')->exists($file)){
                return 'Report not found!';
            }

            if($file){
                return response()->download(storage_path('/app/'. $file));
            } else {
                return 'Report not found!';
            }
        } else {
            return 'Report not found!';
        }
    }
}