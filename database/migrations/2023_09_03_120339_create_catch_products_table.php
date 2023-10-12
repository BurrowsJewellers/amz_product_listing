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
        Schema::create('catch_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku');
            $table->string('title');
            $table->text('product_description')->nullable();
            $table->string('product_reference_type')->nullable();
            $table->string('product_reference_value')->nullable();
            $table->foreignId('brand_id')->references('id')->on('brands')->cascadeOnDelete();
            $table->foreignId('marketplace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('e_web_code')->nullable();
            $table->string('condition')->default('New');
            $table->integer('product_quantity_multiplier')->default(1);
            $table->string('colour')->nullable();
            $table->string('keywords')->nullable();
            $table->string('gender')->nullable();
            $table->string('material')->nullable();
            $table->string('variant_id')->nullable();
            $table->string('variant_colour_value')->nullable();
            $table->string('variant_size_value')->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->string('weight_unit')->nullable();
            $table->decimal('width', 10, 3)->nullable();
            $table->string('width_unit')->nullable();
            $table->decimal('length', 10, 3)->nullable();
            $table->string('length_unit')->nullable();
            $table->decimal('height', 10, 3)->nullable();
            $table->string('height_unit')->nullable();
            $table->string('model_number')->nullable();
            $table->string('season')->nullable();
            $table->string('adult')->nullable();
            $table->string('restriction')->nullable();
            $table->string('gift_type')->nullable();
            $table->string('accessories_material')->nullable();
            $table->string('apparel_type')->nullable();
            $table->boolean('contains_button_cell_batteries')->default(false);
            $table->boolean('clearance')->default(false);
            $table->string('clearance_stream')->nullable();
            $table->string('metal_type')->nullable();
            $table->string('stone_type')->nullable();
            $table->string('display_type')->nullable();
            $table->string('watch_case_diameter')->nullable();
            $table->string('watch_shape')->nullable();
            $table->boolean('water_resistance')->default(true);
            $table->string('watch_case_diameter_unit')->nullable();
            $table->string('bracelet_type')->nullable();
            $table->string('earring_style')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('price_additional_info')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('min_quantity_alert')->default(1);
            $table->timestamp('available_start_date')->nullable();
            $table->timestamp('available_end_date')->nullable();
            $table->string('logistic_class')->nullable();
            $table->decimal('discount_price', 10, 2)->nullable();
            $table->timestamp('discount_start_date')->nullable();
            $table->timestamp('discount_end_date')->nullable();
            $table->integer('leadtime_to_ship')->default(2);
            $table->enum('update_delete', ["UPDATE", "DELETE"])->default("UPDATE");
            $table->integer('quantity_multiplier')->default(1);
            $table->integer('purchase_limit')->nullable();
            $table->boolean('club_catch_eligible')->default(false);
            $table->decimal('tax_au', 5, 2)->default(0);
            $table->boolean('click_and_collect_eligible')->default(false);
            $table->boolean('product_csv_generated')->default(false);
            $table->boolean('product_csv_submitted')->default(false);
            $table->boolean('offer_csv_generated')->default(false);
            $table->boolean('offer_csv_submitted')->default(false);
            $table->boolean('exists_on_catch')->nullable();
            $table->boolean('published')->default(false);
            $table->boolean('update')->default(false);
            $table->string('status')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catch_products');
    }
};
