<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\CategoryField;
use App\Models\Marketplace;

class CategoryFieldSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $marketplace = Marketplace::where('name', 'Amazon')->first();
        $category = Category::where(['name' => 'Jewelry', 'marketplace_id' => $marketplace->id])->first();

        $fields = [
            [
                'category_id' => $category->id,
                'amz_name' => 'WarrantyType',
                'e_web_name' => 'WarrantyType',
            ],
            [
                'category_id' => $category->id,
                'amz_name' => 'ModelNumber',
                'e_web_name' => 'RealDesignNum',
            ],
            [
                'category_id' => $category->id,
                'amz_name' => 'MetalType',
                'e_web_name' => 'SMetalType',
            ],
        ];

        foreach ($fields as $field) {
            CategoryField::firstOrCreate($field);
        }


    }
}
