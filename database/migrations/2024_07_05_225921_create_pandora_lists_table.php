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
        Schema::create('pandora_lists', function (Blueprint $table) {
            $table->id();
            $table->string('design_no');
            $table->string('sku');
            $table->string('product_name')->nullable();
            $table->string('product_url')->nullable();
            $table->text('search_response')->nullable();
            $table->boolean('discontinued')->default(false);
            $table->string('images', 3000)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pandora_lists');
    }
};
