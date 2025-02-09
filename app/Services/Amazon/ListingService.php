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

            $attributes = $this->prepareOfferAttributes($product);

            $listingsItemSubmissionResponse = $this->listingsItemsApi->putListingsItem(
                sellerId: $this->sellerId,
                sku: $product->sku,
                listingsItemPutRequest: new ListingsItemPutRequest(
                    productType: $product->amz_product_type,
                    attributes: $attributes
                ),
                marketplaceIds: [$this->marketplaceId]
            );

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
            "productType" => "PRODUCT",
            "requirements" => "LISTING_OFFER_ONLY",
            "attributes" => [
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
                "fulfillment_availability" => [
                    [
                        'fulfillment_channel_code' => 'DEFAULT',
                        'lead_time_to_ship_max_days' => 2,
                        'quantity' => (int) $product->quantity
                    ]
                ],
            ]
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
                "productType" => $product->amz_product_type ?? "PRODUCT",
                "requirements" => "LISTING",
                "attributes" => [
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
                ]
            ];

            $listingsItemSubmissionResponse = $this->listingsItemsApi->putListingsItem(
                sellerId: $this->sellerId,
                sku: $product->sku,
                listingsItemPutRequest: new ListingsItemPutRequest(
                    productType: $product->amz_product_type ?? "PRODUCT",
                    attributes: $attributes
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
