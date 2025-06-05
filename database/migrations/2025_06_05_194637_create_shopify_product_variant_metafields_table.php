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
        Schema::create('shopify_product_variant_metafields', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->comment('Related to shopify_product_variants.sku');
            $table->foreignId('shopify_metafield_id')->constrained('shopify_metafields')->onDelete('cascade');
            $table->text('value');
            $table->timestamps();

            $table->index('sku');
            $table->unique(['sku', 'shopify_metafield_id'], 'sku_product_metafield_unique'); // Renamed unique index
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_product_variant_metafields');
    }
};
