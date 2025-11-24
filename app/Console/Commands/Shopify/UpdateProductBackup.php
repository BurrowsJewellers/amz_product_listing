<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\Brand;
use App\Models\RetailEdgeProduct;
use App\Models\RetailEdgeProductIsd;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2025_04\Product;

class UpdateProductBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyUpdateProductBackup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'The code to update the Shopify products';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUpdateProduct';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (! $job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                // $job->update(['status' => 1]);

                $session = (new ShopifyService)->getSession();
                $brands = Brand::all();

                $brandsArray = [];

                foreach ($brands as $brand) {
                    $brandsArray[$brand->brand_id]['id'] = $brand->id;
                    $brandsArray[$brand->brand_id]['name'] = $brand->name;
                }

                $skus = RetailEdgeProduct::where('update_date_time', '>', now()->subHours(24))->pluck('sku')->toArray();

                // $variants = ShopifyProductVariant::withWhereHas('retailEdgeProduct')->with('product')->where('requires_update', 1)->select('id', 'shopify_product_id', 'product_id', 'sku')->get();
                $variants = ShopifyProductVariant::withWhereHas('retailEdgeProduct')->with('product')->whereIn('sku', $skus)->select('id', 'shopify_product_id', 'product_id', 'sku')->get();

                foreach ($variants as $variant) {
                    $this->info('Updating: '.$variant->sku);
                    $productTags = $this->calculateTags($variant->retailEdgeProduct, $variant->product->tags);

                    if ($variant->retailEdgeProduct->brand?->name == 'Pandora') {
                        $productTags[] = 'Pandora';
                    }

                    $tags = implode(',', $productTags);

                    // Fetch and add ISDs as metafields
                    $isds = RetailEdgeProductIsd::where('sku', $variant->sku)->get();
                    $metafields = [];
                    if ($isds->isNotEmpty()) {
                        foreach ($isds as $isd) {
                            // Sanitize ISD name for use as a metafield key
                            $key = strtolower($isd->isd_name);
                            $key = preg_replace('/[^a-z0-9\s]/', ' ', $key); // Replace non-alphanumeric/non-space with space
                            $key = preg_replace('/\s+/', ' ', $key);       // Collapse multiple spaces to one
                            $key = trim($key);                             // Trim leading/trailing spaces
                            $metafieldKey = str_replace(' ', '_', $key);   // Replace spaces with underscores

                            if (! empty($metafieldKey) && ! empty($isd->isd_value)) {
                                $metafields[] = [
                                    'key' => $metafieldKey,
                                    'value' => $isd->isd_value,
                                    'type' => 'single_line_text_field',
                                    'namespace' => 'retail_edge_isd',
                                ];
                            }
                        }
                    }

                    try {
                        $product = new Product($session);
                        $product->id = $variant->product_id;
                        $product->tags = $tags;

                        if ($variant->retailEdgeProduct->brand?->name == 'Pandora') {
                            $product->template_suffix = 'no-buy';
                            $product->vendor = $variant->retailEdgeProduct->brand?->name;
                        }

                        $product->save(true);

                        $variant->update(['requires_update' => 0]);
                        $variant->product->update(['tags' => $tags]);
                    } catch (\Exception $e) {
                        report($e);
                        $this->error($e->getMessage());
                    }
                    usleep(1500000);
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

    private function calculateTags(RetailEdgeProduct $product, string|array|null $existingTags = null): array
    {
        $tags = $this->normalizeExistingTags($existingTags);

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

        return array_unique($tags);
    }

    private function normalizeExistingTags(string|array|null $existingTags): array
    {
        if (empty($existingTags)) {
            return [];
        }

        $tags = is_array($existingTags) ? $existingTags : explode(',', $existingTags);

        return array_map('trim', $tags);
    }

    private function addProductPropertyTags(RetailEdgeProduct $product, string $propertyName, string $tagPrefix, array &$tags): void
    {
        $propertyValue = $product->{$propertyName} ?? '';
        if ($propertyValue !== '' && $propertyValue !== 'N/A') {
            foreach (explode(',', $propertyValue) as $tagValue) {
                $tags[] = trim($tagPrefix).'_'.trim($tagValue);
            }
        }
    }
}
