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
        Schema::create('price_inventory_logs', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace');
            $table->string('item_identifier')->index(); // SKU, Variant ID, ASIN etc.
            $table->string('change_type'); // price, inventory, compare_at_price etc.
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->string('status'); // success, failed
            $table->text('message')->nullable(); // Error messages or other details
            $table->string('job_name')->nullable(); // Artisan command signature or job class
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_inventory_logs');
    }
};
