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
        Schema::table('pandora_lists', function (Blueprint $table) {
            $table->text('product_response')->nullable()->after('product_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pandora_lists', function (Blueprint $table) {
            $table->dropColumn('product_url');
        });
    }
};
