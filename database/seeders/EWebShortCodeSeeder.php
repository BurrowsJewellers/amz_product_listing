<?php

namespace Database\Seeders;

use App\Models\EWebShortCode;
use Illuminate\Database\Seeder;

class EWebShortCodeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $codes = [
            [
                'code' => 'AWWA',
                'marketplace_id' => 1, // Amazon
                'product_type_id' => 1, // Watch
                'amz_recommended_browse_node' => '5131150051',
                'button_cell' => 1,
                'classification_path' => 'Clothing, Shoes & Accessories/Women/Watches/Wrist Watches',
            ],

            [
                'code' => 'AMWA',
                'marketplace_id' => 1, // Amazon
                'product_type_id' => 1, // Watch
                'amz_recommended_browse_node' => '5130995051',
                'button_cell' => 1,
                'classification_path' => 'Clothing, Shoes & Accessories/Men/Watches/Wrist Watches',
            ],

            [
                'code' => 'AWRI',
                'marketplace_id' => 1, // Amazon
                'product_type_id' => 14, // Ring
                'amz_recommended_browse_node' => '5131132051',
                'button_cell' => 0,
                'classification_path' => 'Clothing, Shoes & Accessories/Women/Jewellery/Rings',
            ],

            [
                'code' => 'AWNE',
                'marketplace_id' => 1, // Amazon
                'product_type_id' => 11, // Necklace
                'amz_recommended_browse_node' => '5131129051',
                'button_cell' => 0,
                'classification_path' => 'Clothing, Shoes & Accessories/Women/Jewellery/Necklaces',
            ],

            [
                'code' => 'AWBR',
                'marketplace_id' => 1, // Amazon
                'product_type_id' => 19, // Bracelet
                'amz_recommended_browse_node' => '5131122051',
                'button_cell' => 0,
                'classification_path' => 'Clothing, Shoes & Accessories/Women/Jewellery/Bracelets',
            ],

            [
                'code' => 'AWEA',
                'marketplace_id' => 1, // Amazon
                'product_type_id' => 12, // Earring
                'amz_recommended_browse_node' => '5131126051',
                'button_cell' => 0,
                'classification_path' => 'Clothing, Shoes & Accessories/Women/Jewellery/Earrings',
            ],

            [
                'code' => 'AWCC',
                'marketplace_id' => 1, // Amazon
                'product_type_id' => 20, // Charm
                'amz_recommended_browse_node' => '5131913051',
                'button_cell' => 0,
                'classification_path' => 'Clothing, Shoes & Accessories/Women/Jewellery/Charms/Clasp Charms',
            ],
            [
                'code' => 'CWEA',
                'marketplace_id' => 2, // Catch
                'product_type_id' => null,
                'amz_recommended_browse_node' => null,
                'button_cell' => 0,
                'classification_path' => "Jewellery & Accessories/Women's/Jewellery/Earrings",
            ],
            [
                'code' => 'CWBR',
                'marketplace_id' => 2, // Catch
                'product_type_id' => null,
                'amz_recommended_browse_node' => null,
                'button_cell' => 0,
                'classification_path' => "Jewellery & Accessories/Women's/Jewellery/Bracelets & Charms",
            ],
            [
                'code' => 'CWRI',
                'marketplace_id' => 2, // Catch
                'product_type_id' => null,
                'amz_recommended_browse_node' => null,
                'button_cell' => 0,
                'classification_path' => "Jewellery & Accessories/Women's/Jewellery/Rings",
            ],
            [
                'code' => 'CWNE',
                'marketplace_id' => 2, // Catch
                'product_type_id' => null,
                'amz_recommended_browse_node' => null,
                'button_cell' => 0,
                'classification_path' => "Jewellery & Accessories/Women's/Jewellery/Necklaces & Pendants",
            ],
        ];

        foreach($codes as $code) {
            EWebShortCode::updateOrCreate(
                [
                    'code' => $code['code'],
                ],
                [
                    'marketplace_id' => $code['marketplace_id'],
                    'product_type_id' => $code['product_type_id'],
                    'amz_recommended_browse_node' => $code['amz_recommended_browse_node'],
                    'button_cell' => $code['button_cell'],
                    'classification_path' => $code['classification_path'],
                ]
            );
        }

    }
}
