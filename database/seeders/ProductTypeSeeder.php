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

        ProductType::updateOrCreate(['name' => 'Watch', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'FashionNecklaceBraceletAnklet', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'FashionRing', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'FashionEarring', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'FashionOther', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'FineNecklaceBraceletAnklet', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'FineRing', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'FineEarring', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'FineOther', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'ApparelPin', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'Necklace', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'Earring', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'WatchBand', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'Ring', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'JewelrySet', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'PiercingJewelry', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'LooseCutGem', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'CuffLink', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'Bracelet', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'Charm', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'FashionJewelry', 'category_id' => $category->id]);
        ProductType::updateOrCreate(['name' => 'FineJewelry', 'category_id' => $category->id]);
    }
}
