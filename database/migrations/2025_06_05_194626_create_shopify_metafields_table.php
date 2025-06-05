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
        Schema::create('shopify_metafields', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('namespace')->comment('custom');
            $table->string('key');
            $table->string('type')->comment('e.g., multi_line_text_field');
            $table->string('owner_type')->default('PRODUCTVARIANT');
            $table->string('gid')->nullable()->comment('Shopify GraphQL ID');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_metafields');
    }
};
