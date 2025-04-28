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
        Schema::table('amazon_orders', function (Blueprint $table) {
            $table->boolean('pushed_to_retail_edge')->default(0)->after('shipment_service_level_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amazon_orders', function (Blueprint $table) {
            $table->dropColumn('pushed_to_retail_edge');
        });
    }
};
