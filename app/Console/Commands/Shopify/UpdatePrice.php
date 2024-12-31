<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Shopify\Clients\Graphql;

class UpdatePrice extends Command
{
    protected $signature = 'shopifyUpdatePrice';
    protected $description = 'Update Shopify variant prices using GraphQL bulk update';

    private $client;
    private $shopifyService;

    public function __construct()
    {
        parent::__construct();
        $this->shopifyService = new ShopifyService();
    }

    private function checkResponseForErrors($response)
    {
        $responseBody = $response->getDecodedBody();

        // Check for GraphQL response errors
        if (isset($responseBody['errors'])) {
            throw new \Exception(json_encode($responseBody['errors']));
        }

        // Check for user errors in the mutation response
        if (
            isset($responseBody['data']['productVariantsBulkUpdate']['userErrors'])
            && !empty($responseBody['data']['productVariantsBulkUpdate']['userErrors'])
        ) {
            throw new \Exception(json_encode($responseBody['data']['productVariantsBulkUpdate']['userErrors']));
        }

        return $responseBody;
    }

    private function updateVariantPrices($variants)
    {
        $mutation = <<<QUERY
        mutation productVariantsBulkUpdate(\$variants: [ProductVariantsBulkInput!]!) {
            productVariantsBulkUpdate(variants: \$variants) {
                productVariants {
                    id
                    price
                }
                userErrors {
                    field
                    message
                }
            }
        }
        QUERY;

        $variantInputs = $variants->map(function ($variant) {
            return [
                'id' => "gid://shopify/ProductVariant/{$variant->variant_id}",
                'price' => $variant->price,
                'compareAtPrice' => $variant->compare_at_price
            ];
        })->toArray();

        $variables = [
            'variants' => $variantInputs
        ];

        $response = $this->client->query(['query' => $mutation, 'variables' => $variables]);
        return $this->checkResponseForErrors($response);
    }

    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUpdatePrice';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $session = $this->shopifyService->getSession();
                $this->client = new Graphql($session->getShop(), $session->getAccessToken());

                $count = ShopifyProductVariant::whereNotNull('variant_id')
                    ->where('price_requires_update', 1)
                    ->count();
                $this->info("Remaining {$count}");

                while ($count > 0) {
                    // Process in batches of 100 variants
                    $variants = ShopifyProductVariant::with('retailEdgeProduct')
                        ->whereNotNull('variant_id')
                        ->where('price_requires_update', 1)
                        ->take(100)
                        ->get();

                    if ($variants->isNotEmpty()) {
                        try {
                            // Update prices using GraphQL bulk update
                            $response = $this->updateVariantPrices($variants);

                            // Update local records
                            foreach ($variants as $variant) {
                                $variant->update([
                                    'price' => $variant->price,
                                    'compare_at_price' => $variant->compare_at_price,
                                    'price_requires_update' => 0
                                ]);
                                $this->info("Price updated for id {$variant->id}, sku {$variant->sku}, variant id {$variant->variant_id}");
                            }
                        } catch (\Exception $e) {
                            // Mark all variants in the batch as failed
                            foreach ($variants as $variant) {
                                $variant->update(['price_requires_update' => 2]);
                                Log::debug("There was an error while updating the price to {$variant->price} for {$variant->sku}. Error message : {$e->getMessage()}");
                            }
                        }
                        usleep(1500000); // 1.5 second delay between batches
                    }

                    $count = ShopifyProductVariant::whereNotNull('variant_id')
                        ->where('price_requires_update', 1)
                        ->count();
                    $this->info("Remaining {$count}");
                }

                $job->update(['status' => 0, 'message' => null]);
                Log::info("$marketplace $jobType finished!");
            } catch (\Exception $e) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                report($e);
                $this->error($e->getMessage());
            }
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
