<?php

namespace App\Console\Commands\Shopify;

use App\Models\RetailEdgeProductIsd;
use App\Models\ShopifyMetafield;
use App\Services\ShopifyConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Shopify\Clients\Graphql; // Added this line

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
        $this->info('Starting Shopify metafield definition creation...');

        $isdNames = RetailEdgeProductIsd::query()
            ->select('isd_name')
            ->distinct()
            ->pluck('isd_name');

        if ($isdNames->isEmpty()) {
            $this->info('No ISD names found in retail_edge_product_isds table. Exiting.');
            return 0;
        }

        $this->info("Found {$isdNames->count()} unique ISD names to process.");

        $defaultNamespace = config('shopify.metafield_namespace', 'custom'); // Assuming you might want to configure this
        $defaultType = 'multi_line_text_field'; // As per your requirement
        $defaultOwnerType = 'PRODUCTVARIANT'; // As per your requirement

        foreach ($isdNames as $isdName) {
            if (empty(trim($isdName))) {
                $this->warn("Skipping empty ISD name.");
                continue;
            }

            $this->info("Processing ISD Name: {$isdName}");

            $existingMetafield = ShopifyMetafield::where('name', $isdName)->first();

            if ($existingMetafield) {
                $this->line("Metafield definition '{$isdName}' already exists with GID: {$existingMetafield->gid}. Skipping creation.");
                continue;
            }

            // Generate a key from the name (e.g., "My Custom Field" -> "my_custom_field")
            $key = Str::snake(strtolower(str_replace(' ', '_', $isdName)));

            $mutation = <<<GRAPHQL
            mutation CreateMetafieldDefinition(\$definition: MetafieldDefinitionInput!) {
              metafieldDefinitionCreate(definition: \$definition) {
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
                    'ownerType' => $defaultOwnerType,
                    'description' => "Metafield for {$isdName}", // Optional
                    // 'validations' => [], // Optional
                ]
            ];

            try {
                $this->line("Attempting to create metafield definition for: {$isdName}");
                $session = $this->shopifyConnectionService->getSession();
                $client = new Graphql($session->getShop(), $session->getAccessToken());
                $response = $client->query(['query' => $mutation, 'variables' => $variables]);

                $resultBody = $response->getBody()->getContents(); // Ensure we get string content
                $resultBody = json_decode($resultBody, true); // Decode JSON string to array
                $result = $resultBody['data']['metafieldDefinitionCreate'] ?? null;
                $errors = $resultBody['errors'] ?? ($result['userErrors'] ?? []);

                if (!empty($errors)) {
                    foreach ($errors as $error) {
                        $errorMessage = $error['message'] ?? 'Unknown error';
                        $errorField = isset($error['field']) ? implode(', ', $error['field']) : 'N/A';
                        $this->error("Shopify API Error for '{$isdName}': {$errorMessage} (Field: {$errorField})");
                        Log::error("Shopify API Error for metafield '{$isdName}': " . json_encode($error));
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
                    $this->info("Successfully created and saved metafield definition '{$shopifyMetafield->name}' with GID: {$shopifyMetafield->gid}");
                } else {
                    $this->error("Failed to create metafield definition for '{$isdName}'. Response: " . json_encode($resultBody));
                    Log::error("Failed to create Shopify metafield '{$isdName}'. Response: " . json_encode($resultBody));
                }
            } catch (\Exception $e) {
                $this->error("Exception while creating metafield '{$isdName}': " . $e->getMessage());
                Log::error("Exception for Shopify metafield '{$isdName}': " . $e->getMessage(), ['exception' => $e]);
            }
            // Add a small delay to avoid hitting API rate limits too quickly
            sleep(1);
        }

        $this->info('Shopify metafield definition creation process finished.');
        return 0;
    }
}
