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
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('item_length_numeric', 8, 2)->after('e_web_code')->nullable();
            $table->string('item_length_numeric_unit')->after('item_length_numeric')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('item_length_numeric');
            $table->dropColumn('item_length_numeric_unit');
        });
    }
};
