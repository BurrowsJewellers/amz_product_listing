<?php

namespace App\Console\Commands\Shopify;

use App\Models\RetailEdgeProduct;
use App\Models\RetailEdgeProductIsd;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyProductVariant;
use App\Models\ShopifyProductVariantMetafield;
use App\Models\ShopifyProductMetafield;
use App\Services\ShopifyConnectionService; // Changed
use App\Services\MetafieldAssignmentService; // Added
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use Shopify\Clients\Graphql; // Added
use Illuminate\Support\Str;

class UpdateProduct extends Command
{
    protected $signature = 'shopify:update-product'; // Changed signature for clarity
    protected $description = 'Updates Shopify products and their variant metafields using GraphQL API.';

    protected ShopifyConnectionService $shopifyConnectionService;
    protected array $variantTypes = ['vt1' => 'Size', 'vt2' => 'Color', 'vt3' => 'Material', 'vt4' => 'Style'];


    public function __construct(ShopifyConnectionService $shopifyConnectionService)
    {
        parent::__construct();
        $this->shopifyConnectionService = $shopifyConnectionService;
    }

    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUpdateProduct';
        $job = SyncJobController::getJob($jobType, $marketplace);

        if ($job->isRunning()) {
            Log::info("$marketplace $jobType is already running.");
            $this->info("$marketplace $jobType is already running.");
            return 1;
        }

        try {
            Log::info("$marketplace $jobType started!");
            // $job->update(['status' => 1]); // Consider re-enabling if you manage job status this way

            // Fetch variants that need updating.
            // Assuming RetailEdgeProduct.update_date_time triggers an update.
            // $skusToUpdate = RetailEdgeProduct::where('update_date_time', '>', now()->subHours(24))->pluck('sku')->toArray();

            // Or, if you have a direct flag on ShopifyProductVariant:
            // $variantsToUpdate = ShopifyProductVariant::with(['retailEdgeProduct.brand', 'product', 'retailEdgeProduct.children'])
            //     ->where('requires_update', 1) // Assuming such a flag exists
            //     ->get();

            // $variantsToUpdate = ShopifyProductVariant::with([
            //     'retailEdgeProduct.brand', // For vendor, tags
            //     'retailEdgeProduct.children', // For constructing variant data if needed, though less relevant for updates of existing variants
            //     'product' // To get shopify_product_id (Product GID)
            // ])
            //     ->whereHas('retailEdgeProduct') // Ensure related RetailEdgeProduct exists
            //     ->whereIn('sku', $skusToUpdate)
            //     ->get();


            $variantsToUpdate = ShopifyProductVariant::with([
                'retailEdgeProduct.brand',
                'retailEdgeProduct.children',
                'product'
            ])
                ->where(function ($query) {
                    $query->where('requires_update', 1)
                        ->orWhereHas('retailEdgeProduct', function ($subQuery) {
                            $subQuery->where('update_date_time', '>', now()->subHours(24));
                        });
                })
                ->get();

            if ($variantsToUpdate->isEmpty()) {
                $this->info('No products require updating at this time.');
                // $job->update(['status' => 0, 'message' => 'No products to update.']);
                Log::info("$marketplace $jobType finished: No products to update.");
                return 0;
            }

            $this->info("Found {$variantsToUpdate->count()} Shopify product variants to potentially update.");

            $client = new Graphql($this->shopifyConnectionService->getSession()->getShop(), $this->shopifyConnectionService->getSession()->getAccessToken());

            foreach ($variantsToUpdate as $variant) {
                $this->info("Processing SKU: {$variant->sku} (Variant GID: {$variant->variant_id}, Product GID: {$variant->shopify_product_id})");

                $retailEdgeProduct = $variant->retailEdgeProduct;
                if (!$retailEdgeProduct) {
                    $this->warn("Skipping SKU {$variant->sku}: No associated RetailEdgeProduct found.");
                    continue;
                }

                // Find the specific child that matches the variant's SKU for detailed attributes
                $retailEdgeChild = $retailEdgeProduct->children->firstWhere('sku', $variant->sku) ?? $retailEdgeProduct;


                // 1. Prepare Product and Variant Core Data for productUpdate mutation
                $productInput = [
                    'id' => "gid://shopify/Product/{$variant->product_id}",
                    'title' => $retailEdgeProduct->title,
                    'descriptionHtml' => $this->buildProductDescription($retailEdgeProduct), // Trying descriptionHtml
                    'vendor' => $retailEdgeProduct->brand?->name ?? null,
                    'productType' => $retailEdgeProduct->s_cat,
                    'tags' => $this->calculateTags($retailEdgeProduct, $variant->product->tags ?? ''),
                ];
                if ($retailEdgeProduct->brand?->name === 'Pandora') {
                    $productInput['templateSuffix'] = 'no-buy';
                }

                // Variant specific updates
                $calculatedVariantPrice = $this->calculatePrice($retailEdgeChild);
                $variantInput = [
                    'id' => "gid://shopify/ProductVariant/{$variant->variant_id}", // Corrected GID
                    'price' => $calculatedVariantPrice,
                    'compareAtPrice' => $this->calculateCompareAtPrice($retailEdgeChild, $calculatedVariantPrice),
                    'barcode' => $retailEdgeChild->barcode,
                    'inventoryItem' => [
                        'sku' => $retailEdgeChild->sku,
                        // 'cost' => $retailEdgeChild->cost, // Add if you have cost data
                        'tracked' => true, // Add if you want to track inventory
                    ],
                    // 'inventoryPolicy' => 'DENY', // Usually set at creation
                    // 'inventoryQuantities' => [['availableQuantity' => $retailEdgeChild->quantity, 'locationId' => 'gid://shopify/Location/73940500785']], // Cannot be used in updates - only for creation
                    // Option values are part of variant identification, changing them often means new variant.
                    // If you need to update selectedOptions, ensure they match existing option names.
                    // 'optionValues' => $this->buildVariantOptionsInput($retailEdgeChild), // This is complex for updates
                ];
                // $productInput['variants'] = [$variantInput]; // Removed variants from productInput

                // Call productUpdate mutation (Product-level fields only)
                $productUpdateMutation = <<<GRAPHQL
                mutation productUpdate(\$input: ProductInput!) {
                  productUpdate(input: \$input) {
                    product {
                      id
                      title
                    }
                    userErrors {
                      field
                      message
                    }
                  }
                }
                GRAPHQL;

                try {
                    $this->line("Attempting to update product-level data for Product GID: {$productInput['id']}");
                    // Remove variants from productInput before sending if it was added for some reason
                    unset($productInput['variants']);
                    $response = $client->query(['query' => $productUpdateMutation, 'variables' => ['input' => $productInput]]);
                    $resultBody = json_decode($response->getBody()->getContents(), true);

                    $userErrors = $resultBody['data']['productUpdate']['userErrors'] ?? ($resultBody['errors'] ?? []);
                    if (!empty($userErrors)) {
                        foreach ($userErrors as $error) {
                            $this->error("Shopify Product Update API Error for Product GID {$productInput['id']}: {$error['message']} " . (isset($error['field']) ? json_encode($error['field']) : ''));
                            Log::error("Shopify Product Update API Error for Product GID {$productInput['id']}: " . json_encode($error));
                        }
                    } else {
                        $this->info("Successfully updated product-level data for Product GID: {$productInput['id']}");
                    }
                } catch (\Exception $e) {
                    $this->error("Exception during productUpdate for Product GID {$productInput['id']}: " . $e->getMessage());
                    Log::error("Exception during productUpdate for Product GID {$productInput['id']}: " . $e->getMessage(), ['exception' => $e]);
                }

                // Update variant using productVariantsBulkUpdate mutation
                $variantsBulkUpdateMutation = <<<GRAPHQL
                mutation productVariantsBulkUpdate(\$productId: ID!, \$variants: [ProductVariantsBulkInput!]!) {
                  productVariantsBulkUpdate(productId: \$productId, variants: \$variants) {
                    product {
                      id
                    }
                    productVariants {
                      id
                      sku
                      price
                      compareAtPrice
                      barcode
                    }
                    userErrors {
                      field
                      message
                    }
                  }
                }
                GRAPHQL;

                try {
                    $this->line("Attempting to update variant data for Variant GID: {$variantInput['id']}");
                    $response = $client->query([
                        'query' => $variantsBulkUpdateMutation,
                        'variables' => [
                            'productId' => "gid://shopify/Product/{$variant->product_id}",
                            'variants' => [$variantInput] // Single variant in array
                        ]
                    ]);
                    $resultBody = json_decode($response->getBody()->getContents(), true);

                    $userErrors = $resultBody['data']['productVariantsBulkUpdate']['userErrors'] ?? ($resultBody['errors'] ?? []);
                    if (!empty($userErrors)) {
                        foreach ($userErrors as $error) {
                            $this->error("Shopify Variant Bulk Update API Error for Variant GID {$variantInput['id']}: {$error['message']} " . (isset($error['field']) ? json_encode($error['field']) : ''));
                            Log::error("Shopify Variant Bulk Update API Error for Variant GID {$variantInput['id']}: " . json_encode($error));
                        }
                    } else {
                        $this->info("Successfully updated variant data for Variant GID: {$variantInput['id']}");
                        // $variant->update(['requires_update' => 0]); // Update local flag if successful
                    }
                } catch (\Exception $e) {
                    $this->error("Exception during variant bulk update for Variant GID {$variantInput['id']}: " . $e->getMessage());
                    Log::error("Exception during variant bulk update for Variant GID {$variantInput['id']}: " . $e->getMessage(), ['exception' => $e]);
                }

                // 2. Dynamic Metafield Assignment using MetafieldAssignmentService
                $metafieldService = new MetafieldAssignmentService();
                $assignment = $metafieldService->determineMetafieldAssignment($retailEdgeProduct);

                $this->line("Metafield assignment type: {$assignment['type']} for Product: {$retailEdgeProduct->sku}");

                $metafieldsToSet = [];

                // Handle product-level metafields
                if (!empty($assignment['product_metafields'])) {
                    $this->line("Processing " . count($assignment['product_metafields']) . " product-level metafields");
                    foreach ($assignment['product_metafields'] as $metafield) {
                        $shopifyMetafieldDef = ShopifyMetafield::where('name', $metafield['isd_name'])
                            ->where('owner_type', 'PRODUCT')
                            ->first();

                        if ($shopifyMetafieldDef && !empty($metafield['value'])) {
                            $metafieldsToSet[] = [
                                'ownerId' => "gid://shopify/Product/{$variant->product_id}",
                                'namespace' => $shopifyMetafieldDef->namespace,
                                'key' => $shopifyMetafieldDef->key,
                                'type' => $shopifyMetafieldDef->type,
                                'value' => (string) $metafield['value'],
                            ];
                            $this->line("Added product metafield: {$metafield['isd_name']} = {$metafield['value']}");
                        } else {
                            $this->warn("Skipping product metafield '{$metafield['isd_name']}': Definition not found or empty value.");
                        }
                    }
                }

                // Handle variant-level metafields
                if (!empty($assignment['variant_metafields'][$variant->sku])) {
                    $this->line("Processing " . count($assignment['variant_metafields'][$variant->sku]) . " variant-level metafields for SKU: {$variant->sku}");
                    foreach ($assignment['variant_metafields'][$variant->sku] as $metafield) {
                        $shopifyMetafieldDef = ShopifyMetafield::where('name', $metafield['isd_name'])
                            ->where('owner_type', 'PRODUCTVARIANT')
                            ->first();

                        if ($shopifyMetafieldDef && !empty($metafield['value'])) {
                            $metafieldsToSet[] = [
                                'ownerId' => "gid://shopify/ProductVariant/{$variant->variant_id}",
                                'namespace' => $shopifyMetafieldDef->namespace,
                                'key' => $shopifyMetafieldDef->key,
                                'type' => $shopifyMetafieldDef->type,
                                'value' => (string) $metafield['value'],
                            ];
                            $this->line("Added variant metafield: {$metafield['isd_name']} = {$metafield['value']}");
                        } else {
                            $this->warn("Skipping variant metafield '{$metafield['isd_name']}' for SKU {$variant->sku}: Definition not found or empty value.");
                        }
                    }
                }

                // Batch process metafields in chunks of 250 (Shopify's limit)
                if (!empty($metafieldsToSet)) {
                    $this->processMetafieldsInBatches($metafieldsToSet, $variant->sku, $retailEdgeProduct->sku, $client);
                } else {
                    $this->line("No metafields to set for SKU: {$variant->sku}");
                }
                // Removed sleep(180); as it's likely unintended
                usleep(1000000); // 1 second delay
            }

            // $job->update(['status' => 0, 'message' => 'Completed successfully.']);
            Log::info("$marketplace $jobType finished successfully!");
            $this->info("$marketplace $jobType finished successfully!");
        } catch (\Exception $e) {
            // $job->update(['status' => 0, 'message' => "Error: {$e->getMessage()}"]);
            Log::error("$marketplace $jobType failed: " . $e->getMessage(), ['exception' => $e]);
            $this->error("An overall error occurred: " . $e->getMessage());
            report($e);
            return 1;
        }
        return 0;
    }

    private function buildProductDescription(RetailEdgeProduct $product): string
    {
        $mktDescription = $product->marketing_description ?? '';
        if ($product->brand?->name == 'Pandora') {
            $mktDescription .= " - Design number: " . $product->real_design_number;
        }
        return $mktDescription;
    }

    private function calculatePrice(RetailEdgeProduct $retailEdgeChild): string
    {
        $retailPrices = [$retailEdgeChild->retail_price1, $retailEdgeChild->retail_price2];
        $prices = array_filter(array_map('floatval', $retailPrices), fn($price) => $price > 0);
        return (string) (empty($prices) ? 0 : min($prices));
    }

    private function calculateCompareAtPrice(RetailEdgeProduct $retailEdgeChild, string $currentPrice): string
    {
        $currentPriceFloat = floatval($currentPrice);
        $retailPrices = [$retailEdgeChild->retail_price1, $retailEdgeChild->retail_price2];
        $prices = array_filter(array_map('floatval', $retailPrices), fn($price) => $price > 0);
        $compareAtPrice = empty($prices) ? 0 : max($prices);
        return (string) (($currentPriceFloat == $compareAtPrice) ? 0 : $compareAtPrice);
    }

    // buildVariantOptionsInput might be needed if you intend to change variant option values.
    // This is complex as it might require restructuring options. For now, focusing on attribute updates.
    /*
    private function buildVariantOptionsInput(RetailEdgeProduct $child): array
    {
        $optionsInput = [];
        $vts = array_filter(array_map('trim', array_map('strtolower', explode("-", $child->id3))));
        $optionIndex = 1;

        foreach ($vts as $vt) {
            $vt = trim($vt);
            if (isset($this->variantTypes[$vt])) {
                $variantType = $this->variantTypes[$vt];
                $variantTypeValue = '';

                // Logic from CreateProduct to determine option value
                if ($vt == 'vt1') { // Size
                    if ($child->s_cat == 'Rings') $variantTypeValue = $child->ring_size;
                    elseif ($child->s_cat == 'Bracelets') $variantTypeValue = $child->bracelet_length;
                } elseif ($vt == 'vt2') { // Color
                    $variantTypeValue = $child->metal_colour;
                } elseif ($vt == 'vt3') { // Material
                    $variantTypeValue = $child->s_metal_type;
                } elseif ($vt == 'vt4') { // Style
                    $variantTypeValue = $child->pendant_style;
                }

                if (!empty($variantTypeValue)) {
                    // For productUpdate, variant options are usually just an array of strings ['value1', 'value2']
                    // if the option names are fixed at product level.
                    // Or if you are providing selectedOptions for a variant:
                    // $optionsInput[] = ['name' => $variantType, 'value' => $variantTypeValue];
                    // This part needs to align with how productUpdate handles variant options.
                    // For simplicity, if option names are 'Option1', 'Option2', 'Option3' on Shopify:
                    // $optionsInput["option{$optionIndex}"] = $variantTypeValue;
                }
                $optionIndex++;
            }
        }
        return $optionsInput; // Adjust based on exact GraphQL requirements for variant option updates
    }
    */

    private function calculateTags(RetailEdgeProduct $product, string|array $existingTags = null): array
    {
        $tags = $this->normalizeExistingTags($existingTags);
        $originalTagCount = count($tags);

        // Remove old S.* tags to rebuild them, preserving other tags
        $tags = array_filter($tags, function ($tag) {
            return !Str::startsWith($tag, 'S.');
        });


        $types = [
            's_web_menu' => 'S.WebMenu',
            's_metal_type' => 'S.Metal Type',
            's_stone_type' => 'S.Stone Type',
            's_cat' => 'S.Cat',
            's_sub_cat' => 'S.Sub Cat',
        ];

        foreach ($types as $propertyName => $tagPrefix) {
            $this->addProductPropertyTags($product, $propertyName, $tagPrefix, $tags);
        }

        if ($product->brand?->name == 'Pandora' && !in_array('Pandora', $tags)) {
            $tags[] = 'Pandora';
        }


        return array_values(array_unique($tags)); // Return as indexed array
    }

    private function normalizeExistingTags(string|array|null $existingTags): array
    {
        if (empty($existingTags)) {
            return [];
        }
        $tags = is_string($existingTags) ? explode(",", $existingTags) : $existingTags;
        return array_map('trim', $tags);
    }

    private function addProductPropertyTags(RetailEdgeProduct $product, string $propertyName, string $tagPrefix, array &$tags): void
    {
        $propertyValue = $product->{$propertyName} ?? '';
        if ($propertyValue !== '' && $propertyValue !== 'N/A') {
            foreach (explode(",", $propertyValue) as $tagValue) {
                if (!empty(trim($tagValue))) {
                    $tags[] = trim($tagPrefix) . "_" . trim($tagValue);
                }
            }
        }
    }

    /**
     * Process metafields in batches of 250 (Shopify's limit)
     */
    private function processMetafieldsInBatches(array $metafieldsToSet, string $variantSku, string $productSku, $client): void
    {
        $batchSize = 25; // Shopify's actual limit for metafields
        $totalMetafields = count($metafieldsToSet);
        $batches = array_chunk($metafieldsToSet, $batchSize);

        $this->line("Processing {$totalMetafields} metafields in " . count($batches) . " batches of {$batchSize} for SKU: {$variantSku}");

        $metafieldsSetMutation = <<<GRAPHQL
        mutation metafieldsSet(\$metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: \$metafields) {
            metafields {
              id
              key
              namespace
              value
            }
            userErrors {
              field
              message
              elementIndex
            }
          }
        }
        GRAPHQL;

        $totalSuccessful = 0;
        $totalFailed = 0;
        $allResultBodies = [];

        foreach ($batches as $batchIndex => $batch) {
            $batchNumber = $batchIndex + 1;
            $this->line("Processing batch {$batchNumber}/" . count($batches) . " (" . count($batch) . " metafields)");

            try {
                $response = $client->query(['query' => $metafieldsSetMutation, 'variables' => ['metafields' => $batch]]);
                $resultBody = json_decode($response->getBody()->getContents(), true);

                $userErrors = $resultBody['data']['metafieldsSet']['userErrors'] ?? ($resultBody['errors'] ?? []);
                if (!empty($userErrors)) {
                    foreach ($userErrors as $error) {
                        $failedMetafieldIndex = $error['elementIndex'] ?? 'N/A';
                        $failedMetafield = ($failedMetafieldIndex !== 'N/A' && isset($batch[$failedMetafieldIndex])) ? $batch[$failedMetafieldIndex]['key'] : 'unknown';
                        $this->error("Shopify MetafieldsSet API Error in batch {$batchNumber} for SKU {$variantSku} (Metafield: {$failedMetafield}): {$error['message']}");
                        Log::error("Shopify MetafieldsSet API Error for SKU {$variantSku}: " . json_encode($error) . " | Metafield data: " . json_encode($batch[$failedMetafieldIndex] ?? []));
                        $totalFailed++;
                    }
                } else {
                    $createdMetafields = $resultBody['data']['metafieldsSet']['metafields'] ?? [];
                    $batchSuccessful = count($createdMetafields);
                    $totalSuccessful += $batchSuccessful;
                    $this->info("Batch {$batchNumber} successful: {$batchSuccessful} metafields updated");

                    // Store result body for database saving
                    $allResultBodies[] = [
                        'resultBody' => $resultBody,
                        'batch' => $batch,
                        'batchOffset' => $batchIndex * $batchSize
                    ];
                }

                // Small delay between batches to avoid rate limiting
                if ($batchNumber < count($batches)) {
                    usleep(500000); // 0.5 second delay
                }
            } catch (\Exception $e) {
                $this->error("Exception during metafieldsSet batch {$batchNumber} for SKU {$variantSku}: " . $e->getMessage());
                Log::error("Exception during metafieldsSet for SKU {$variantSku}: " . $e->getMessage(), ['exception' => $e]);
                $totalFailed += count($batch);
            }
        }

        // Save all successful metafields to local database
        if (!empty($allResultBodies)) {
            foreach ($allResultBodies as $batchData) {
                $this->saveMetafieldsToDatabase($batchData['resultBody'], $batchData['batch'], $variantSku, $productSku);
            }
        }

        // Final summary
        $this->info("Metafield processing complete for SKU {$variantSku}: {$totalSuccessful} successful, {$totalFailed} failed out of {$totalMetafields} total");
    }

    /**
     * Save metafields to local database after successful Shopify creation (both product and variant)
     */
    private function saveMetafieldsToDatabase(array $resultBody, array $metafieldsToSet, string $variantSku, string $productSku): void
    {
        try {
            $createdMetafields = $resultBody['data']['metafieldsSet']['metafields'] ?? [];

            foreach ($createdMetafields as $index => $createdMetafield) {
                // Find the corresponding metafield from our input
                $inputMetafield = $metafieldsToSet[$index] ?? null;

                if (!$inputMetafield) continue;

                if (str_contains($inputMetafield['ownerId'], 'ProductVariant')) {
                    // This is a variant metafield, save it to variant metafields table
                    $shopifyMetafieldDef = ShopifyMetafield::where('namespace', $inputMetafield['namespace'])
                        ->where('key', $inputMetafield['key'])
                        ->where('owner_type', 'PRODUCTVARIANT')
                        ->first();

                    if ($shopifyMetafieldDef) {
                        ShopifyProductVariantMetafield::updateOrCreate(
                            [
                                'sku' => $variantSku,
                                'shopify_metafield_id' => $shopifyMetafieldDef->id,
                            ],
                            [
                                'value' => $createdMetafield['value'],
                            ]
                        );

                        $this->line("Saved variant metafield to database: {$shopifyMetafieldDef->name} = {$createdMetafield['value']} for SKU: {$variantSku}");
                    }
                } elseif (str_contains($inputMetafield['ownerId'], 'Product/')) {
                    // This is a product metafield, save it to product metafields table
                    $shopifyMetafieldDef = ShopifyMetafield::where('namespace', $inputMetafield['namespace'])
                        ->where('key', $inputMetafield['key'])
                        ->where('owner_type', 'PRODUCT')
                        ->first();

                    if ($shopifyMetafieldDef) {
                        ShopifyProductMetafield::updateOrCreate(
                            [
                                'product_sku' => $productSku,
                                'shopify_metafield_id' => $shopifyMetafieldDef->id,
                            ],
                            [
                                'value' => $createdMetafield['value'],
                            ]
                        );

                        $this->line("Saved product metafield to database: {$shopifyMetafieldDef->name} = {$createdMetafield['value']} for Product SKU: {$productSku}");
                    }
                }
            }
        } catch (\Exception $e) {
            $this->warn("Failed to save metafields to database for SKU {$variantSku}: " . $e->getMessage());
            Log::warning("Failed to save metafields to database for SKU {$variantSku}: " . $e->getMessage());
        }
    }
}
