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
        Schema::create('shopify_product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->references('product_id')->on('shopify_products')->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->index();
            $table->string('title');
            $table->decimal('price')->default(0);
            $table->string('sku')->nullable();
            $table->smallInteger('position')->default(1);
            $table->string('inventory_policy')->nullable();
            $table->string('fulfillment_service')->nullable();
            $table->string('inventory_management')->nullable();
            $table->string('option1')->nullable();
            $table->string('option2')->nullable();
            $table->string('option3')->nullable();
            $table->boolean('taxable')->default(true);
            $table->string('barcode')->nullable();
            $table->decimal('grams')->default(0);
            $table->decimal('weight')->default(0);
            $table->unsignedBigInteger('inventory_item_id')->nullable()->index();
            $table->smallInteger('inventory_quantity')->default(0);
            $table->smallInteger('old_inventory_quantity')->default(0);
            $table->boolean('requires_shipping')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_product_variants');
    }
};
