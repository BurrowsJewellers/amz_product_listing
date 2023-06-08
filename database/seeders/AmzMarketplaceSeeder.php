<?php

namespace Database\Seeders;

use App\Models\AmzMarketplace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AmzMarketplaceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AmzMarketplace::updateOrCreate([
            'marketplace_id' => 'A39IBJ37TRP1C6',
            'country' => 'Australia',
            'country_code' => 'AU',
            'region' => 'FE',
        ]);
    }
}
