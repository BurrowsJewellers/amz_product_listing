<?php

namespace App\Models\Amazon;

use Illuminate\Database\Eloquent\Model;

class AmazonListings extends Model
{
    protected $fillable = [
        'item_name',
        'item_description',
        'listing_id',
        'seller_sku',
        'price',
        'quantity',
        'open_date',
        'image_url',
        'item_is_marketplace',
        'product_id_type',
        'zshop_shipping_fee',
        'item_note',
        'item_condition',
        'zshop_category1',
        'zshop_browse_path',
        'zshop_storefront_feature',
        'asin1',
        'asin2',
        'asin3',
        'will_ship_internationally',
        'expedited_shipping',
        'zshop_boldface',
        'product_id',
        'bid_for_featured_placement',
        'add_delete',
        'pending_quantity',
        'fulfilment_channel',
        'merchant_shipping_group',
        'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'pending_quantity' => 'integer',
        'open_date' => 'datetime',
        'item_is_marketplace' => 'boolean',
    ];
}
