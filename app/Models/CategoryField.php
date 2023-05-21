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
    ];

    public function category() {
        return $this->belongsTo(Category::class);
    }

}
