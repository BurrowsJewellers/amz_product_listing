<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EWebShortCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'marketplace_id',
        'product_type_id',
        'amz_recommended_browse_node',
        'button_cell',
        'classification_path',
    ];

    public function productType()
    {
        return $this->belongsTo(ProductType::class);
    }

    public function marketplace()
    {
        return $this->belongsTo(Marketplace::class);
    }
}
