<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyMetafield extends Model
{
    use HasFactory;

    protected $table = 'shopify_metafields'; // Explicitly define table name

    protected $fillable = [
        'name',
        'namespace',
        'key',
        'type',
        'owner_type',
        'gid',
    ];

    /**
     * Get the variant metafields associated with this metafield definition.
     */
    public function variantMetafields()
    {
        return $this->hasMany(\App\Models\ShopifyProductVariantMetafield::class, 'shopify_metafield_id');
    }
}
