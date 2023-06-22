<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category_id',
        'amz_recommended_browse_node',
    ];

    public function fields(){
        return $this->hasMany(ProductTypeField::class);
    }

}
