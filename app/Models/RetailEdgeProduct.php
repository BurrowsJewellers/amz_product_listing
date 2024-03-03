<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RetailEdgeProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'title',
        'marketing_description',
        'brand_id',
        'retail_price1',
        'retail_price2',
        'quantity',
        'id1',
        'id2',
        'id3',
        'id4',
        'old_key',
        'is_valid_child',
        'real_design_number',
        'pendant_style',
        's_web_menu',
        's_metal_type',
        's_stone_type',
        's_cat',
        's_sub_cat',
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
    ];

    public function children(): HasMany
    {
        return $this->hasMany(RetailEdgeProduct::class, 'old_key', 'sku');
    }

    public function images(): HasMany
    {
        return $this->hasMany(RetailEdgeProductImage::class, 'sku', 'sku');
    }
}
