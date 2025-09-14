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
        Schema::table('retail_edge_products', function (Blueprint $table) {
            $table->decimal('special_price', 10, 2)->default(0)->nullable()->after('compare_at_price');
            $table->dateTime('special_price_start')->nullable()->after('special_price');
            $table->dateTime('special_price_end')->nullable()->after('special_price_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('retail_edge_products', function (Blueprint $table) {
            $table->dropColumn(['special_price', 'special_price_start', 'special_price_end']);
        });
    }
};
