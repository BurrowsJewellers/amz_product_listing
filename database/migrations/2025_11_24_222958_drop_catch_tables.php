<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('catch_product_images');
        Schema::dropIfExists('catch_imports');
        Schema::dropIfExists('catch_products');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to recreate these tables
    }
};
