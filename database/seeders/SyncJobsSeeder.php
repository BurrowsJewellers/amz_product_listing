<?php

namespace Database\Seeders;

use App\Models\SyncJob;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
                'type' => 'getProductsFromEWeb',
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
                'marketplace' => 'EWeb',
                'type' => 'getImagesFromEWeb',
            ],

        ];

        foreach ($jobs as $job) {
            SyncJob::updateOrCreate($job);
        }

    }
}
