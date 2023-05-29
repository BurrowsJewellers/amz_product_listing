<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'marketplace_id',
    ];

    public function fields(){
        return $this->hasMany(CategoryField::class);
    }

    public function productTypes() {
        return $this->hasMany(ProductType::class);
    }

}
