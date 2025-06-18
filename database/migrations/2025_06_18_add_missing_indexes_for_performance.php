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
        // Add indexes to shopify_product_variants table
        Schema::table('shopify_product_variants', function (Blueprint $table) {
            // Critical index for JOIN operations with retail_edge_products
            $table->index('sku', 'idx_shopify_product_variants_sku');
            
            // Index for filtering variants that need updates
            $table->index('requires_update', 'idx_shopify_product_variants_requires_update');
            
            // Composite index for common query pattern
            $table->index(['sku', 'requires_update'], 'idx_shopify_product_variants_sku_requires_update');
        });

        // Add indexes to retail_edge_products table
        Schema::table('retail_edge_products', function (Blueprint $table) {
            // Index for filtering by upload status
            $table->index('uploaded_to_shopify', 'idx_retail_edge_products_uploaded_to_shopify');
            
            // Index for quantity filtering
            $table->index('quantity', 'idx_retail_edge_products_quantity');
            
            // Composite index for common query pattern
            $table->index(['uploaded_to_shopify', 'quantity'], 'idx_retail_edge_products_upload_quantity');
        });

        // Add indexes to shopify_products table
        Schema::table('shopify_products', function (Blueprint $table) {
            // Index for SKU lookups
            $table->index('sku', 'idx_shopify_products_sku');
        });

        // Add indexes to shopify_skus table
        Schema::table('shopify_skus', function (Blueprint $table) {
            // Primary lookup column needs index
            $table->index('sku', 'idx_shopify_skus_sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove indexes from shopify_product_variants
        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->dropIndex('idx_shopify_product_variants_sku');
            $table->dropIndex('idx_shopify_product_variants_requires_update');
            $table->dropIndex('idx_shopify_product_variants_sku_requires_update');
        });

        // Remove indexes from retail_edge_products
        Schema::table('retail_edge_products', function (Blueprint $table) {
            $table->dropIndex('idx_retail_edge_products_uploaded_to_shopify');
            $table->dropIndex('idx_retail_edge_products_quantity');
            $table->dropIndex('idx_retail_edge_products_upload_quantity');
        });

        // Remove indexes from shopify_products
        Schema::table('shopify_products', function (Blueprint $table) {
            $table->dropIndex('idx_shopify_products_sku');
        });

        // Remove indexes from shopify_skus
        Schema::table('shopify_skus', function (Blueprint $table) {
            $table->dropIndex('idx_shopify_skus_sku');
        });
    }
};