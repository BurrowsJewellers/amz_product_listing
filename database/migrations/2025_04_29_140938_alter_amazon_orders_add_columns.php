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
        Schema::table('amazon_orders', function (Blueprint $table) {
            $table->string('buyer_email')->nullable()->after('marketplace_id');
            $table->string('shipping_name')->nullable()->after('purchase_date');
            $table->string('shipping_address1')->nullable()->after('shipping_name');
            $table->string('shipping_address2')->nullable()->after('shipping_address1');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amazon_orders', function (Blueprint $table) {
            $table->dropColumn('buyer_email');
            $table->dropColumn('shipping_name');
            $table->dropColumn('shipping_address1');
            $table->dropColumn('shipping_address2');
        });
    }
};
