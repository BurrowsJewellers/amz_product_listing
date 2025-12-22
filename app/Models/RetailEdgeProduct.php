<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RetailEdgeProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'title',
        'marketing_description',
        'brand_id',
        'barcode',
        'retail_price1',
        'retail_price2',
        'price',
        'compare_at_price',
        'special_price',
        'special_price_start',
        'special_price_end',
        'catalogue_name',
        'catalogue_price',
        'catalogue_price_start',
        'catalogue_price_end',
        'quantity',
        'id1',
        'id2',
        'id3',
        'id4',
        'old_key',
        'is_valid_child',
        'real_design_number',
        'pendant_style',
        'metal_colour',
        's_web_menu',
        's_metal_type',
        's_stone_type',
        's_cat',
        's_sub_cat',
        'ring_size',
        'bracelet_length',
        'web_option_boolean1',
        'web_option_boolean2',
        'web_option_boolean3',
        'web_option_boolean4',
        'web_option_boolean5',
        'web_option_boolean6',
        'web_option_boolean7',
        'web_option_boolean8',
        'uploaded_to_shopify',
        'uploaded_to_catch',
        'uploaded_to_amazon',
        'update_date_time',
    ];

    public function children(): HasMany
    {
        // Exclude self-referencing parent (where old_key = sku)
        // Only return true children where old_key points to this product's SKU
        return $this->hasMany(RetailEdgeProduct::class, 'old_key', 'sku')
            ->whereRaw('old_key != sku');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(RetailEdgeProduct::class, 'old_key', 'sku');
    }

    public function images(): HasMany
    {
        return $this->hasMany(RetailEdgeProductImage::class, 'sku', 'sku');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id', 'brand_id');
    }

    public function pandoraScraped(): HasOne
    {
        return $this->hasOne(PandoraList::class, 'design_no', 'real_design_number');
    }
}
