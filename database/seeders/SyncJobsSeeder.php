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
            [
                'marketplace' => 'EWeb',
                'type' => 'getBrandsFromEWeb',
            ],
            [
                'marketplace' => 'EWeb',
                'type' => 'getProductsFromEWeb',
            ],
            [
                'marketplace' => 'EWeb',
                'type' => 'getImagesFromEWeb',
            ],
            [
                'marketplace' => 'EWeb',
                'type' => 'getInventoryFromEWeb',
            ],
            [
                'marketplace' => 'Amazon',
                'type' => 'generateAmzProductsXml',
            ],
            [
                'marketplace' => 'Amazon',
                'type' => 'generateAmzInventoryXml',
            ],
            [
                'marketplace' => 'Amazon',
                'type' => 'generateAmzImagesXml',
            ],
            [
                'marketplace' => 'Amazon',
                'type' => 'generateAmzPriceXml',
            ],
            [
                'marketplace' => 'Amazon',
                'type' => 'checkAmzFeedStatus',
            ],
            [
                'marketplace' => 'Amazon',
                'type' => 'getAmzMerchantListingAllData',
            ],
            [
                'marketplace' => 'Amazon',
                'type' => 'processAmzMerchantListingAllData',
            ],
        ];

        foreach ($jobs as $job) {
            SyncJob::updateOrCreate($job);
        }
    }
}
