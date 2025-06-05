<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyProductVariantMetafield extends Model // Renamed class
{
    use HasFactory;

    protected $table = 'shopify_product_variant_metafields'; // Renamed table

    protected $fillable = [
        'sku',
        'shopify_metafield_id',
        'value',
    ];

    /**
     * Get the metafield definition associated with this variant metafield.
     */
    public function metafieldDefinition()
    {
        return $this->belongsTo(\App\Models\ShopifyMetafield::class, 'shopify_metafield_id');
    }

    /**
     * Get the shopify product variant associated with this variant metafield.
     * Note: This assumes you have a ShopifyProductVariant model with 'sku' as a findable attribute.
     */
    public function shopifyProductVariant()
    {
        // Assuming App\Models\ShopifyProductVariant exists and 'sku' is a unique/primary key or indexed.
        // Adjust namespace if your ShopifyProductVariant model is located elsewhere.
        return $this->belongsTo(\App\Models\ShopifyProductVariant::class, 'sku', 'sku');
    }
}
