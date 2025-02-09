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

    public function __construct()
    {
        $this->amazonService = new AmazonSpApiService();
        $this->listingService = new ListingService();
        $this->sellerId = $sellerId ?? config('amazon.spapi.seller_id');
        $this->marketplaceId = $marketplaceId ?? config('amazon.spapi.marketplace_id');
        $this->currency = $currency ?? config('amazon.spapi.currency');

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
     * Search and process items in Amazon catalog
     *
     * @param string $identifier
     * @param string $identifiersType
     * @return array<string, mixed> Processing results
     * @throws AmazonApiException
     */
    public function searchItem(): array
    {
        try {
            $this->validateConfiguration();

            $sellerConnector = $this->amazonService->getSellerConnector();
            $catalogItemsApi = $sellerConnector->catalogItemsV20220401();

            $products = $this->getUnsubmittedProducts();

            $results = [
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];

            foreach ($products as $product) {
                try {
                    // Skip products without EAN
                    if (empty($product->ean)) {
                        $results['failed']++;
                        $results['errors'][] = [
                            'sku' => $product->sku,
                            'message' => 'Missing EAN code'
                        ];
                        continue;
                    }

                    $response = $catalogItemsApi->searchCatalogItems(
                        marketplaceIds: [$this->marketplaceId],
                        identifiers: $product->ean,
                        identifiersType: 'EAN',
                        includedData: ['summaries'],
                        sellerId: $this->sellerId,
                    );

                    $itemSearchResults = $response->dto();

                    if ($itemSearchResults->numberOfResults > 0) {
                        $this->processExistingProduct($product, $itemSearchResults->item[0]);
                        $results['success']++;
                    } else {
                        $this->processNewProduct($product);
                        $results['success']++;
                    }
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
     * @return void
     */
    private function processExistingProduct(Product $product, object $item): void
    {
        $product->update([
            'exists_on_amazon' => 1,
            'asin' => $item->asin,
            'amz_product_type' => $item->productTypes[0]->productType
        ]);

        $this->listingService->submitOfferOnly($product);
    }

    /**
     * Process new product for Amazon
     *
     * @param Product $product
     * @return void
     */
    private function processNewProduct(Product $product): void
    {
        $this->listingService->submitNewListing($product);
    }
}
