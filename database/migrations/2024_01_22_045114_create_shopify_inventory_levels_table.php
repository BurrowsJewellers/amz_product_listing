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
        Schema::create('shopify_inventory_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->references('location_id')->on('shopify_locations');
            $table->foreignId('inventory_item_id')->references('inventory_item_id')->on('shopify_product_variants');
            $table->smallInteger('available')->default(0);
            $table->timestamp('inventory_updated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_inventory_levels');
    }
};
