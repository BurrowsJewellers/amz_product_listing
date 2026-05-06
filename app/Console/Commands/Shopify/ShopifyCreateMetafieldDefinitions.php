<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\RetailEdgeProductIsd;
use App\Models\ShopifyMetafield;
use App\Services\ShopifyConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Shopify\Clients\Graphql;

class ShopifyCreateMetafieldDefinitions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:create-metafield-definitions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates Shopify metafield definitions based on RetailEdgeProductIsd entries and stores them in the database.';

    protected ShopifyConnectionService $shopifyConnectionService;

    public function __construct(ShopifyConnectionService $shopifyConnectionService)
    {
        parent::__construct();
        $this->shopifyConnectionService = $shopifyConnectionService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopify:create-metafield-definitions';

        // Acquire lock using locking system
        $job = SyncJobController::acquireLock($jobType, $marketplace);
        if (! $job) {
            $this->warn('Job is already running or paused.');
            Log::info("$marketplace $jobType: Cannot acquire lock (running or paused)");

            return Command::SUCCESS;
        }

        try {
            Log::info("$marketplace $jobType started!");

            $this->info('Starting Shopify metafield definition creation...');

            $isdNames = RetailEdgeProductIsd::query()
                ->select('isd_name')
                ->distinct()
                ->pluck('isd_name');

            if ($isdNames->isEmpty()) {
                $this->info('No ISD names found in retail_edge_product_isds table. Exiting.');

                $job->finishJob();
                Log::info("$marketplace $jobType finished - no ISD names to process.");

                return Command::SUCCESS;
            }

            $this->info("Found {$isdNames->count()} unique ISD names to process.");

            $defaultNamespace = config('shopify.metafield_namespace', 'custom'); // Assuming you might want to configure this
            $defaultType = 'multi_line_text_field'; // As per your requirement
            $defaultOwnerType = 'PRODUCTVARIANT'; // As per your requirement

            foreach ($isdNames as $isdName) {
                if (empty(trim($isdName))) {
                    $this->warn('Skipping empty ISD name.');

                    continue;
                }

                $this->info("Processing ISD Name: {$isdName}");

                // Generate base key from the name (e.g., "My Custom Field" -> "my_custom_field")
                $baseKey = Str::snake(strtolower(str_replace(' ', '_', $isdName)));

                // Create both PRODUCT and PRODUCTVARIANT definitions
                $ownerTypes = ['PRODUCT', 'PRODUCTVARIANT'];

                foreach ($ownerTypes as $ownerType) {
                    $key = $baseKey.'_'.strtolower($ownerType === 'PRODUCT' ? 'product' : 'variant');

                    $existingMetafield = ShopifyMetafield::where('name', $isdName)
                        ->where('owner_type', $ownerType)
                        ->first();

                    if ($existingMetafield) {
                        $this->line("Metafield definition '{$isdName}' ({$ownerType}) already exists with GID: {$existingMetafield->gid}. Skipping creation.");

                        continue;
                    }

                    $mutation = <<<'GRAPHQL'
                mutation CreateMetafieldDefinition($definition: MetafieldDefinitionInput!) {
                  metafieldDefinitionCreate(definition: $definition) {
                    createdDefinition {
                      id
                      name
                      namespace
                      key
                      type {
                        name
                      }
                      ownerType
                    }
                    userErrors {
                      field
                      message
                    }
                  }
                }
                GRAPHQL;

                    $variables = [
                        'definition' => [
                            'name' => $isdName,
                            'namespace' => $defaultNamespace,
                            'key' => $key,
                            'type' => $defaultType,
                            'ownerType' => $ownerType,
                            'description' => "Metafield for {$isdName} ({$ownerType})", // Optional
                            // 'validations' => [], // Optional
                        ],
                    ];

                    try {
                        $this->line("Attempting to create metafield definition for: {$isdName} ({$ownerType})");
                        $session = $this->shopifyConnectionService->getSession();
                        $client = new Graphql($session->getShop(), $session->getAccessToken());
                        $response = $client->query(['query' => $mutation, 'variables' => $variables]);

                        $resultBody = $response->getBody()->getContents(); // Ensure we get string content
                        $resultBody = json_decode($resultBody, true); // Decode JSON string to array
                        $result = $resultBody['data']['metafieldDefinitionCreate'] ?? null;
                        $errors = $resultBody['errors'] ?? ($result['userErrors'] ?? []);

                        if (! empty($errors)) {
                            foreach ($errors as $error) {
                                $errorMessage = $error['message'] ?? 'Unknown error';
                                $errorField = isset($error['field']) ? implode(', ', $error['field']) : 'N/A';
                                $this->error("Shopify API Error for '{$isdName}' ({$ownerType}): {$errorMessage} (Field: {$errorField})");
                                Log::error("Shopify API Error for metafield '{$isdName}' ({$ownerType}): ".json_encode($error));
                            }

                            continue;
                        }

                        if (isset($result['createdDefinition']) && $result['createdDefinition']) {
                            $createdDefinition = $result['createdDefinition'];
                            $shopifyMetafield = ShopifyMetafield::create([
                                'name' => $createdDefinition['name'],
                                'namespace' => $createdDefinition['namespace'],
                                'key' => $createdDefinition['key'],
                                'type' => $createdDefinition['type']['name'], // Type is an object
                                'owner_type' => $createdDefinition['ownerType'],
                                'gid' => $createdDefinition['id'],
                            ]);
                            $this->info("Successfully created and saved metafield definition '{$shopifyMetafield->name}' ({$ownerType}) with GID: {$shopifyMetafield->gid}");
                        } else {
                            $this->error("Failed to create metafield definition for '{$isdName}' ({$ownerType}). Response: ".json_encode($resultBody));
                            Log::error("Failed to create Shopify metafield '{$isdName}' ({$ownerType}). Response: ".json_encode($resultBody));
                        }
                    } catch (\Exception $e) {
                        $this->error("Exception while creating metafield '{$isdName}' ({$ownerType}): ".$e->getMessage());
                        Log::error("Exception for Shopify metafield '{$isdName}' ({$ownerType}): ".$e->getMessage(), ['exception' => $e]);
                    }
                    // Add a small delay to avoid hitting API rate limits too quickly
                    sleep(1);
                }
            }

            $this->createSyntheticDefinition(
                name: 'Design Number',
                namespace: $defaultNamespace,
                key: 'design_number_variant',
                type: 'single_line_text_field',
                ownerType: 'PRODUCTVARIANT',
            );

            $this->info('Shopify metafield definition creation process finished.');

            $job->finishJob();
            Log::info("$marketplace $jobType finished!");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $job->finishJob($e->getMessage());
            report($e);
            $this->error($e->getMessage());
            Log::error("$marketplace $jobType failed: ".$e->getMessage());

            return Command::FAILURE;
        }
    }

    private function createSyntheticDefinition(
        string $name,
        string $namespace,
        string $key,
        string $type,
        string $ownerType,
    ): void {
        $existing = ShopifyMetafield::where('namespace', $namespace)
            ->where('key', $key)
            ->where('owner_type', $ownerType)
            ->first();

        if ($existing) {
            $this->line("Synthetic metafield definition '{$name}' ({$ownerType}) already exists with GID: {$existing->gid}. Skipping.");

            return;
        }

        $mutation = <<<'GRAPHQL'
        mutation CreateMetafieldDefinition($definition: MetafieldDefinitionInput!) {
          metafieldDefinitionCreate(definition: $definition) {
            createdDefinition { id name namespace key type { name } ownerType }
            userErrors { field message }
          }
        }
        GRAPHQL;

        $variables = [
            'definition' => [
                'name' => $name,
                'namespace' => $namespace,
                'key' => $key,
                'type' => $type,
                'ownerType' => $ownerType,
                'description' => "Metafield for {$name} ({$ownerType})",
            ],
        ];

        try {
            $session = $this->shopifyConnectionService->getSession();
            $client = new Graphql($session->getShop(), $session->getAccessToken());
            $response = $client->query(['query' => $mutation, 'variables' => $variables]);

            $resultBody = json_decode($response->getBody()->getContents(), true);
            $result = $resultBody['data']['metafieldDefinitionCreate'] ?? null;
            $errors = $resultBody['errors'] ?? ($result['userErrors'] ?? []);

            if (! empty($errors)) {
                foreach ($errors as $error) {
                    $this->error("Shopify API Error for '{$name}' ({$ownerType}): ".($error['message'] ?? 'Unknown error'));
                    Log::error("Shopify API Error for synthetic metafield '{$name}' ({$ownerType}): ".json_encode($error));
                }

                return;
            }

            if (! empty($result['createdDefinition'])) {
                $created = $result['createdDefinition'];
                $row = ShopifyMetafield::create([
                    'name' => $created['name'],
                    'namespace' => $created['namespace'],
                    'key' => $created['key'],
                    'type' => $created['type']['name'],
                    'owner_type' => $created['ownerType'],
                    'gid' => $created['id'],
                ]);
                $this->info("Successfully created synthetic metafield '{$row->name}' ({$ownerType}) with GID: {$row->gid}");
            }
        } catch (\Exception $e) {
            $this->error("Exception while creating synthetic metafield '{$name}' ({$ownerType}): ".$e->getMessage());
            Log::error("Exception for synthetic metafield '{$name}' ({$ownerType}): ".$e->getMessage(), ['exception' => $e]);
        }
    }
}
