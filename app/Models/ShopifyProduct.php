<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'title',
        'vendor',
        'product_type',
        'handle',
        'tags',
        'status',
    ];
}
