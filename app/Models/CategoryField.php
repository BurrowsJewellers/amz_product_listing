<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryField extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'amz_name',
        'e_web_name',
        'sort_order',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function value()
    {
        return $this->hasOne(ProductFieldValue::class, 'category_field_id', 'id')->whereNull('product_type_field_id');
    }
}
