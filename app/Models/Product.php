<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'title',
        'asin',
        'ean',
        'upc',
        'brand_id',
        'marketplace_id',
        'category_id',
        'product_type_id',
        'description',
        'manufacturer',
        'recommended_browse_nodes',
        'department_name',
        'size_name',
        'country_of_origin',
        'item_type_name',
        'quantity',
        'standard_price',
    ];

    public function fields() {
        return $this->hasMany(ProductFieldValue::class);
    }

}
