<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Marketplace;
use App\Models\ProductType;
use App\Models\ProductTypeField;

class ProductTypeFieldSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $marketplace = Marketplace::where('name', 'Amazon')->first();
        $category = Category::where(['name' => 'Jewelry', 'marketplace_id' => $marketplace->id])->first();
        $productType = ProductType::where(['name' => 'Necklace', 'category_id' => $category->id])->first();

        $fields = [
            'GemType',
            'SupplierDeclaredMaterialRegulation',
            'TargetGender',
        ];

        foreach ($fields as $field) {
            ProductTypeField::firstOrCreate(['field_name' => $field, 'product_type_id' => $productType->id]);
        }
    }
}
