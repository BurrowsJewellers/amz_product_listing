<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductTypeField extends Model
{
    use HasFactory;

    protected $fillable = [
        'field_name',
        'product_type_id',
    ];
}
