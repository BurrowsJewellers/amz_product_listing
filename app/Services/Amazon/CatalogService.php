<?php

namespace App\Services\Amazon;

use App\Models\Product;
use App\Exceptions\AmazonApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CatalogService
{
    private string $sellerId;
    private string $marketplaceId;
    private string $currency;
    private AmazonSpApiService $amazonService;
    private ListingService $listingService;

    public function __construct(?AmazonSpApiService $amazonService = null, ?ListingService $listingService = null)
    {
        $this->amazonService = $amazonService ?? new AmazonSpApiService();
        $this->listingService = $listingService ?? new ListingService();
        $this->sellerId = config('amazon.spapi.seller_id');
        $this->marketplaceId = config('amazon.spapi.marketplace_id');
        $this->currency = config('amazon.spapi.currency');

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
     * Process a single product - search in catalog and submit as appropriate
     *
     * @param Product $product
     * @return bool
     * @throws AmazonApiException
     */
    public function processProduct(Product $product): bool
    {
        try {
            $this->validateConfiguration();

            $sellerConnector = $this->amazonService->getSellerConnector();
            $catalogItemsApi = $sellerConnector->catalogItemsV20220401();

            // First check if the product has identifiers to search in the catalog
            if ($product->ean) {
                Log::info("{$product->sku}, searching in Amazon Catalog with EAN");
                $response = $catalogItemsApi->searchCatalogItems(
                    marketplaceIds: [$this->marketplaceId],
                    identifiers: [$product->ean],
                    identifiersType: 'EAN',
                    includedData: ['summaries', 'productTypes'],
                    sellerId: $this->sellerId,
                );

                $itemSearchResults = $response->dto();

                if ($itemSearchResults->numberOfResults > 0) {
                    Log::info("{$product->sku}, found in Amazon Catalog");
                    echo "{$product->sku} found in Amazon Catalog.\n";
                    return $this->processExistingProduct($product, $itemSearchResults->items[0]);
                }
            } elseif ($product->upc) {
                Log::info("{$product->sku}, searching in Amazon Catalog with UPC");
                $response = $catalogItemsApi->searchCatalogItems(
                    marketplaceIds: [$this->marketplaceId],
                    identifiers: [$product->upc],
                    identifiersType: 'UPC',
                    includedData: ['summaries', 'productTypes'],
                    sellerId: $this->sellerId,
                );

                $itemSearchResults = $response->dto();

                if ($itemSearchResults->numberOfResults > 0) {
                    Log::info("{$product->sku}, found in Amazon Catalog");
                    echo "{$product->sku} found in Amazon Catalog.\n";
                    return $this->processExistingProduct($product, $itemSearchResults->items[0]);
                }
            }

            // If we get here, either the product wasn't found in the catalog or it doesn't have EAN/UPC
            // Submit as a new product
            Log::info("{$product->sku}, submitting new product to Amazon");
            return $this->processNewProduct($product);
        } catch (\Exception $e) {
            Log::error("Failed to process product {$product->sku}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new AmazonApiException(
                "Failed to process product {$product->sku}: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Search and process items in Amazon catalog
     *
     * @return array<string, mixed> Processing results
     * @throws AmazonApiException
     */
    public function searchItem(): array
    {
        try {
            $this->validateConfiguration();

            $products = $this->getUnsubmittedProducts();

            $results = [
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];

            foreach ($products as $product) {
                try {
                    $success = $this->processProduct($product);

                    if ($success) {
                        $results['success']++;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = [
                            'sku' => $product->sku,
                            'message' => 'Failed to process product'
                        ];
                    }

                    sleep(1); // Rate limiting
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'sku' => $product->sku,
                        'message' => $e->getMessage()
                    ];

                    Log::error("Failed to process product {$product->sku}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            return $results;
        } catch (\Exception $e) {
            throw new AmazonApiException(
                "Failed to connect to Amazon API: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get unsubmitted products
     *
     * @return Collection<Product>
     */
    private function getUnsubmittedProducts(): Collection
    {
        return Product::where([
            'submitted' => 0,
            'exists_on_amazon' => 0
        ])->get();
    }

    /**
     * Process existing product on Amazon
     *
     * @param Product $product
     * @param object $item
     * @return bool
     */
    private function processExistingProduct(Product $product, object $item): bool
    {
        try {
            $product->update([
                'exists_on_amazon' => 1,
                'asin' => $item->asin,
                'amz_product_type' => $item->productTypes[0]->productType
            ]);

            return $this->listingService->submitOfferOnly($product);
        } catch (\Exception $e) {
            Log::error("Failed to process existing product {$product->sku}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Process new product for Amazon
     *
     * @param Product $product
     * @return bool
     */
    private function processNewProduct(Product $product): bool
    {
        try {
            return $this->listingService->submitNewListing($product);
        } catch (\Exception $e) {
            Log::error("Failed to process new product {$product->sku}: {$e->getMessage()}");
            return false;
        }
    }
}
