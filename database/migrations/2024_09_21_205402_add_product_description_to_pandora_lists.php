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
            $table->index('design_no');
            $table->text('product_description')->nullable()->after('product_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pandora_lists', function (Blueprint $table) {
            $table->dropIndex('design_no');
            $table->dropColumn('product_description');
        });
    }
};
