<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Marketplace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FieldMappingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $amazon = Marketplace::where('name', 'Amazon')->first();
        $category = Category::where('name', 'Necklace');
    }
}
