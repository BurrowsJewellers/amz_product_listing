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
        Schema::create('retail_edge_product_isds', function (Blueprint $table) {
            $table->string('sku');
            $table->integer('isd_index');
            $table->string('isd_name');
            $table->string('isd_value');
            $table->timestamps();
            $table->primary(['sku', 'isd_index']);
            $table->foreign('sku')->references('sku')->on('retail_edge_products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retail_edge_product_isds');
    }
};
