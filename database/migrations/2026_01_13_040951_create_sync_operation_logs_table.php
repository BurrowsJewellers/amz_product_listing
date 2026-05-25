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
        Schema::create('sync_operation_logs', function (Blueprint $table) {
            $table->id();

            // Marketplace identification
            $table->enum('marketplace', ['Shopify', 'Amazon'])->index();
            $table->string('job_name', 100)->index();

            // Item identification
            $table->string('item_identifier', 100)->index();
            $table->string('item_title')->nullable();

            // Operation details
            $table->enum('operation_type', [
                // Product operations
                'product_create',
                'product_update',
                'product_delete',
                'product_archive',
                'product_sync',
                // Variant operations
                'variant_create',
                'variant_update',
                'variant_delete',
                'duplicate_cleanup',
                // Price/Inventory operations
                'price_update',
                'inventory_update',
                'price_inventory_update',
                // Image operations
                'image_upload',
                'image_delete',
                // Metafield operations
                'metafield_update',
                // Feed operations (Amazon)
                'feed_submit',
                'feed_status_check',
            ])->index();

            // Status tracking
            $table->enum('status', ['success', 'failed', 'pending', 'skipped'])->index();

            // Value changes (for price/inventory tracking)
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();

            // Detailed logging
            $table->text('message')->nullable();
            $table->json('api_request')->nullable();
            $table->json('api_response')->nullable();
            $table->json('errors')->nullable();
            $table->json('context_data')->nullable();

            // Error tracking (for failures)
            $table->string('error_file')->nullable();
            $table->integer('error_line')->nullable();

            // Related records
            $table->unsignedBigInteger('shopify_product_id')->nullable()->index();
            $table->unsignedBigInteger('shopify_variant_id')->nullable()->index();
            $table->string('amazon_asin', 20)->nullable()->index();

            // Processing metadata
            $table->integer('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();

            $table->timestamps();

            // Composite indexes for common queries
            $table->index(['marketplace', 'status', 'created_at']);
            $table->index(['operation_type', 'status']);
            $table->index(['item_identifier', 'marketplace']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_operation_logs');
    }
};
