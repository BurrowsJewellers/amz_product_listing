<?php

namespace App\Console\Commands\Shopify;

use App\Models\RetailEdgeProduct;
use App\Models\RetailEdgeProductIsd;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyConnectionService; // Changed
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
            $skusToUpdate = RetailEdgeProduct::where('update_date_time', '>', now()->subHours(24))->pluck('sku')->toArray();

            // Or, if you have a direct flag on ShopifyProductVariant:
            // $variantsToUpdate = ShopifyProductVariant::with(['retailEdgeProduct.brand', 'product', 'retailEdgeProduct.children'])
            //     ->where('requires_update', 1) // Assuming such a flag exists
            //     ->get();

            $variantsToUpdate = ShopifyProductVariant::with([
                'retailEdgeProduct.brand', // For vendor, tags
                'retailEdgeProduct.children', // For constructing variant data if needed, though less relevant for updates of existing variants
                'product' // To get shopify_product_id (Product GID)
            ])
                ->whereHas('retailEdgeProduct') // Ensure related RetailEdgeProduct exists
                ->whereIn('sku', $skusToUpdate)
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
                $this->info("Processing SKU: {$variant->sku} (Variant GID: {$variant->id}, Product GID: {$variant->shopify_product_id})");

                $retailEdgeProduct = $variant->retailEdgeProduct;
                if (!$retailEdgeProduct) {
                    $this->warn("Skipping SKU {$variant->sku}: No associated RetailEdgeProduct found.");
                    continue;
                }

                // Find the specific child that matches the variant's SKU for detailed attributes
                $retailEdgeChild = $retailEdgeProduct->children->firstWhere('sku', $variant->sku) ?? $retailEdgeProduct;


                // 1. Prepare Product and Variant Core Data for productUpdate mutation
                $productInput = [
                    'id' => $variant->shopify_product_id, // Product GID
                    'title' => $retailEdgeProduct->title,
                    'bodyHtml' => $this->buildProductDescription($retailEdgeProduct),
                    'vendor' => $retailEdgeProduct->brand->name ?? null,
                    'productType' => $retailEdgeProduct->s_cat,
                    'tags' => $this->calculateTags($retailEdgeProduct, $variant->product->tags ?? ''),
                ];
                if ($retailEdgeProduct->brand->name === 'Pandora') {
                    $productInput['templateSuffix'] = 'no-buy';
                }

                // Variant specific updates
                $variantInput = [
                    'id' => $variant->variant_id, // Variant GID
                    'sku' => $retailEdgeChild->sku,
                    'price' => $this->calculatePrice($retailEdgeChild),
                    'compareAtPrice' => $this->calculateCompareAtPrice($retailEdgeChild, $productInput['price']),
                    'barcode' => $retailEdgeChild->barcode,
                    // 'inventoryManagement' => 'shopify', // Usually set at creation
                    // 'inventoryQuantities' => [['availableQuantity' => $retailEdgeChild->quantity, 'locationId' => 'gid://shopify/Location/YOUR_LOCATION_ID']], // Requires Location GID
                    // Option values are part of variant identification, changing them often means new variant.
                    // If you need to update selectedOptions, ensure they match existing option names.
                    // 'options' => $this->buildVariantOptionsInput($retailEdgeChild), // This is complex for updates
                ];
                $productInput['variants'] = [$variantInput];


                // Call productUpdate mutation
                $productUpdateMutation = <<<GRAPHQL
                mutation productUpdate(\$input: ProductInput!) {
                  productUpdate(input: \$input) {
                    product {
                      id
                      title
                      variants(first: 5) {
                        edges { node { id sku price } }
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
                    $this->line("Attempting to update product/variant core data for SKU: {$variant->sku}");
                    $response = $client->query(['query' => $productUpdateMutation, 'variables' => ['input' => $productInput]]);
                    $resultBody = json_decode($response->getBody()->getContents(), true);

                    $userErrors = $resultBody['data']['productUpdate']['userErrors'] ?? ($resultBody['errors'] ?? []);
                    if (!empty($userErrors)) {
                        foreach ($userErrors as $error) {
                            $this->error("Shopify Product Update API Error for SKU {$variant->sku}: {$error['message']} " . (isset($error['field']) ? json_encode($error['field']) : ''));
                            Log::error("Shopify Product Update API Error for SKU {$variant->sku}: " . json_encode($error));
                        }
                        // Decide if to continue to metafields or skip this SKU
                        // continue;
                    } else {
                        $this->info("Successfully updated core data for product/variant SKU: {$variant->sku}");
                        // $variant->update(['requires_update' => 0]); // Update local flag
                    }
                } catch (\Exception $e) {
                    $this->error("Exception during productUpdate for SKU {$variant->sku}: " . $e->getMessage());
                    Log::error("Exception during productUpdate for SKU {$variant->sku}: " . $e->getMessage(), ['exception' => $e]);
                    // continue; // Skip to next variant on critical error
                }

                // 2. Prepare and Set Variant Metafields
                $isds = RetailEdgeProductIsd::where('sku', $variant->sku)->get();
                if ($isds->isNotEmpty()) {
                    $metafieldsToSet = [];
                    foreach ($isds as $isd) {
                        $shopifyMetafieldDef = ShopifyMetafield::where('name', $isd->isd_name)->first();
                        if ($shopifyMetafieldDef && !empty($isd->isd_value)) {
                            $metafieldsToSet[] = [
                                'ownerId' => $variant->variant_id, // Variant GID
                                'namespace' => $shopifyMetafieldDef->namespace,
                                'key' => $shopifyMetafieldDef->key,
                                'type' => $shopifyMetafieldDef->type, // Ensure this type matches Shopify's expected type string
                                'value' => (string) $isd->isd_value,
                            ];
                        } else {
                            $this->warn("Skipping ISD '{$isd->isd_name}' for SKU {$variant->sku}: Definition not found or empty value.");
                        }
                    }

                    if (!empty($metafieldsToSet)) {
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
                              elementIndex # Helps identify which metafield failed
                            }
                          }
                        }
                        GRAPHQL;

                        try {
                            $this->line("Attempting to set/update variant metafields for SKU: {$variant->sku}");
                            $response = $client->query(['query' => $metafieldsSetMutation, 'variables' => ['metafields' => $metafieldsToSet]]);
                            $resultBody = json_decode($response->getBody()->getContents(), true);

                            $userErrors = $resultBody['data']['metafieldsSet']['userErrors'] ?? ($resultBody['errors'] ?? []);
                            if (!empty($userErrors)) {
                                foreach ($userErrors as $error) {
                                    $failedMetafieldIndex = $error['elementIndex'] ?? 'N/A';
                                    $failedMetafield = ($failedMetafieldIndex !== 'N/A' && isset($metafieldsToSet[$failedMetafieldIndex])) ? $metafieldsToSet[$failedMetafieldIndex]['key'] : 'unknown';
                                    $this->error("Shopify MetafieldsSet API Error for SKU {$variant->sku} (Metafield: {$failedMetafield}): {$error['message']} " . (isset($error['field']) ? json_encode($error['field']) : ''));
                                    Log::error("Shopify MetafieldsSet API Error for SKU {$variant->sku}: " . json_encode($error) . " | Metafield data: " . json_encode($metafieldsToSet[$failedMetafieldIndex] ?? []));
                                }
                            } else {
                                $this->info("Successfully set/updated variant metafields for SKU: {$variant->sku}");
                            }
                        } catch (\Exception $e) {
                            $this->error("Exception during metafieldsSet for SKU {$variant->sku}: " . $e->getMessage());
                            Log::error("Exception during metafieldsSet for SKU {$variant->sku}: " . $e->getMessage(), ['exception' => $e]);
                        }
                    }
                }
                sleep(180);
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

        if ($product->brand->name == 'Pandora' && !in_array('Pandora', $tags)) {
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
}
