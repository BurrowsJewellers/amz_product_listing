<?php

namespace Database\Seeders;

use App\Models\SyncJob;
use Illuminate\Database\Seeder;

class SyncJobsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $jobs = [
            // EWeb Jobs
            ['marketplace' => 'EWeb', 'type' => 'getBrandsFromEWeb', 'timeout_minutes' => 15],
            ['marketplace' => 'EWeb', 'type' => 'getProductsFromEWeb', 'timeout_minutes' => 30],
            ['marketplace' => 'EWeb', 'type' => 'getProductsFromEWebMain', 'timeout_minutes' => 60],
            ['marketplace' => 'EWeb', 'type' => 'getImagesFromEWeb', 'timeout_minutes' => 30],
            ['marketplace' => 'EWeb', 'type' => 'getInventoryFromEWeb', 'timeout_minutes' => 30],

            // Shopify Jobs - Orchestrated
            ['marketplace' => 'Shopify', 'type' => 'shopifyGetProducts', 'timeout_minutes' => 30],
            ['marketplace' => 'Shopify', 'type' => 'shopifyCreateProduct', 'timeout_minutes' => 60],
            ['marketplace' => 'Shopify', 'type' => 'shopifyUploadImages', 'timeout_minutes' => 60],
            ['marketplace' => 'Shopify', 'type' => 'shopifyArchiveProducts', 'timeout_minutes' => 30],

            // Shopify Jobs - Price & Inventory
            ['marketplace' => 'Shopify', 'type' => 'shopify:update-price-inventory-batch', 'timeout_minutes' => 45],
            ['marketplace' => 'Shopify', 'type' => 'shopify:verify-sync-prices', 'timeout_minutes' => 30],
            ['marketplace' => 'Shopify', 'type' => 'shopifyRetryFailedInventoryUpdates', 'timeout_minutes' => 30],

            // Shopify Jobs - Other
            ['marketplace' => 'Shopify', 'type' => 'shopifyCountImages', 'timeout_minutes' => 15],
            ['marketplace' => 'Shopify', 'type' => 'shopify:update-product', 'timeout_minutes' => 60],

            // Amazon Jobs
            ['marketplace' => 'Amazon', 'type' => 'generateAmzProductsJson', 'timeout_minutes' => 45],
            ['marketplace' => 'Amazon', 'type' => 'amazonUpdateInventoryPrice', 'timeout_minutes' => 45],
            ['marketplace' => 'Amazon', 'type' => 'getAmzMerchantListingAllData', 'timeout_minutes' => 30],
            ['marketplace' => 'Amazon', 'type' => 'processAmzMerchantListingAllData', 'timeout_minutes' => 45],
            ['marketplace' => 'Amazon', 'type' => 'checkAmzFeedStatus', 'timeout_minutes' => 15],

            // Legacy Amazon Jobs (deprecated but kept for reference)
            ['marketplace' => 'Amazon', 'type' => 'generateAmzProductsXml', 'timeout_minutes' => 30],
            ['marketplace' => 'Amazon', 'type' => 'generateAmzInventoryXml', 'timeout_minutes' => 30],
            ['marketplace' => 'Amazon', 'type' => 'generateAmzImagesXml', 'timeout_minutes' => 30],
            ['marketplace' => 'Amazon', 'type' => 'generateAmzPriceXml', 'timeout_minutes' => 30],
        ];

        foreach ($jobs as $job) {
            SyncJob::updateOrCreate(
                ['type' => $job['type'], 'marketplace' => $job['marketplace']],
                ['timeout_minutes' => $job['timeout_minutes'] ?? 30]
            );
        }
    }
}
