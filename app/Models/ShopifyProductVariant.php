<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_product_table_id',
        'product_id',
        'variant_id',
        'title',
        'price',
        'sku',
        'position',
        'inventory_policy',
        'fulfillment_service',
        'inventory_management',
        'option1',
        'option2',
        'option3',
        'taxable',
        'barcode',
        'grams',
        'weight',
        'inventory_item_id',
        'inventory_quantity',
        'old_inventory_quantity',
        'requires_shipping',
        'requires_update',
    ];
}
