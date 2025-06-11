<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyProductMetafield extends Model
{
    use HasFactory;

    protected $table = 'shopify_product_metafields';

    protected $fillable = [
        'product_sku',
        'shopify_metafield_id',
        'value',
    ];

    /**
     * Get the metafield definition associated with this product metafield.
     */
    public function metafieldDefinition()
    {
        return $this->belongsTo(\App\Models\ShopifyMetafield::class, 'shopify_metafield_id');
    }

    /**
     * Get the shopify product associated with this product metafield.
     * Note: This assumes you have a ShopifyProduct model with 'sku' as a findable attribute.
     */
    public function shopifyProduct()
    {
        return $this->belongsTo(\App\Models\ShopifyProduct::class, 'product_sku', 'sku');
    }
}
