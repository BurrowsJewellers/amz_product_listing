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
        // Add composite index for flag queries on shopify_product_variants
        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->index(['price_requires_update', 'inventory_requires_update'], 'idx_flags');
        });

        // Add indexes for dashboard queries on price_inventory_logs
        Schema::table('price_inventory_logs', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'idx_status_date');
            $table->index(['job_name', 'created_at'], 'idx_job_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->dropIndex('idx_flags');
        });

        Schema::table('price_inventory_logs', function (Blueprint $table) {
            $table->dropIndex('idx_status_date');
            $table->dropIndex('idx_job_date');
        });
    }
};
