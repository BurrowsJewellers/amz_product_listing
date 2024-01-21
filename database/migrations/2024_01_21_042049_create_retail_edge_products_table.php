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
        Schema::create('retail_edge_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->index();
            $table->string('title');
            $table->text('marketing_description')->nullable();
            $table->decimal('retail_price_1', 10, 2)->default(0);
            $table->decimal('retail_price_2', 10, 2)->default(0);
            $table->integer('quantity')->default(0);
            $table->string('id_1')->nullable();
            $table->string('old_key')->nullable();
            $table->string('real_design_number')->nullable();
            $table->string('s_web_menu')->nullable();
            $table->string('s_metal_type')->nullable();
            $table->string('s_stone_type')->nullable();
            $table->string('s_cat')->nullable();
            $table->string('s_sub_cat')->nullable();
            $table->boolean('web_option_boolean_1')->default(false);
            $table->boolean('web_option_boolean_2')->default(false);
            $table->boolean('web_option_boolean_3')->default(false);
            $table->boolean('web_option_boolean_4')->default(false);
            $table->boolean('web_option_boolean_5')->default(false);
            $table->boolean('web_option_boolean_6')->default(false);
            $table->boolean('web_option_boolean_7')->default(false);
            $table->boolean('web_option_boolean_8')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retail_edge_products');
    }
};
