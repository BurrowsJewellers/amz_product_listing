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
            $table->string('inventory_item_gid')->nullable()->after('inventory_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->dropColumn('inventory_item_gid');
        });
    }
};
