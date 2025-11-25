<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;

class ShopifyGraphQLService extends ShopifyConnectionService
{
    /**
     * Update product variants with both price and inventory using productSet mutation
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

        // Build variants input array
        $variantsInput = [];
        foreach ($variantsData as $variantData) {
            $variantGid = "gid://shopify/ProductVariant/{$variantData['variant_id']}";

            $variantInput = [
                'id' => $variantGid,
            ];

            // Add price if provided
            if (isset($variantData['price'])) {
                $variantInput['price'] = (string) $variantData['price'];
            }

            // Add compareAtPrice if provided (null to remove, value to set)
            if (array_key_exists('compare_at_price', $variantData)) {
                $variantInput['compareAtPrice'] = $variantData['compare_at_price'] !== null
                    ? (string) $variantData['compare_at_price']
                    : null;
            }

            // Add inventory quantities if provided
            if (isset($variantData['inventory_quantity'])) {
                $variantInput['inventoryQuantities'] = [
                    [
                        'locationId' => $locationGid,
                        'name' => 'available',
                        'quantity' => (int) $variantData['inventory_quantity'],
                    ],
                ];
            }

            $variantsInput[] = $variantInput;
        }

        // ProductSet GraphQL mutation
        $productSetMutation = <<<'GRAPHQL'
        mutation productSet($input: ProductSetInput!, $identifier: ProductSetIdentifiers) {
          productSet(synchronous: true, input: $input, identifier: $identifier) {
            product {
              id
              variants(first: 250) {
                nodes {
                  id
                  sku
                  price
                  compareAtPrice
                  inventoryQuantity
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

        try {
            Log::debug("ShopifyGraphQLService: Executing productSet mutation for product {$productId} with ".count($variantsInput).' variants');

            $response = $client->query([
                'query' => $productSetMutation,
                'variables' => [
                    'identifier' => [
                        'id' => $productGid,
                    ],
                    'input' => [
                        'variants' => $variantsInput,
                    ],
                ],
            ]);

            $resultBody = json_decode($response->getBody()->getContents(), true);

            // Check for errors
            $userErrors = $resultBody['data']['productSet']['userErrors'] ?? [];
            $graphqlErrors = $resultBody['errors'] ?? [];

            if (! empty($userErrors) || ! empty($graphqlErrors)) {
                Log::error('ShopifyGraphQLService: productSet mutation returned errors', [
                    'product_id' => $productId,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                ]);

                return [
                    'success' => false,
                    'user_errors' => $userErrors,
                    'graphql_errors' => $graphqlErrors,
                    'data' => null,
                ];
            }

            $productData = $resultBody['data']['productSet']['product'] ?? null;

            Log::debug('ShopifyGraphQLService: productSet mutation successful', [
                'product_id' => $productId,
                'variants_updated' => count($variantsInput),
            ]);

            return [
                'success' => true,
                'user_errors' => [],
                'graphql_errors' => [],
                'data' => $productData,
            ];
        } catch (\Exception $e) {
            Log::error('ShopifyGraphQLService: Exception during productSet mutation', [
                'product_id' => $productId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'user_errors' => [],
                'graphql_errors' => [['message' => $e->getMessage()]],
                'data' => null,
                'exception' => $e,
            ];
        }
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
}
