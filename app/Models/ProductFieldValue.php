<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductFieldValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'category_id',
        'category_field_id',
        'product_type_id',
        'product_type_field_id',
        'value',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function productType()
    {
        return $this->belongsTo(ProductType::class);
    }

    public function categoryField()
    {
        return $this->belongsTo(CategoryField::class, 'category_field_id', 'id')->orderBy('sort_order', 'asc');
    }

    public function productTypeField()
    {
        return $this->belongsTo(ProductTypeField::class, 'product_type_field_id', 'id')->orderBy('sort_order', 'asc');
    }
}
