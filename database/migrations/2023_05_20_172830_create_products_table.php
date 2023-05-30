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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku');
            $table->string('title');
            $table->string('asin')->nullable();
            $table->string('ean')->nullable();
            $table->string('upc')->nullable();
            $table->foreignId('brand_id')->constrained();
            $table->foreignId('marketplace_id')->constrained();
            $table->foreignId('category_id')->constrained();
            $table->foreignId('product_type_id')->constrained();
            $table->text('description')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('recommended_browse_nodes')->nullable();
            $table->string('department_name')->nullable();
            $table->string('size_name')->nullable();
            $table->string('country_of_origin')->nullable();
            $table->string('item_type_name')->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('standard_price', 8, 2)->nullable();
            $table->boolean('xml_generated')->default(false);
            $table->boolean('price_feed_status')->default(false);
            $table->boolean('image_feed_status')->default(false);
            $table->boolean('inventory_feed_status')->default(false);
            $table->boolean('submitted')->default(false);
            $table->boolean('published')->default(false);
            $table->boolean('update')->default(false);
            $table->string('status')->nullable();
            $table->text('message')->nullable();
            $table->foreignId('amz_feed_id')->nullable()->references('id')->on('amz_feeds');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
