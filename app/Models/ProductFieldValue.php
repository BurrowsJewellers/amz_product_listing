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

}
