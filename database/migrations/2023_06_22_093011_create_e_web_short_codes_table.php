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
        Schema::create('e_web_short_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('marketplace_id')->constrained();
            $table->foreignId('product_type_id')->nullable()->constrained();
            $table->string('amz_recommended_browse_node')->nullable();
            $table->boolean('button_cell')->default(false);
            $table->string('classification_path', )->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('e_web_short_codes');
    }
};
