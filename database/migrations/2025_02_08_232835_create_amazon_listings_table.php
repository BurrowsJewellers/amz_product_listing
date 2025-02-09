<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('amazon_listings', function (Blueprint $table) {
            $table->id();
            $table->string('item_name');
            $table->text('item_description')->nullable();
            $table->string('listing_id')->unique();
            $table->string('seller_sku');
            $table->decimal('price', 10, 2);
            $table->integer('quantity');
            $table->datetime('open_date');
            $table->string('image_url')->nullable();
            $table->boolean('item_is_marketplace')->default(false);
            $table->string('product_id_type')->nullable();
            $table->string('zshop_shipping_fee')->nullable();
            $table->text('item_note')->nullable();
            $table->string('item_condition')->nullable();
            $table->string('zshop_category1')->nullable();
            $table->string('zshop_browse_path')->nullable();
            $table->string('zshop_storefront_feature')->nullable();
            $table->string('asin1')->nullable();
            $table->string('asin2')->nullable();
            $table->string('asin3')->nullable();
            $table->string('will_ship_internationally')->nullable();
            $table->string('expedited_shipping')->nullable();
            $table->string('zshop_boldface')->nullable();
            $table->string('product_id')->nullable();
            $table->string('bid_for_featured_placement')->nullable();
            $table->string('add_delete')->nullable();
            $table->integer('pending_quantity')->default(0);
            $table->string('fulfilment_channel')->nullable();
            $table->string('merchant_shipping_group')->nullable();
            $table->string('status');
            $table->timestamps();
            $table->index('seller_sku');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amazon_listings');
    }
};
