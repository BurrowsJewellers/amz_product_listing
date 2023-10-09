<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Marketplace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $marketplace = Marketplace::where('name', 'Amazon')->first();
        Category::updateOrCreate(['name' => 'Jewelry', 'marketplace_id' => $marketplace->id]);

        $marketplace = Marketplace::where('name', 'Catch')->first();
        Category::updateOrCreate(['name' => 'Jewelry', 'marketplace_id' => $marketplace->id]);

    }
}
