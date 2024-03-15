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
            $table->string('metal_colour')->nullable()->after('pendant_style');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('retail_edge_products', function (Blueprint $table) {
            $table->dropColumn('metal_colour');
        });
    }
};
