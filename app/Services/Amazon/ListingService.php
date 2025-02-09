<?php

namespace App\Services\Amazon;

use App\Models\Product;
use App\Exceptions\AmazonListingException;
use SellingPartnerApi\Seller\ListingsItemsV20210801\Api;
use SellingPartnerApi\Seller\ListingsItemsV20210801\Dto\ListingsItemPutRequest;
use SellingPartnerApi\Seller\SellerConnector;
use Illuminate\Support\Facades\Log;

class ListingService
{
    private string $sellerId;
    private string $marketplaceId;
    private string $currency;
    private ?Api $listingsItemsApi = null;
    private AmazonSpApiService $amazonService;
    private SellerConnector $sellerConnector;

    public function __construct()
    {
        $this->amazonService = new AmazonSpApiService();
        $this->sellerId = $sellerId ?? config('amazon.spapi.seller_id');
        $this->marketplaceId = $marketplaceId ?? config('amazon.spapi.marketplace_id');
        $this->currency = $currency ?? config('amazon.spapi.currency');
        $this->sellerConnector = $this->amazonService->getSellerConnector();

        // $this->validateConfiguration();
    }

    /**
     * Validate essential configuration
     *
     * @throws \InvalidArgumentException
     */
    private function validateConfiguration(): void
    {
        if (empty($this->sellerId) || empty($this->marketplaceId)) {
            throw new \InvalidArgumentException('Amazon seller ID and marketplace ID are required');
        }
    }

    /**
     * Initialize the Listings API
     *
     * @throws AmazonListingException
     */
    public function initializeListingsApi(): void
    {
        try {
            $this->validateConfiguration();

            if (!$this->listingsItemsApi) {
                $this->listingsItemsApi = $this->sellerConnector->listingsItemsV20210801();
            }
        } catch (\Exception $e) {
            throw new AmazonListingException("Failed to initialize Listings API: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Submit offer only to Amazon
     *
     * @param Product $product
     * @return bool
     * @throws AmazonListingException
     */
    public function submitOfferOnly(Product $product): bool
    {
        try {
            $this->initializeListingsApi();

            Log::info("Submitting offer to Amazon", ['sku' => $product->sku]);

            echo "Submitting offer to Amazon: sku : {$product->sku}\n";

            $attributes = $this->prepareOfferAttributes($product);

            echo json_encode($attributes);
            echo "\n";

            $listingsItemPutRequest = new ListingsItemPutRequest(
                productType: $product->amz_product_type,
                attributes: $attributes,
                requirements: "LISTING_OFFER_ONLY"
            );

            $listingsItemSubmissionResponse = $this->listingsItemsApi->putListingsItem(
                sellerId: $this->sellerId,
                sku: $product->sku,
                listingsItemPutRequest: $listingsItemPutRequest,
                marketplaceIds: [$this->marketplaceId]
            );

            // var_dump($listingsItemPutRequest);
            $response = $listingsItemSubmissionResponse->dto();

            return $this->handleSubmissionResponse($product, $response);
        } catch (\Exception $e) {
            $this->handleSubmissionError($product, $e);
            throw new AmazonListingException(
                "Failed to submit offer for SKU {$product->sku}: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Prepare offer attributes
     *
     * @param Product $product
     * @return array<string, mixed>
     */
    private function prepareOfferAttributes(Product $product): array
    {
        return [
            "condition_type" => [
                [
                    "value" => "new_new",
                    "marketplace_id" => $this->marketplaceId
                ]
            ],
            "merchant_suggested_asin" => [
                [
                    "value" => $product->asin,
                    "marketplace_id" => $this->marketplaceId
                ]
            ],
            "purchasable_offer" => [
                [
                    "audience" => "ALL",
                    "currency" => "AUD",
                    "our_price" => [
                        [
                            "schedule" => [
                                [
                                    "value_with_tax" => floatval($product->retail_price),
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            "fulfillment_availability" => [
                [
                    'fulfillment_channel_code' => 'DEFAULT',
                    'lead_time_to_ship_max_days' => 2,
                    'quantity' => (int) $product->quantity
                ]
            ],
            "supplier_declared_dg_hz_regulation" => [
                [
                    "value" => "not_applicable",
                    "marketplace_id" => $this->marketplaceId
                ]
            ],
            "batteries_required" => [
                [
                    "value" => false,
                    "marketplace_id" => $this->marketplaceId
                ]
            ],
        ];
    }

    /**
     * Handle submission response
     *
     * @param Product $product
     * @param object $response
     * @return bool
     */
    private function handleSubmissionResponse(Product $product, object $response): bool
    {
        if ($response->status === 'ACCEPTED') {
            $product->update([
                'submitted' => 1,
                'amz_submission_id' => $response->submissionId
            ]);

            Log::info("Listing successfully submitted", ['sku' => $product->sku]);
            echo "Listing successfully submitted: sku : {$product->sku}\n";

            return true;
        }

        $message = $response->issues[0]->message ?? 'Unknown error';
        $product->update([
            'submitted' => 2,
            'message' => $message
        ]);

        Log::error("Listing submission failed", [
            'sku' => $product->sku,
            'status' => $response->status,
            'message' => $message
        ]);

        echo "Listing submission failed. SKU: {$product->sku}. Status: {$response->status}. Message: {$message}\n";

        return false;
    }

    /**
     * Handle submission error
     *
     * @param Product $product
     * @param \Exception $e
     * @return void
     */
    private function handleSubmissionError(Product $product, \Exception $e): void
    {
        $product->update([
            'submitted' => 2,
            'message' => $e->getMessage()
        ]);

        Log::error("Listing submission error", [
            'sku' => $product->sku,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        echo "Listing submission error. SKU: {$product->sku}. Error : {$e->getMessage()}\n";
    }

    /**
     * Submit new listing to Amazon
     * 
     * @param Product $product
     * @return bool
     * @throws AmazonListingException
     */
    public function submitNewListing(Product $product): bool
    {
        try {
            $this->initializeListingsApi();

            Log::info("Submitting new listing to Amazon", ['sku' => $product->sku]);

            $attributes = [
                "condition_type" => [
                    [
                        "value" => "new_new",
                        "marketplace_id" => $this->marketplaceId
                    ]
                ],
                "product_name" => [
                    [
                        "value" => $product->name,
                        "marketplace_id" => $this->marketplaceId
                    ]
                ],
                "brand" => [
                    [
                        "value" => $product->brand->name,
                        "marketplace_id" => $this->marketplaceId
                    ]
                ],
                "externally_assigned_product_identifier" => [
                    [
                        "type" => "ean",
                        "value" => $product->ean,
                        "marketplace_id" => $this->marketplaceId
                    ]
                ],
                "fulfillment_availability" => [
                    [
                        'fulfillment_channel_code' => 'DEFAULT',
                        'lead_time_to_ship_max_days' => 2,
                        'quantity' => (int) $product->quantity
                    ]
                ],
                "purchasable_offer" => [
                    [
                        "audience" => "ALL",
                        "currency" => "AUD",
                        "our_price" => [
                            [
                                "schedule" => [
                                    [
                                        "value_with_tax" => floatval($product->retail_price),
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            $mainImageUrl = null;
            if ($product->images->count()) {
                $mainImageUrl = $product->images[0]?->url;

                if ($mainImageUrl) {
                    $attributes['main_product_image_locator'] = [
                        [
                            "media_location" => $product->images[0]?->url,
                            "marketplace_id" => $this->marketplaceId
                        ]
                    ];
                }

                $otherImageIndex = 1;
                foreach ($product->images as $image) {
                    $attributes["other_product_image_locator_{$otherImageIndex}"] = [
                        [
                            "media_location" => $image->url,
                            "marketplace_id" => $this->marketplaceId
                        ]
                    ];

                    $otherImageIndex++;
                }
            }

            $listingsItemSubmissionResponse = $this->listingsItemsApi->putListingsItem(
                sellerId: $this->sellerId,
                sku: $product->sku,
                listingsItemPutRequest: new ListingsItemPutRequest(
                    productType: $product->productType->name,
                    attributes: $attributes,
                    requirements: 'LISTING',
                ),
                marketplaceIds: [$this->marketplaceId]
            );

            $response = $listingsItemSubmissionResponse->dto();

            return $this->handleSubmissionResponse($product, $response);
        } catch (\Exception $e) {
            $this->handleSubmissionError($product, $e);
            throw new AmazonListingException(
                "Failed to submit new listing for SKU {$product->sku}: {$e->getMessage()}",
                0,
                $e
            );
        }
    }
}
