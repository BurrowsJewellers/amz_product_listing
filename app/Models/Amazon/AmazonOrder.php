<?php

namespace App\Models\Amazon;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmazonOrder extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'amazon_order_id',
        'sales_channel',
        'order_status',
        'number_of_items_shipped',
        'order_type',
        'is_premium_order',
        'is_prime',
        'fulfillment_channel',
        'number_of_items_unshipped',
        'has_regulated_items',
        'is_replacement_order',
        'is_sold_by_ab',
        'latest_ship_date',
        'ship_service_level',
        'is_ispu',
        'marketplace_id',
        'purchase_date',
        'shipping_state_or_region',
        'shipping_postal_code',
        'shipping_city',
        'shipping_country_code',
        'is_access_point_order',
        'is_business_order',
        'order_total_currency_code',
        'order_total_amount',
        'payment_method_details',
        'last_update_date',
        'shipment_service_level_category',
        'pushed_to_retail_edge',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_premium_order' => 'boolean',
        'is_prime' => 'boolean',
        'has_regulated_items' => 'boolean',
        'is_replacement_order' => 'boolean',
        'is_sold_by_ab' => 'boolean',
        'is_ispu' => 'boolean',
        'is_access_point_order' => 'boolean',
        'is_business_order' => 'boolean',
        'latest_ship_date' => 'datetime',
        'purchase_date' => 'datetime',
        'last_update_date' => 'datetime',
        'payment_method_details' => 'json',
        'pushed_to_retail_edge' => 'boolean',
    ];

    /**
     * Get the order items for this Amazon order.
     */
    public function orderItems()
    {
        return $this->hasMany(AmazonOrderItem::class, 'amazon_order_id', 'amazon_order_id');
    }
}
