<?php

namespace App\Services\Amazon;

use Exception;
use Illuminate\Support\Facades\Log;
use SellingPartnerApi\SellingPartnerApi;
use SellingPartnerApi\Seller\SellerConnector;
use SellingPartnerApi\Seller\ListingsItemsV20210801\Dto\ListingsItemPatchRequest;
use SellingPartnerApi\Seller\ListingsItemsV20210801\Dto\PatchOperation;
use App\Models\Product;
use SellingPartnerApi\Seller\ListingsItemsV20210801\Api;

// Removed duplicate log import
class AmazonSpApiService
{
    private const MAX_RETRIES = 3;
    private ?string $sellerId;
    private ?string $marketplaceId;
    private ?string $currency;
    private ?Api $listingsItemsApi = null;

    public function __construct()
    {
        $this->sellerId = config('amazon.spapi.seller_id');
        $this->marketplaceId = config('amazon.spapi.marketplace_id');
        $this->currency = config('amazon.spapi.currency');

        // $this->validateConfig();
    }

    public function getSellerConnector($region = 'FE', $debug = false): SellerConnector
    {
        return SellingPartnerApi::seller(
            clientId: config('amazon.spapi.client_id'),
            clientSecret: config('amazon.spapi.client_secret'),
            refreshToken: config('amazon.spapi.refresh_token'),
            endpoint: constant("SellingPartnerApi\Enums\Endpoint::$region"),
        );
    }

    /**
     * Initialize the Listings API
     */
    public function initializeListingsApi(): void
    {
        $sellerConnector = $this->getSellerConnector();
        $this->listingsItemsApi = $sellerConnector->listingsItemsV20210801();
    }

    /**
     * Update a product's inventory and price on Amazon
     */
    public function updateProduct(Product $product): bool
    {
        if ($this->listingsItemsApi === null) {
            $this->initializeListingsApi();
        }

        // Log the current product being processed
        $message = "Processing SKU: {$product->sku} - Price: {$product->retail_price} - Inventory: {$product->quantity}";
        Log::info($message);
        echo PHP_EOL . $message . PHP_EOL;

        $retryCount = 0;

        while ($retryCount < self::MAX_RETRIES) {
            try {
                $patches = $this->buildProductPatches($product);
                $submitMessage = "Submitting update to Amazon for SKU: {$product->sku}";
                Log::info($submitMessage);
                echo $submitMessage . PHP_EOL;
                $response = $this->submitProductUpdate($product->sku, $patches);

                $this->handleUpdateResponse($product, $response->dto());
                return true;
            } catch (Exception $e) {
                $retryCount++;

                // Check for 429 Too Many Requests error
                $message = $e->getMessage();
                $sleepTime = 1; // Default delay

                if (strpos($message, 'Too Many Requests (429)') !== false) {
                    // Exponential backoff for rate limit errors
                    $sleepTime = pow(2, $retryCount);
                    $rateLimitMessage = "Rate limit exceeded for product {$product->sku}. Waiting {$sleepTime} seconds before retry {$retryCount}.";
                    Log::warning($rateLimitMessage);
                    echo $rateLimitMessage . PHP_EOL;
                } else {
                    $retryMessage = "Retry {$retryCount} for product {$product->sku}: {$message}";
                    Log::warning($retryMessage);
                    echo $retryMessage . PHP_EOL;
                }

                if ($retryCount === self::MAX_RETRIES) {
                    $this->handleProductError($product, $e);
                    return false;
                }

                sleep($sleepTime); // Dynamic delay between retries
            }
        }

        return false;
    }

    /**
     * Build product patches for Amazon API
     */
    private function buildProductPatches(Product $product): array
    {
        if (!is_numeric($product->retail_price) || !is_numeric($product->quantity)) {
            throw new Exception("Invalid price or quantity for SKU {$product->sku}");
        }

        return [
            new PatchOperation(
                op: 'replace',
                path: '/attributes/purchasable_offer',
                value: [[
                    'marketplace_id' => $this->marketplaceId,
                    'currency' => $this->currency,
                    'our_price' => [[
                        'schedule' => [[
                            'value_with_tax' => (float) $product->retail_price
                        ]]
                    ]]
                ]]
            ),
            new PatchOperation(
                op: 'replace',
                path: '/attributes/fulfillment_availability',
                value: [[
                    'fulfillment_channel_code' => 'DEFAULT',
                    'lead_time_to_ship_max_days' => 2,
                    'quantity' => (int) $product->quantity
                ]]
            )
        ];
    }

    /**
     * Submit the product update to Amazon
     */
    private function submitProductUpdate(string $sku, array $patches)
    {
        return $this->listingsItemsApi->patchListingsItem(
            sellerId: $this->sellerId,
            sku: $sku,
            listingsItemPatchRequest: new ListingsItemPatchRequest(
                productType: 'PRODUCT',
                patches: $patches
            ),
            marketplaceIds: [$this->marketplaceId]
        );
    }

    /**
     * Handle the API response
     */
    private function handleUpdateResponse(Product $product, $response): void
    {
        if ($response->status === 'ACCEPTED') {
            $product->update([
                'inventory_feed_status' => 1,
                'price_feed_status' => 1
            ]);

            $successMessage = "Product {$product->sku} updated successfully on Amazon.";
            Log::info($successMessage);
            echo $successMessage . PHP_EOL;
        } elseif ($response->status === 'INVALID') {
            throw new Exception("Invalid submission for SKU {$product->sku}: " . json_encode($response));
        } else {
            $unexpectedMessage = "Unexpected status for SKU {$product->sku}: {$response->status}";
            Log::warning($unexpectedMessage);
            echo $unexpectedMessage . PHP_EOL;
            throw new Exception("Unexpected status for SKU {$product->sku}: {$response->status}");
        }
    }

    /**
     * Handle product update errors
     */
    private function handleProductError(Product $product, Exception $e): void
    {
        $errorMessage = "Error updating product {$product->sku}: {$e->getMessage()}";
        Log::error($errorMessage);
        echo $errorMessage . PHP_EOL;
        report($e);

        $product->update([
            'inventory_feed_status' => 2,
            'price_feed_status' => 2
        ]);
    }

    /**
     * Validate required configuration
     */
    private function validateConfig(): void
    {
        if (!$this->sellerId || !$this->marketplaceId || !$this->currency) {
            throw new Exception('Missing required Amazon API configuration');
        }
    }
}
