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
use App\Http\Controllers\AmzConfigController;

class AmzFeedController extends Controller
{
    public $types;
    public $processingStatus;
    public $marketplaceIds;

    public function __construct()
    {
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
            'SUBMITTED',
            'IN_QUEUE',
            'IN_PROGRESS',
            'DONE',
            'FATAL',
            'CANCELLED',
            'FINISHED',
        ];

        $this->marketplaceIds = ['A39IBJ37TRP1C6'];
    }

    public function createAmzFeed($data, $type, array $ids = null)
    {
        try {
            if (!array_key_exists($type, $this->types)) {
                $error = "Error in AmzFeedController : $type is not in types array.";
                Log::error($error);
                exit($error);
            }

            $fileName = $type . '_' . time() . '.xml';

            // $data = mb_convert_encoding($data, 'UTF-8');
            $data = utf8_encode($data);

            $dom = new \DOMDocument;
            $dom->preserveWhiteSpace = FALSE;
            $dom->loadXML($data);
            $dom->formatOutput = TRUE;

            $data = $dom->saveXML(null, LIBXML_NOEMPTYTAG);

            $storage = Storage::disk('local')->put($fileName, $data);

            if ($storage) {
                $feed = AmzFeed::create([
                    'type'      => $type,
                    'file_name' => $fileName,
                    'processing_status' => 'XML_GENERATED',
                ]);

                if ($ids) {
                    if ($type == 'POST_PRODUCT_DATA') {
                        $updateData = ['xml_generated' => 1, 'amz_feed_id' => $feed->id];
                    } elseif ($type == 'POST_PRODUCT_IMAGE_DATA') {
                        $updateData = ['image_feed_status' => 1];
                    } elseif ($type == 'POST_INVENTORY_AVAILABILITY_DATA') {
                        $updateData = ['inventory_feed_status' => 1];
                    } elseif ($type == 'POST_PRODUCT_PRICING_DATA') {
                        $updateData = ['price_feed_status' => 1];
                    }

                    $update = Product::whereIn('id', $ids)->update($updateData);
                }
            } else {
                $error = "Error in AmzFeedController : Could not store file $fileName.";
                Log::error($error);
            }
        } catch (\Exception $e) {
            Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() . ' Line : ' . $e->getLine());
        }
    }

    public function submitFeed($type)
    {
        try {
            if (!array_key_exists($type, $this->types)) {
                $error = "Error in AmzFeedController : $type is not in types array.";
                Log::error($error);
                exit($error);
            }

            $feeds = AmzFeed::where(['type' => $type, 'processing_status' => 'XML_GENERATED'])->get();

            if ($feeds->count()) {
                $feedType = $this->types[$type];

                $config = (new AmzConfigController())->getConfig();

                $feedsApiInstance = new FeedsApi($config);

                $contentType = 'text/xml; charset=UTF-8';
                $createFeedDocSpec = new CreateFeedDocumentSpecification(['content_type' => $contentType]);

                foreach ($feeds as $feed) {
                    try {
                        $exists = Storage::disk('local')->exists($feed->file_name);

                        if ($exists) {
                            $xml = Storage::disk('local')->get($feed->file_name);

                            $feedDocumentInfo = $feedsApiInstance->createFeedDocument($createFeedDocSpec);
                            $feedDocumentId = $feedDocumentInfo->getFeedDocumentId();
                            $url = $feedDocumentInfo->getUrl();

                            if ($feedDocumentId && $url) {
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

                                if ($statusCode == 200) {
                                    $specifications = [
                                        'feed_type'             => $feedType,
                                        'marketplace_ids'       => $this->marketplaceIds,
                                        'input_feed_document_id' => $feedDocumentId,
                                        'feed_options'          => null
                                    ];

                                    $body = new CreateFeedSpecification($specifications);

                                    // dd($body);
                                    $response = $feedsApiInstance->createFeed($body);

                                    $feedId = $response->getFeedId();

                                    $id = $feed->id;

                                    $feed = $feed->update(['feed_id' => $feedId, 'processing_status' => 'SUBMITTED']);

                                    if ($feed) {
                                        $feed = AmzFeed::where('id', $id)->first();
                                        $newName = $feedId . '_' . $feed->type . '.xml';
                                        $move = Storage::disk('local')->move($feed->file_name, $newName);
                                        if ($move) {
                                            $feed->update(['file_name' => $newName]);
                                        }
                                    }


                                    // Storage::disk('local')->put('result.json', $response);
                                } else {
                                    Log::error("Error in AmzFeedController - statusCode : " . $statusCode);
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('Error in AmzFeedController : ' . $e->getMessage());
                    }

                    sleep(150);
                }
            }
        } catch (\Exception $e) {
            Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() . ' Line : ' . $e->getLine());
        }
    }

    public function checkFeedStatus()
    {
        $feeds = AmzFeed::whereNotNull('feed_id')->where(function ($query) {
            $query->where('processing_status', 'SUBMITTED')
                ->orWhere('processing_status', 'IN_QUEUE')
                ->orWhere('processing_status', 'IN_PROGRESS');
        })->get();

        $productDataFeed = false;

        if ($feeds->count()) {
            $config = (new AmzConfigController())->getConfig();
            $feedsApiInstance = new FeedsApi($config);

            foreach ($feeds as $feed) {
                try {
                    $feedType = constant("SellingPartnerApi\FeedType::$feed->type");

                    $productDataFeed = $feed->type == 'POST_PRODUCT_DATA';

                    $getFeed = $feedsApiInstance->getFeed($feed->feed_id);
                    $processingStatus = $getFeed->getProcessingStatus();
                    if ($processingStatus == 'DONE') {
                        $feedResultDocumentId = $getFeed->getResultFeedDocumentId();
                        $feedResultDocument = $feedsApiInstance->getFeedDocument($feedResultDocumentId);

                        $docToDownload = new Document($feedResultDocument, $feedType);
                        $contents = $docToDownload->download();
                        // $data = $docToDownload->getData();

                        $fileName = $feed->feed_id . '_response.xml';
                        Storage::disk('local')->put($fileName, $contents);
                        $feed->update(['response_file_name' => $fileName, 'processing_status' => $processingStatus]);
                        sleep(5);
                    } else {
                        $feed->update(['processing_status' => $processingStatus]);
                    }
                } catch (\Exception $e) {
                    Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() . ' Line : ' . $e->getLine());
                    throw new \Exception($e->getMessage());
                }
            }
        }

        // if ($productDataFeed) {
        //     echo "productData\n";
        //     $this->updateMessage();
        // }
    }

    public function updateMessage()
    {
        $feeds = AmzFeed::where(['type' => 'POST_PRODUCT_DATA', 'processing_status' => 'DONE'])->whereNotNull('response_file_name')->get();
        Log::debug('Found ' . $feeds->count() . ' feeds for updating error messages from feeds.');

        $messagesArray = [];

        foreach ($feeds as $feed) {
            try {
                if (!Storage::exists($feed->response_file_name)) {
                    echo "$feed->response_file_name not found.\n";
                    $feed->update(['processing_status' => 'Response file not found!']);
                    return;
                }

                $contents = Storage::disk('local')->get($feed->response_file_name);

                if (!$contents) {
                    echo "$feed->response_file_name empty.\n";
                    $feed->update(['processing_status' => 'Response file is empty!']);
                    return;
                }

                $data = simplexml_load_string($contents);

                if (!$data) {
                    echo "$feed->response_file_name empty.\n";
                    $feed->update(['processing_status' => 'Response file is empty!']);
                    return;
                }

                echo "Processing $feed->response_file_name\n";

                $ProcessingReport = $data->Message->ProcessingReport;

                $MessagesWithError = (int) $ProcessingReport->ProcessingSummary->MessagesWithError;
                $MessagesWithWarning = (int) $ProcessingReport->ProcessingSummary->MessagesWithWarning;

                if ($MessagesWithError > 0 || $MessagesWithWarning > 0) {
                    foreach ($ProcessingReport->Result as $r) {
                        $sku = str_replace("%", "", $r->AdditionalInfo->SKU);
                        $message = html_entity_decode($r->ResultDescription, ENT_QUOTES | ENT_HTML5);
                        $messagesArray[$sku][] = $message;
                    }
                }

                $feed->update(['processing_status' => 'FINISHED']);
            } catch (\Exception $e) {
                $feed->update(['processing_status' => $e->getMessage()]);
                $this->error("Error : " . $e->getFile() . ' : ' . $e->getMessage() . ' Line : ' . $e->getLine());
                Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() . ' Line : ' . $e->getLine());
            }
        }

        if (!empty($messagesArray)) {
            Product::where('id', '>', 0)->update(['message' => null]);

            foreach ($messagesArray as $sku => $messages) {
                $i = 1;
                $m = '';
                foreach ($messages as $message) {
                    $m .= "$i: $message <br>";
                    $i++;
                }

                echo 'SKU : ' . $sku . "\n";
                echo 'Message : ' . $m . "\n";
                echo '===================================' . "\n";

                // $product = Product::where('sku', $sku)->update(['message' => implode("<br>", $messages)]);
                $product = Product::where('sku', $sku)->update(['message' => $m]);
            }
        }
    }

    public function amazonFeeds()
    {
        if (request()->ajax()) {
            $feeds = AmzFeed::query();
            return datatables()->of($feeds)
                ->addColumn('feed_xml', function ($feed) {
                    $link = route('amazon.feed.download', ['id' => $feed->id, 'type' => 'feed']);
                    return '<a href="' . $link . '">Download</a>';
                })
                ->addColumn('response_xml', function ($feed) {
                    if ($feed->response_file_name) {
                        $link = route('amazon.feed.download', ['id' => $feed->id, 'type' => 'response']);
                        return '<a href="' . $link . '">Download</a>';
                    } else {
                        return '';
                    }
                })
                ->editColumn('created_at', function ($feed) {
                    return $feed->created_at->format('Y-m-d H:i:s');
                })
                ->editColumn('updated_at', function ($feed) {
                    return $feed->updated_at->format('Y-m-d H:i:s');
                })

                ->rawColumns(['feed_xml', 'response_xml'])
                ->toJson();
        }
        return view('amazon.feeds');
    }

    public function downloadFile(Req $request)
    {
        if ($request->filled('id') && $request->filled('type')) {
            $feed = AmzFeed::where('id', $request->id)->first();

            $file = null;
            if ($request->type == 'feed') {
                $file = $feed->file_name;
            } elseif ($request->type == 'response') {
                $file = $feed->response_file_name;
            }

            if (!Storage::disk('local')->exists($file)) {
                return 'Report not found!';
            }

            if ($file) {
                return response()->download(storage_path('/app/' . $file));
            } else {
                return 'Report not found!';
            }
        } else {
            return 'Report not found!';
        }
    }
}
