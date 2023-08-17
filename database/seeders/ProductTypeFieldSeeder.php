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

        $necklace = ProductType::where(['name' => 'Necklace', 'category_id' => $category->id])->first();
        $earring = ProductType::where(['name' => 'Earring', 'category_id' => $category->id])->first();
        $ring = ProductType::where(['name' => 'Ring', 'category_id' => $category->id])->first();
        $bracelet = ProductType::where(['name' => 'Bracelet', 'category_id' => $category->id])->first();
        $watch = ProductType::where(['name' => 'Watch', 'category_id' => $category->id])->first();

        $fields = [
            [
                'product_type_id' => $necklace->id,
                'amz_name' => 'GemType',
                'e_web_name' => 'SStoneType',
            ],
            [
                'product_type_id' => $necklace->id,
                'amz_name' => 'SupplierDeclaredMaterialRegulation',
                'e_web_name' => 'SupplierDeclaredMaterialRegulation',
            ],
            [
                'product_type_id' => $necklace->id,
                'amz_name' => 'TargetGender',
                'e_web_name' => 'TargetGender',
            ],

            [
                'product_type_id' => $earring->id,
                'amz_name' => 'GemType',
                'e_web_name' => 'SStoneType',
            ],
            [
                'product_type_id' => $earring->id,
                'amz_name' => 'SupplierDeclaredMaterialRegulation',
                'e_web_name' => 'SupplierDeclaredMaterialRegulation',
            ],
            [
                'product_type_id' => $earring->id,
                'amz_name' => 'TargetGender',
                'e_web_name' => 'TargetGender',
            ],

            [
                'product_type_id' => $ring->id,
                'amz_name' => 'GemType',
                'e_web_name' => 'SStoneType',
            ],
            [
                'product_type_id' => $ring->id,
                'amz_name' => 'RingSize',
                'e_web_name' => 'RingSize',
            ],
            [
                'product_type_id' => $ring->id,
                'amz_name' => 'SupplierDeclaredMaterialRegulation',
                'e_web_name' => 'SupplierDeclaredMaterialRegulation',
            ],
            [
                'product_type_id' => $ring->id,
                'amz_name' => 'TargetGender',
                'e_web_name' => 'TargetGender',
            ],

            [
                'product_type_id' => $bracelet->id,
                'amz_name' => 'GemType',
                'e_web_name' => 'SStoneType',
            ],
            [
                'product_type_id' => $bracelet->id,
                'amz_name' => 'SupplierDeclaredMaterialRegulation',
                'e_web_name' => 'SupplierDeclaredMaterialRegulation',
            ],
            [
                'product_type_id' => $bracelet->id,
                'amz_name' => 'TargetGender',
                'e_web_name' => 'TargetGender',
            ],
            [
                'product_type_id' => $watch->id,
                'amz_name' => 'MovementType',
                'e_web_name' => 'MovementType',
            ],
            [
                'product_type_id' => $watch->id,
                'amz_name' => 'TargetGender',
                'e_web_name' => 'TargetGender',
            ],
            [
                'product_type_id' => $watch->id,
                'amz_name' => 'AgeRangeDescription',
                'e_web_name' => 'AgeRangeDescription',
            ],
            [
                'product_type_id' => $watch->id,
                'amz_name' => 'SupplierDeclaredMaterialRegulation',
                'e_web_name' => 'SupplierDeclaredMaterialRegulation',
            ],
            [
                'product_type_id' => $watch->id,
                'amz_name' => 'WaterResistanceLevel',
                'e_web_name' => 'WaterResistanceLevel',
            ],

        ];

        foreach ($fields as $field) {
            ProductTypeField::firstOrCreate($field);
        }
    }
}
