<?php

namespace App\Models\Catch;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\EWebShortCode;
use App\Models\Category;
use App\Models\Brand;

class CatchProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'title',
        'product_description',
        'product_reference_type',
        'product_reference_value',
        'brand_id',
        'marketplace_id',
        'category_id',
        'e_web_code',
        'condition',
        'product_quantity_multiplier',
        'colour',
        'keywords',
        'gender',
        'material',
        'variant_id',
        'variant_colour_value',
        'variant_size_value',
        'weight',
        'weight_unit',
        'width',
        'width_unit',
        'length',
        'length_unit',
        'height',
        'height_unit',
        'model_number',
        'season',
        'adult',
        'restriction',
        'gift_type',
        'accessories_material',
        'apparel_type',
        'contains_button_cell_batteries',
        'clearance',
        'clearance_stream',
        'metal_type',
        'stone_type',
        'display_type',
        'watch_case_diameter',
        'watch_shape',
        'water_resistance',
        'watch_case_diameter_unit',
        'bracelet_type',
        'earring_style',
        'price',
        'price_additional_info',
        'quantity',
        'min_quantity_alert',
        'available_start_date',
        'available_end_date',
        'logistic_class',
        'discount_price',
        'discount_start_date',
        'discount_end_date',
        'leadtime_to_ship',
        'update_delete',
        'quantity_multiplier',
        'purchase_limit',
        'club_catch_eligible',
        'tax_au',
        'click_and_collect_eligible',
        'product_csv_generated',
        'product_csv_submitted',
        'offer_csv_generated',
        'offer_csv_submitted',
        'exists_on_catch',
        'published',
        'update',
        'status',
        'message',
    ];

    public function category() {
        return $this->belongsTo(Category::class);
    }

    public function brand() {
        return $this->belongsTo(Brand::class);
    }

    public function images() {
        return $this->hasMany(CatchProductImage::class);
    }

    public function eWebCode() {
        return $this->hasOne(EWebShortCode::class, 'code', 'e_web_code');
    }

}
