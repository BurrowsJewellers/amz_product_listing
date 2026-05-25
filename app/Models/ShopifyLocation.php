<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'name',
        'address1',
        'address2',
        'city',
        'zip',
        'province',
        'country',
        'phone',
        'country_code',
        'country_name',
        'province_code',
        'active',
    ];

    /**
     * Get the inventory levels for this location
     */
    public function inventoryLevels()
    {
        return $this->hasMany(ShopifyInventoryLevel::class, 'location_id', 'location_id');
    }
}
