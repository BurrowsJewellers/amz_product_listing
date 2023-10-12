<?php

namespace App\Models\Catch;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatchProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'catch_product_id',
        'e_web_index',
        'width',
        'height',
        'url',
    ];

}
