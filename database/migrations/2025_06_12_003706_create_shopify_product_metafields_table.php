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
        Schema::create('shopify_product_metafields', function (Blueprint $table) {
            $table->id();
            $table->string('product_sku'); // Reference to the product SKU
            $table->unsignedBigInteger('shopify_metafield_id'); // Reference to shopify_metafields table
            $table->text('value'); // The metafield value
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('shopify_metafield_id')->references('id')->on('shopify_metafields')->onDelete('cascade');

            // Index for faster lookups
            $table->index(['product_sku', 'shopify_metafield_id'], 'spm_sku_metafield_idx');

            // Unique constraint to prevent duplicate metafields for same product
            $table->unique(['product_sku', 'shopify_metafield_id'], 'spm_sku_metafield_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_product_metafields');
    }
};
