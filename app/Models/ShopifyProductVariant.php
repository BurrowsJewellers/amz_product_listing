<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_product_id',
        'product_id',
        'variant_id',
        'title',
        'price',
        'compare_at_price',
        'sku',
        'old_key',
        'position',
        'inventory_policy',
        'fulfillment_service',
        'inventory_management',
        'option1_type',
        'option1',
        'option2_type',
        'option2',
        'option3_type',
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

    public function product()
    {
        return $this->belongsTo(ShopifyProduct::class);
    }

    public function images()
    {
        return $this->hasMany(RetailEdgeProductImage::class, 'sku', 'sku');
    }
}
