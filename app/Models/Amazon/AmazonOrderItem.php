<?php

namespace App\Models\Amazon;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmazonOrderItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'amazon_order_id',
        'order_item_id',
        'asin',
        'seller_sku',
        'title',
        'quantity_ordered',
        'quantity_shipped',
        'item_price_currency_code',
        'item_price_amount',
        'shipping_price_currency_code',
        'shipping_price_amount',
        'item_tax_currency_code',
        'item_tax_amount',
        'shipping_tax_currency_code',
        'shipping_tax_amount',
        'condition_id',
        'condition_note',
        'is_gift',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'quantity_ordered' => 'integer',
        'quantity_shipped' => 'integer',
        'item_price_amount' => 'decimal:2',
        'shipping_price_amount' => 'decimal:2',
        'item_tax_amount' => 'decimal:2',
        'shipping_tax_amount' => 'decimal:2',
        'is_gift' => 'boolean',
    ];

    /**
     * Get the order that owns this item.
     */
    public function order()
    {
        return $this->belongsTo(AmazonOrder::class, 'amazon_order_id', 'amazon_order_id');
    }
}
