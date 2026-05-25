<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add indexes to improve query performance for frequently used columns
     * in Shopify sync operations.
     */
    public function up(): void
    {
        // Add index for status filtering (used in ArchiveProducts, listing queries)
        Schema::table('shopify_products', function (Blueprint $table) {
            $table->index('status', 'sprod_status_idx');
        });

        // Add index for SKU lookups (heavily used in JOINs and WHERE clauses)
        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->index('sku', 'spv_sku_idx');
        });

        // Add index for requires_update flag (used in inventory sync queries)
        Schema::table('shopify_inventory_levels', function (Blueprint $table) {
            $table->index('requires_update', 'sil_requires_update_idx');
        });

        // Add index for shopify_metafield_id on variant metafields (used in lookups)
        Schema::table('shopify_product_variant_metafields', function (Blueprint $table) {
            $table->index('shopify_metafield_id', 'spvm_metafield_id_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopify_products', function (Blueprint $table) {
            $table->dropIndex('sprod_status_idx');
        });

        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->dropIndex('spv_sku_idx');
        });

        Schema::table('shopify_inventory_levels', function (Blueprint $table) {
            $table->dropIndex('sil_requires_update_idx');
        });

        Schema::table('shopify_product_variant_metafields', function (Blueprint $table) {
            $table->dropIndex('spvm_metafield_id_idx');
        });
    }
};
