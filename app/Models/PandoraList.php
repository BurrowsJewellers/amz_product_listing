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
        'product_name',
        'product_description',
        'product_url',
        'product_response',
        'search_response',
        'discontinued',
        'images',
    ];

    public function retailEdgeProduct()
    {
        return $this->belongsTo(RetailEdgeProduct::class, 'design_no', 'real_design_number');
    }
}
