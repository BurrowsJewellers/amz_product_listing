<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyInventoryLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'inventory_item_id',
        'available',
        'inventory_updated_at',
        'requires_update',
    ];

    /**
     * Get the location for this inventory level
     */
    public function location()
    {
        return $this->belongsTo(ShopifyLocation::class, 'location_id', 'location_id');
    }

    /**
     * Get the variant for this inventory level
     */
    public function variant()
    {
        return $this->belongsTo(ShopifyProductVariant::class, 'inventory_item_id', 'inventory_item_id');
    }
}
