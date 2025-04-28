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
        Schema::create('amazon_order_items', function (Blueprint $table) {
            $table->id();
            $table->string('amazon_order_id');
            $table->string('order_item_id');
            $table->string('asin')->nullable();
            $table->string('seller_sku')->nullable();
            $table->string('title')->nullable();
            $table->integer('quantity_ordered')->nullable();
            $table->integer('quantity_shipped')->nullable();
            $table->string('item_price_currency_code')->nullable();
            $table->decimal('item_price_amount', 10, 2)->nullable();
            $table->string('shipping_price_currency_code')->nullable();
            $table->decimal('shipping_price_amount', 10, 2)->nullable();
            $table->string('item_tax_currency_code')->nullable();
            $table->decimal('item_tax_amount', 10, 2)->nullable();
            $table->string('shipping_tax_currency_code')->nullable();
            $table->decimal('shipping_tax_amount', 10, 2)->nullable();
            $table->string('condition_id')->nullable();
            $table->text('condition_note')->nullable();
            $table->boolean('is_gift')->nullable();
            $table->timestamps();

            // Add indexes for faster lookups
            $table->index('amazon_order_id');
            $table->index('order_item_id');
            $table->unique(['amazon_order_id', 'order_item_id']);

            // Add foreign key constraint
            $table->foreign('amazon_order_id')
                ->references('amazon_order_id')
                ->on('amazon_orders')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amazon_order_items');
    }
};
