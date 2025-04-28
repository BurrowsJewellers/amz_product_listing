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
        Schema::create('amazon_orders', function (Blueprint $table) {
            $table->id();
            $table->string('amazon_order_id')->unique();
            $table->string('sales_channel')->nullable();
            $table->string('order_status')->nullable();
            $table->integer('number_of_items_shipped')->nullable();
            $table->string('order_type')->nullable();
            $table->boolean('is_premium_order')->nullable();
            $table->boolean('is_prime')->nullable();
            $table->string('fulfillment_channel')->nullable();
            $table->integer('number_of_items_unshipped')->nullable();
            $table->boolean('has_regulated_items')->nullable();
            $table->boolean('is_replacement_order')->nullable();
            $table->boolean('is_sold_by_ab')->nullable();
            $table->timestamp('latest_ship_date')->nullable();
            $table->string('ship_service_level')->nullable();
            $table->boolean('is_ispu')->nullable();
            $table->string('marketplace_id')->nullable();
            $table->timestamp('purchase_date')->nullable();
            $table->string('shipping_state_or_region')->nullable();
            $table->string('shipping_postal_code')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_country_code')->nullable();
            $table->boolean('is_access_point_order')->nullable();
            $table->boolean('is_business_order')->nullable();
            $table->string('order_total_currency_code')->nullable();
            $table->decimal('order_total_amount', 10, 2)->nullable();
            $table->json('payment_method_details')->nullable();
            $table->timestamp('last_update_date')->nullable();
            $table->string('shipment_service_level_category')->nullable();
            $table->timestamps();

            // Add index for faster lookups
            $table->index('amazon_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amazon_orders');
    }
};
