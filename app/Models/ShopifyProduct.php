<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShopifyProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'title',
        'vendor',
        'product_type',
        'handle',
        'tags',
        'status',
    ];

    public function variants()
    {
        return $this->hasMany(ShopifyProductVariant::class);
    }
}
