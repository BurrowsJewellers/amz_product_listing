<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductTypeField extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_type_id',
        'amz_name',
        'e_web_name',
        'sort_order',
    ];

    public function productType() {
        return $this->belongsTo(ProductType::class);
    }

    public function value() {
        return $this->hasOne(ProductFieldValue::class, 'product_type_field_id', 'id')->whereNotNull('product_type_field_id');
    }


}
