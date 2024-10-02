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
        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->index('price_requires_update');
            $table->index('inventory_requires_update');
            $table->index('images_requires_update');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopify_product_variants', function (Blueprint $table) {
            //
        });
    }
};
