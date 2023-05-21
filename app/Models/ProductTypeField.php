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
    ];

    public function productType() {
        return $this->belongsTo(ProductType::class);
    }

}
