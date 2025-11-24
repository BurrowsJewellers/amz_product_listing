<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\RetailEdgeProduct;
use App\Services\ShopifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Shopify\Clients\Graphql;

class UpdateProductDescriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:update-descriptions {--dry-run : Run without making changes} {--reset : Reset progress and start from beginning}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Updates product descriptions to include design numbers using GraphQL';

    /**
     * GraphQL client instance
     */
    private $client;

    /**
     * Progress tracking data
     */
    private $progress = [
        'processed_product_ids' => [],
        'stats' => [
            'total_processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'pandora_skipped' => 0,
            'already_has_design_number' => 0,
        ],
        'last_cursor' => null,
    ];

    /**
     * Progress file path
     */
    private $progressFile = 'shopify_description_update_progress.json';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUpdateDescriptions';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (! $job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                // Initialize GraphQL client
                $session = (new ShopifyService)->getSession();
                $this->client = new Graphql($session->getShop(), $session->getAccessToken());

                $this->info('🚀 Starting Shopify product description update...');

                if ($this->option('dry-run')) {
                    $this->warn('🔍 DRY RUN MODE - No changes will be made');
                }

                // Load or reset progress
                if ($this->option('reset')) {
                    $this->info('🔄 Resetting progress...');
                    $this->resetProgress();
                } else {
                    $this->loadProgress();
                    if ($this->progress['stats']['total_processed'] > 0) {
                        $this->info('📊 Resuming from previous progress:');
                        $this->info('   - Total processed: '.$this->progress['stats']['total_processed']);
                        $this->info('   - Updated: '.$this->progress['stats']['updated']);
                        $this->info('   - Skipped: '.$this->progress['stats']['skipped']);
                    }
                }

                // Process products
                $this->processProducts();

                // Display final statistics
                $this->displayStatistics();

                $job->update(['status' => 0, 'message' => 'Completed successfully']);
                Log::info("$marketplace $jobType finished!");

            } catch (\Exception $e) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                report($e);
                $this->error('❌ Error: '.$e->getMessage());
                Log::error("$marketplace $jobType failed: ".$e->getMessage(), ['exception' => $e]);

                // Save progress even on error
                $this->saveProgress();
            }
        } else {
            Log::info("$marketplace $jobType is already running.");
            $this->warn('⚠️ Job is already running.');
        }
    }

    /**
     * Process products using GraphQL with pagination
     */
    private function processProducts(): void
    {
        $hasNextPage = true;
        $cursor = $this->progress['last_cursor'];
        $pageNumber = 0;

        while ($hasNextPage) {
            $pageNumber++;
            $this->info("📦 Fetching page {$pageNumber} of products...");

            try {
                // GraphQL query to get products with their descriptions
                $query = <<<'GRAPHQL'
                query getProducts($first: Int!, $after: String) {
                  products(first: $first, after: $after) {
                    edges {
                      node {
                        id
                        title
                        descriptionHtml
                        vendor
                        handle
                        variants(first: 1) {
                          edges {
                            node {
                              sku
                            }
                          }
                        }
                      }
                    }
                    pageInfo {
                      hasNextPage
                      endCursor
                    }
                  }
                }
                GRAPHQL;

                $variables = [
                    'first' => 50, // Smaller batch size for descriptions
                    'after' => $cursor,
                ];

                $response = $this->client->query(['query' => $query, 'variables' => $variables]);
                $resultBody = json_decode($response->getBody()->getContents(), true);

                if (isset($resultBody['errors'])) {
                    throw new \Exception('GraphQL errors: '.json_encode($resultBody['errors']));
                }

                $products = $resultBody['data']['products']['edges'] ?? [];

                foreach ($products as $edge) {
                    $product = $edge['node'];
                    $this->processProduct($product);
                }

                // Update pagination info
                $pageInfo = $resultBody['data']['products']['pageInfo'] ?? [];
                $hasNextPage = $pageInfo['hasNextPage'] ?? false;
                $cursor = $pageInfo['endCursor'] ?? null;

                // Update progress
                $this->progress['last_cursor'] = $cursor;
                $this->saveProgress();

                // Rate limiting delay
                if ($hasNextPage) {
                    usleep(1000000); // 1 second delay between pages
                }

            } catch (\Exception $e) {
                $this->error('❌ Failed to fetch products: '.$e->getMessage());
                $this->progress['stats']['errors']++;
                throw $e;
            }
        }
    }

    /**
     * Process individual product
     */
    private function processProduct(array $product): void
    {
        $productId = $product['id'];
        $productGidNumber = str_replace('gid://shopify/Product/', '', $productId);

        // Skip if already processed
        if (in_array($productGidNumber, $this->progress['processed_product_ids'])) {
            return;
        }

        $this->progress['stats']['total_processed']++;

        try {
            // Get SKU from first variant
            $sku = $product['variants']['edges'][0]['node']['sku'] ?? null;

            if (empty($sku)) {
                $this->line("   ⚠️ Skipping product '{$product['title']}' - No SKU found");
                $this->progress['stats']['skipped']++;
                $this->progress['processed_product_ids'][] = $productGidNumber;

                return;
            }

            // Find RetailEdgeProduct by SKU
            $retailEdgeProduct = RetailEdgeProduct::where('sku', $sku)->first();

            if (! $retailEdgeProduct) {
                $this->line("   ⚠️ Skipping product '{$product['title']}' - RetailEdgeProduct not found for SKU: {$sku}");
                $this->progress['stats']['skipped']++;
                $this->progress['processed_product_ids'][] = $productGidNumber;

                return;
            }

            // Skip Pandora products (they already have design numbers)
            if ($product['vendor'] === 'Pandora') {
                $this->line("   ⏭️ Skipping Pandora product: {$product['title']}");
                $this->progress['stats']['pandora_skipped']++;
                $this->progress['processed_product_ids'][] = $productGidNumber;

                return;
            }

            // Check if description already contains design number
            $currentDescription = $product['descriptionHtml'] ?? '';
            $designNumber = $retailEdgeProduct->real_design_number ?? '';

            if (empty($designNumber)) {
                $this->line("   ⚠️ Skipping product '{$product['title']}' - No design number available");
                $this->progress['stats']['skipped']++;
                $this->progress['processed_product_ids'][] = $productGidNumber;

                return;
            }

            // Check if description already ends with design number
            $designNumberPattern = 'Design number: '.$designNumber;
            if (str_contains($currentDescription, $designNumberPattern)) {
                $this->line("   ✅ Product '{$product['title']}' already has design number");
                $this->progress['stats']['already_has_design_number']++;
                $this->progress['processed_product_ids'][] = $productGidNumber;

                return;
            }

            // Build new description
            $newDescription = $this->buildProductDescription($retailEdgeProduct);

            // Update product description
            if (! $this->option('dry-run')) {
                $this->updateProductDescription($productId, $newDescription, $product['title']);
                $this->info("   ✨ Updated: {$product['title']} - Added design number: {$designNumber}");
            } else {
                $this->info("   [DRY RUN] Would update: {$product['title']} - Add design number: {$designNumber}");
            }

            $this->progress['stats']['updated']++;
            $this->progress['processed_product_ids'][] = $productGidNumber;

        } catch (\Exception $e) {
            $this->error("   ❌ Failed to process product '{$product['title']}': ".$e->getMessage());
            $this->progress['stats']['errors']++;
            $this->progress['processed_product_ids'][] = $productGidNumber;
        }
    }

    /**
     * Update product description using GraphQL
     */
    private function updateProductDescription(string $productId, string $description, string $title): void
    {
        $mutation = <<<'GRAPHQL'
        mutation productUpdate($input: ProductInput!) {
          productUpdate(input: $input) {
            product {
              id
              title
              descriptionHtml
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        $variables = [
            'input' => [
                'id' => $productId,
                'descriptionHtml' => $description,
            ],
        ];

        try {
            $response = $this->client->query(['query' => $mutation, 'variables' => $variables]);
            $resultBody = json_decode($response->getBody()->getContents(), true);

            $userErrors = $resultBody['data']['productUpdate']['userErrors'] ?? [];
            if (! empty($userErrors)) {
                $errorMessages = array_map(fn ($e) => $e['message'], $userErrors);
                throw new \Exception('GraphQL errors: '.implode(', ', $errorMessages));
            }

            // Small delay after successful update
            usleep(500000); // 0.5 second delay

        } catch (\Exception $e) {
            throw new \Exception('Failed to update product description: '.$e->getMessage());
        }
    }

    /**
     * Build product description with design number
     * Matches the logic from CreateProduct
     */
    private function buildProductDescription(RetailEdgeProduct $product): string
    {
        $mktDescription = $product->marketing_description ?? '';

        // Add design number to all products (not just Pandora)
        if (! empty($product->real_design_number)) {
            $mktDescription .= ' - Design number: '.$product->real_design_number;
        }

        return $mktDescription;
    }

    /**
     * Load progress from file
     */
    private function loadProgress(): void
    {
        if (Storage::exists($this->progressFile)) {
            $data = Storage::get($this->progressFile);
            $this->progress = json_decode($data, true);
            $this->info('📂 Loaded progress from previous run');
        }
    }

    /**
     * Save progress to file
     */
    private function saveProgress(): void
    {
        Storage::put($this->progressFile, json_encode($this->progress, JSON_PRETTY_PRINT));
    }

    /**
     * Reset progress
     */
    private function resetProgress(): void
    {
        $this->progress = [
            'processed_product_ids' => [],
            'stats' => [
                'total_processed' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'pandora_skipped' => 0,
                'already_has_design_number' => 0,
            ],
            'last_cursor' => null,
        ];

        if (Storage::exists($this->progressFile)) {
            Storage::delete($this->progressFile);
        }
    }

    /**
     * Display final statistics
     */
    private function displayStatistics(): void
    {
        $this->info('');
        $this->info('📊 Final Statistics:');
        $this->info('====================');
        $this->info('Total Processed: '.$this->progress['stats']['total_processed']);
        $this->info('Updated: '.$this->progress['stats']['updated']);
        $this->info('Pandora Skipped: '.$this->progress['stats']['pandora_skipped']);
        $this->info('Already Had Design Number: '.$this->progress['stats']['already_has_design_number']);
        $this->info('Other Skipped: '.$this->progress['stats']['skipped']);
        $this->info('Errors: '.$this->progress['stats']['errors']);

        if ($this->option('dry-run')) {
            $this->warn('');
            $this->warn('🔍 This was a DRY RUN - no actual changes were made');
        }
    }
}
