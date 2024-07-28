<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PandoraList extends Model
{
    use HasFactory;

    protected $fillable = [
        'design_no',
        'sku',
        'search_response',
        'product_name',
        'product_url',
        'product_response',
        'discontinued',
        'images',
    ];
}
