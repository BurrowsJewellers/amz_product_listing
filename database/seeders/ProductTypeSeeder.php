<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Marketplace;
use App\Models\ProductType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $marketplace = Marketplace::where('name', 'Amazon')->first();
        $category = Category::where(['name' => 'Jewelry', 'marketplace_id' => $marketplace->id])->first();
        ProductType::updateOrCreate(['name' => 'Necklace', 'category_id' => $category->id],['amz_recommended_browse_node' => '5131129051']);
        ProductType::updateOrCreate(['name' => 'Earring', 'category_id' => $category->id],['amz_recommended_browse_node' => '5131126051']);
    }
}
