<?php

namespace Database\Seeders;

use App\Models\Marketplace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MarketplaceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Marketplace::updateOrCreate(['name' => 'Amazon']);
        Marketplace::updateOrCreate(['name' => 'Catch']);
    }
}
