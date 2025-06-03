<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetailEdgeProductIsd extends Model
{
    use HasFactory;

    protected $table = 'retail_edge_product_isds';

    protected $primaryKey = ['sku', 'isd_index']; // For composite primary keys

    public $incrementing = false; // Since 'sku' is not auto-incrementing

    protected $fillable = [
        'sku',
        'isd_index',
        'isd_name',
        'isd_value',
    ];

    /**
     * Get the retail edge product that owns the ISD.
     */
    public function retailEdgeProduct(): BelongsTo
    {
        return $this->belongsTo(RetailEdgeProduct::class, 'sku', 'sku');
    }
}
