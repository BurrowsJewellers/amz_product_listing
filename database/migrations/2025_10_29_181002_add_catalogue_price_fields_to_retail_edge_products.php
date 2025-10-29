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
            $table->string('catalogue_name')->nullable()->after('special_price_end');
            $table->decimal('catalogue_price', 10, 2)->default(0)->nullable()->after('catalogue_name');
            $table->dateTime('catalogue_price_start')->nullable()->after('catalogue_price');
            $table->dateTime('catalogue_price_end')->nullable()->after('catalogue_price_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('retail_edge_products', function (Blueprint $table) {
            $table->dropColumn(['catalogue_name', 'catalogue_price', 'catalogue_price_start', 'catalogue_price_end']);
        });
    }
};
