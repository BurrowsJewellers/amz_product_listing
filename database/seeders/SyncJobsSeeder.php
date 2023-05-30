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
                'type' => 'generateAmzProductsXml',
                'marketplace' => 'Amazon',
            ],
        ];

        foreach ($jobs as $job) {
            SyncJob::updateOrCreate($job);
        }

    }
}
