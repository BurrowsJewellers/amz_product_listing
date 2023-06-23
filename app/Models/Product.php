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
        'department_name',
        'size_name',
        'country_of_origin',
        'item_type_name',
        'quantity',
        'retail_price',
        'retail_price2',
        'real_design_number',
        'e_web_code',
        'xml_generated',
        'price_feed_status',
        'image_feed_status',
        'inventory_feed_status',
        'submitted',
        'published',
        'update',
        'status',
        'message',
        'amz_feed_id',
    ];

    public function fields() {
        return $this->hasMany(ProductFieldValue::class);
    }

    public function category() {
        return $this->belongsTo(Category::class);
    }

    public function categoryFields() {
        return $this->hasMany(CategoryField::class, 'category_id', 'category_id');
    }

    public function productType() {
        return $this->belongsTo(ProductType::class);
    }

    public function productTypeFields() {
        return $this->hasMany(ProductTypeField::class, 'product_type_id', 'product_type_id');
    }

    public function brand() {
        return $this->belongsTo(Brand::class);
    }

    public function images() {
        return $this->hasMany(ProductImage::class);
    }

    public function eWebCode() {
        return $this->hasOne(EWebShortCode::class, 'code', 'e_web_code');
    }


}
