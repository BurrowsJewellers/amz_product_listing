<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;

class ShopifyGraphQLService extends ShopifyConnectionService
{
    /**
     * Update product variants with both price and inventory using separate mutations
     * Uses productVariantsBulkUpdate for prices and inventorySetQuantities for inventory
     *
     * @param  int  $productId  Shopify product ID (numeric)
     * @param  array  $variantsData  Array of variant data with price and inventory
     * @param  int  $locationId  Shopify location ID for inventory
     * @return array Response data including success status and any errors
     */
    public function updateProductPriceAndInventory(int $productId, array $variantsData, int $locationId): array
    {
        $session = $this->getSession();
        $client = new Graphql($session->getShop(), $session->getAccessToken());

        // Convert numeric IDs to GIDs
        $productGid = "gid://shopify/Product/{$productId}";
        $locationGid = "gid://shopify/Location/{$locationId}";

        $allUserErrors = [];
        $allGraphqlErrors = [];
        $priceSuccess = true;
        $inventorySuccess = true;

        // Separate variants needing price updates vs inventory updates
        $priceVariants = [];
        $inventoryUpdates = [];

        foreach ($variantsData as $variantData) {
            $variantGid = "gid://shopify/ProductVariant/{$variantData['variant_id']}";

            // Build price update input
            if (isset($variantData['price']) || array_key_exists('compare_at_price', $variantData)) {
                $priceInput = ['id' => $variantGid];

                if (isset($variantData['price'])) {
                    $priceInput['price'] = (string) $variantData['price'];
                }

                if (array_key_exists('compare_at_price', $variantData)) {
                    $priceInput['compareAtPrice'] = $variantData['compare_at_price'] !== null
                        ? (string) $variantData['compare_at_price']
                        : null;
                }

                $priceVariants[] = $priceInput;
            }

            // Build inventory update input (requires inventory_item_id from variant data)
            if (isset($variantData['inventory_quantity']) && isset($variantData['inventory_item_id'])) {
                $inventoryUpdates[] = [
                    'inventoryItemId' => "gid://shopify/InventoryItem/{$variantData['inventory_item_id']}",
                    'locationId' => $locationGid,
                    'quantity' => (int) $variantData['inventory_quantity'],
                ];
            }
        }

        // Execute price updates using productVariantsBulkUpdate
        if (! empty($priceVariants)) {
            $priceResult = $this->updateVariantPrices($client, $productGid, $priceVariants, $productId);
            $priceSuccess = $priceResult['success'];
            $allUserErrors = array_merge($allUserErrors, $priceResult['user_errors']);
            $allGraphqlErrors = array_merge($allGraphqlErrors, $priceResult['graphql_errors']);
        }

        // Execute inventory updates using inventorySetQuantities
        if (! empty($inventoryUpdates)) {
            $inventoryResult = $this->updateInventoryQuantities($client, $inventoryUpdates, $productId);
            $inventorySuccess = $inventoryResult['success'];
            $allUserErrors = array_merge($allUserErrors, $inventoryResult['user_errors']);
            $allGraphqlErrors = array_merge($allGraphqlErrors, $inventoryResult['graphql_errors']);
        }

        $overallSuccess = $priceSuccess && $inventorySuccess;

        return [
            'success' => $overallSuccess,
            'user_errors' => $allUserErrors,
            'graphql_errors' => $allGraphqlErrors,
            'data' => null,
        ];
    }

    /**
     * Update variant prices using productVariantsBulkUpdate mutation
     */
    private function updateVariantPrices(Graphql $client, string $productGid, array $variants, int $productId): array
    {
        $mutation = <<<'GRAPHQL'
        mutation productVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
          productVariantsBulkUpdate(productId: $productId, variants: $variants) {
            productVariants {
              id
              price
              compareAtPrice
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        try {
            Log::debug("ShopifyGraphQLService: Executing productVariantsBulkUpdate for product {$productId} with ".count($variants).' variants');

            $response = $client->query([
                'query' => $mutation,
                'variables' => [
                    'productId' => $productGid,
                    'variants' => $variants,
                ],
            ]);

            $resultBody = json_decode($response->getBody()->getContents(), true);

            $userErrors = $resultBody['data']['productVariantsBulkUpdate']['userErrors'] ?? [];
            $graphqlErrors = $resultBody['errors'] ?? [];

            if (! empty($userErrors) || ! empty($graphqlErrors)) {
                Log::error('ShopifyGraphQLService: productVariantsBulkUpdate returned errors', [
                    'product_id' => $productId,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ]);

                return [
                    'success' => false,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ];
            }

            Log::debug('ShopifyGraphQLService: productVariantsBulkUpdate successful', [
                'product_id' => $productId,
                'variants_updated' => count($variants),
            ]);

            return [
                'success' => true,
                'user_errors' => [],
                'graphql_errors' => [],
            ];
        } catch (\Exception $e) {
            Log::error('ShopifyGraphQLService: Exception during productVariantsBulkUpdate', [
                'product_id' => $productId,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'user_errors' => [],
                'graphql_errors' => [['message' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Update inventory quantities using inventorySetQuantities mutation (internal helper)
     */
    private function updateInventoryQuantities(Graphql $client, array $quantities, int $productId): array
    {
        $mutation = <<<'GRAPHQL'
        mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
          inventorySetQuantities(input: $input) {
            inventoryAdjustmentGroup {
              createdAt
              reason
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        try {
            Log::debug("ShopifyGraphQLService: Executing inventorySetQuantities for product {$productId} with ".count($quantities).' items');

            $response = $client->query([
                'query' => $mutation,
                'variables' => [
                    'input' => [
                        'name' => 'available',
                        'reason' => 'correction',
                        'ignoreCompareQuantity' => true,
                        'quantities' => $quantities,
                    ],
                ],
            ]);

            $resultBody = json_decode($response->getBody()->getContents(), true);

            $userErrors = $resultBody['data']['inventorySetQuantities']['userErrors'] ?? [];
            $graphqlErrors = $resultBody['errors'] ?? [];

            if (! empty($userErrors) || ! empty($graphqlErrors)) {
                Log::error('ShopifyGraphQLService: inventorySetQuantities returned errors', [
                    'product_id' => $productId,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ]);

                return [
                    'success' => false,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ];
            }

            Log::debug('ShopifyGraphQLService: inventorySetQuantities successful', [
                'product_id' => $productId,
                'items_updated' => count($quantities),
            ]);

            return [
                'success' => true,
                'user_errors' => [],
                'graphql_errors' => [],
            ];
        } catch (\Exception $e) {
            Log::error('ShopifyGraphQLService: Exception during inventorySetQuantities', [
                'product_id' => $productId,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'user_errors' => [],
                'graphql_errors' => [['message' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Bulk update inventory quantities across multiple products using inventorySetQuantities
     * Processes up to 250 items per API call
     *
     * @param  array  $inventoryItems  Array of items with inventory_item_id and quantity
     * @param  int  $locationId  Shopify location ID
     * @return array Response with success status, processed count, and failed items
     */
    public function bulkUpdateInventory(array $inventoryItems, int $locationId): array
    {
        $session = $this->getSession();
        $client = new Graphql($session->getShop(), $session->getAccessToken());
        $locationGid = "gid://shopify/Location/{$locationId}";

        $mutation = <<<'GRAPHQL'
        mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
          inventorySetQuantities(input: $input) {
            inventoryAdjustmentGroup {
              createdAt
              reason
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        // Build quantities array
        $quantities = [];
        foreach ($inventoryItems as $item) {
            $quantities[] = [
                'inventoryItemId' => "gid://shopify/InventoryItem/{$item['inventory_item_id']}",
                'locationId' => $locationGid,
                'quantity' => (int) $item['quantity'],
            ];
        }

        try {
            Log::debug('ShopifyGraphQLService: Executing bulk inventorySetQuantities with '.count($quantities).' items');

            $response = $client->query([
                'query' => $mutation,
                'variables' => [
                    'input' => [
                        'name' => 'available',
                        'reason' => 'correction',
                        'ignoreCompareQuantity' => true,
                        'quantities' => $quantities,
                    ],
                ],
            ]);

            $resultBody = json_decode($response->getBody()->getContents(), true);

            $userErrors = $resultBody['data']['inventorySetQuantities']['userErrors'] ?? [];
            $graphqlErrors = $resultBody['errors'] ?? [];

            if (! empty($userErrors) || ! empty($graphqlErrors)) {
                Log::error('ShopifyGraphQLService: bulk inventorySetQuantities returned errors', [
                    'item_count' => count($quantities),
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ]);

                return [
                    'success' => false,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                    'processed_count' => 0,
                    'failed_items' => $inventoryItems,
                ];
            }

            Log::debug('ShopifyGraphQLService: bulk inventorySetQuantities successful', [
                'items_updated' => count($quantities),
            ]);

            return [
                'success' => true,
                'user_errors' => [],
                'graphql_errors' => [],
                'processed_count' => count($quantities),
                'failed_items' => [],
            ];
        } catch (\Exception $e) {
            Log::error('ShopifyGraphQLService: Exception during bulk inventorySetQuantities', [
                'item_count' => count($quantities),
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'user_errors' => [],
                'graphql_errors' => [['message' => $e->getMessage()]],
                'processed_count' => 0,
                'failed_items' => $inventoryItems,
            ];
        }
    }

    /**
     * Update variant prices for a single product using productVariantsBulkUpdate
     *
     * @param  int  $productId  Shopify product ID (numeric)
     * @param  array  $variants  Array of variant data with id, price, compareAtPrice
     * @return array Response with success status and errors
     */
    public function updateProductVariantPrices(int $productId, array $variants): array
    {
        $session = $this->getSession();
        $client = new Graphql($session->getShop(), $session->getAccessToken());
        $productGid = "gid://shopify/Product/{$productId}";

        // Convert variant IDs to GIDs and format input
        $variantsInput = [];
        foreach ($variants as $variant) {
            $input = ['id' => "gid://shopify/ProductVariant/{$variant['variant_id']}"];

            if (isset($variant['price'])) {
                $input['price'] = (string) $variant['price'];
            }

            if (array_key_exists('compare_at_price', $variant)) {
                $input['compareAtPrice'] = $variant['compare_at_price'] !== null
                    ? (string) $variant['compare_at_price']
                    : null;
            }

            $variantsInput[] = $input;
        }

        return $this->updateVariantPrices($client, $productGid, $variantsInput, $productId);
    }

    /**
     * Convert variant GID to numeric ID
     *
     * @param  string  $gid  Shopify GID (e.g., "gid://shopify/ProductVariant/123")
     * @return int|null Numeric ID or null if invalid
     */
    public function gidToNumericId(string $gid): ?int
    {
        if (preg_match('/\/(\d+)$/', $gid, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Convert numeric ID to variant GID
     *
     * @param  int  $id  Numeric variant ID
     * @return string Shopify GID
     */
    public function numericIdToVariantGid(int $id): string
    {
        return "gid://shopify/ProductVariant/{$id}";
    }

    /**
     * Convert numeric ID to product GID
     *
     * @param  int  $id  Numeric product ID
     * @return string Shopify GID
     */
    public function numericIdToProductGid(int $id): string
    {
        return "gid://shopify/Product/{$id}";
    }

    /**
     * Update product status using GraphQL productUpdate mutation
     *
     * @param  int  $productId  Shopify product ID (numeric)
     * @param  string  $status  Status to set (ACTIVE, ARCHIVED, DRAFT)
     * @return array Response with success status and errors
     */
    public function updateProductStatus(int $productId, string $status): array
    {
        $session = $this->getSession();
        $client = new Graphql($session->getShop(), $session->getAccessToken());
        $productGid = "gid://shopify/Product/{$productId}";

        $mutation = <<<'GRAPHQL'
        mutation productUpdate($input: ProductInput!) {
          productUpdate(input: $input) {
            product {
              id
              status
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        try {
            Log::debug("ShopifyGraphQLService: Executing productUpdate for product {$productId} with status {$status}");

            $response = $client->query([
                'query' => $mutation,
                'variables' => [
                    'input' => [
                        'id' => $productGid,
                        'status' => strtoupper($status),
                    ],
                ],
            ]);

            $resultBody = json_decode($response->getBody()->getContents(), true);

            $userErrors = $resultBody['data']['productUpdate']['userErrors'] ?? [];
            $graphqlErrors = $resultBody['errors'] ?? [];

            if (! empty($userErrors) || ! empty($graphqlErrors)) {
                Log::error('ShopifyGraphQLService: productUpdate returned errors', [
                    'product_id' => $productId,
                    'status' => $status,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ]);

                return [
                    'success' => false,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ];
            }

            Log::debug('ShopifyGraphQLService: productUpdate successful', [
                'product_id' => $productId,
                'status' => $status,
            ]);

            return [
                'success' => true,
                'user_errors' => [],
                'graphql_errors' => [],
            ];
        } catch (\Exception $e) {
            Log::error('ShopifyGraphQLService: Exception during productUpdate', [
                'product_id' => $productId,
                'status' => $status,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'user_errors' => [],
                'graphql_errors' => [['message' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Bulk update product statuses using GraphQL
     * Processes products one at a time but with minimal delay
     *
     * @param  array  $products  Array of products with product_id and desired status
     * @param  string  $status  Status to set (ACTIVE, ARCHIVED, DRAFT)
     * @return array Response with success count, failed items, and errors
     */
    public function bulkUpdateProductStatus(array $products, string $status): array
    {
        $successCount = 0;
        $failedItems = [];
        $allErrors = [];

        foreach ($products as $product) {
            $productId = $product['product_id'] ?? $product->product_id ?? null;

            if (! $productId) {
                $failedItems[] = $product;
                $allErrors[] = ['message' => 'Missing product_id'];

                continue;
            }

            $result = $this->updateProductStatus($productId, $status);

            if ($result['success']) {
                $successCount++;
            } else {
                $failedItems[] = $product;
                $allErrors = array_merge($allErrors, $result['user_errors'], $result['graphql_errors']);
            }

            // Minimal delay to avoid rate limiting (100ms)
            usleep(100000);
        }

        return [
            'success' => $successCount > 0 && empty($failedItems),
            'success_count' => $successCount,
            'failed_count' => count($failedItems),
            'failed_items' => $failedItems,
            'errors' => $allErrors,
        ];
    }

    /**
     * Create product media from external URL using GraphQL productUpdate mutation
     * Uses productUpdate with media parameter (productCreateMedia is deprecated)
     *
     * @param  int  $productId  Shopify product ID (numeric)
     * @param  array  $imageUrls  Array of image URLs to upload
     * @return array Response with success status, media IDs, and errors
     */
    public function createProductMedia(int $productId, array $imageUrls): array
    {
        $session = $this->getSession();
        $client = new Graphql($session->getShop(), $session->getAccessToken());
        $productGid = "gid://shopify/Product/{$productId}";

        $mutation = <<<'GRAPHQL'
        mutation productUpdateWithMedia($product: ProductUpdateInput!, $media: [CreateMediaInput!]) {
          productUpdate(product: $product, media: $media) {
            product {
              id
              media(first: 10) {
                nodes {
                  ... on MediaImage {
                    id
                    status
                    image {
                      url
                    }
                  }
                }
              }
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        // Build media input array
        $mediaInput = [];
        foreach ($imageUrls as $url) {
            $mediaInput[] = [
                'originalSource' => $url,
                'mediaContentType' => 'IMAGE',
            ];
        }

        try {
            Log::debug("ShopifyGraphQLService: Executing productUpdate with media for product {$productId} with ".count($imageUrls).' images');

            $response = $client->query([
                'query' => $mutation,
                'variables' => [
                    'product' => [
                        'id' => $productGid,
                    ],
                    'media' => $mediaInput,
                ],
            ]);

            $resultBody = json_decode($response->getBody()->getContents(), true);

            $userErrors = $resultBody['data']['productUpdate']['userErrors'] ?? [];
            $graphqlErrors = $resultBody['errors'] ?? [];

            if (! empty($userErrors) || ! empty($graphqlErrors)) {
                Log::error('ShopifyGraphQLService: productUpdate with media returned errors', [
                    'product_id' => $productId,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ]);

                return [
                    'success' => false,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                    'media' => [],
                ];
            }

            $media = $resultBody['data']['productUpdate']['product']['media']['nodes'] ?? [];

            Log::debug('ShopifyGraphQLService: productUpdate with media successful', [
                'product_id' => $productId,
                'images_created' => count($mediaInput),
            ]);

            return [
                'success' => true,
                'user_errors' => [],
                'graphql_errors' => [],
                'media' => $media,
            ];
        } catch (\Exception $e) {
            Log::error('ShopifyGraphQLService: Exception during productUpdate with media', [
                'product_id' => $productId,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'user_errors' => [],
                'graphql_errors' => [['message' => $e->getMessage()]],
                'media' => [],
            ];
        }
    }

    /**
     * Assign media to a specific variant using GraphQL
     * Uses productVariantAppendMedia mutation to attach images to a variant
     *
     * @param  int  $productId  Shopify product ID (numeric)
     * @param  int  $variantId  Shopify variant ID (numeric)
     * @param  array  $mediaIds  Array of Shopify media GIDs
     * @return array Response with success status and errors
     */
    public function assignMediaToVariant(int $productId, int $variantId, array $mediaIds): array
    {
        $session = $this->getSession();
        $client = new Graphql($session->getShop(), $session->getAccessToken());
        $productGid = "gid://shopify/Product/{$productId}";
        $variantGid = "gid://shopify/ProductVariant/{$variantId}";

        $mutation = <<<'GRAPHQL'
        mutation productVariantAppendMedia($productId: ID!, $variantMedia: [ProductVariantAppendMediaInput!]!) {
          productVariantAppendMedia(productId: $productId, variantMedia: $variantMedia) {
            product {
              id
            }
            productVariants {
              id
              media(first: 10) {
                edges {
                  node {
                    id
                  }
                }
              }
            }
            userErrors {
              code
              field
              message
            }
          }
        }
        GRAPHQL;

        try {
            $mediaCount = count($mediaIds);
            Log::debug("ShopifyGraphQLService: Executing productVariantAppendMedia to assign {$mediaCount} media to variant {$variantId}");

            $response = $client->query([
                'query' => $mutation,
                'variables' => [
                    'productId' => $productGid,
                    'variantMedia' => [
                        [
                            'variantId' => $variantGid,
                            'mediaIds' => $mediaIds,
                        ],
                    ],
                ],
            ]);

            $resultBody = json_decode($response->getBody()->getContents(), true);

            $userErrors = $resultBody['data']['productVariantAppendMedia']['userErrors'] ?? [];
            $graphqlErrors = $resultBody['errors'] ?? [];

            if (! empty($userErrors) || ! empty($graphqlErrors)) {
                Log::error('ShopifyGraphQLService: productVariantAppendMedia returned errors', [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'media_ids' => $mediaIds,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ]);

                return [
                    'success' => false,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ];
            }

            Log::debug('ShopifyGraphQLService: productVariantAppendMedia successful', [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'media_count' => $mediaCount,
            ]);

            return [
                'success' => true,
                'user_errors' => [],
                'graphql_errors' => [],
            ];
        } catch (\Exception $e) {
            Log::error('ShopifyGraphQLService: Exception during productVariantAppendMedia', [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'user_errors' => [],
                'graphql_errors' => [['message' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Upload image to product and optionally assign to variant using GraphQL
     * Combines productCreateMedia and productVariantUpdate in a workflow
     *
     * @param  int  $productId  Shopify product ID (numeric)
     * @param  int|null  $variantId  Shopify variant ID (numeric) - optional
     * @param  string  $imageUrl  URL of the image to upload
     * @return array Response with success status, media info, and errors
     */
    public function uploadProductImage(int $productId, ?int $variantId, string $imageUrl): array
    {
        // First, create the media
        $createResult = $this->createProductMedia($productId, [$imageUrl]);

        if (! $createResult['success']) {
            return $createResult;
        }

        $media = $createResult['media'] ?? [];
        if (empty($media)) {
            return [
                'success' => false,
                'user_errors' => [['message' => 'No media created']],
                'graphql_errors' => [],
            ];
        }

        // If variant is specified, assign the media to it
        if ($variantId && ! empty($media[0]['id'])) {
            $mediaId = $media[0]['id'];
            $assignResult = $this->assignMediaToVariant($variantId, $mediaId);

            return [
                'success' => $assignResult['success'],
                'user_errors' => $assignResult['user_errors'],
                'graphql_errors' => $assignResult['graphql_errors'],
                'media' => $media,
            ];
        }

        return [
            'success' => true,
            'user_errors' => [],
            'graphql_errors' => [],
            'media' => $media,
        ];
    }
}
