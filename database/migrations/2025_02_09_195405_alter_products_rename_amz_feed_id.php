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
            $table->dropForeign(['amz_feed_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('amz_feed_id', 'amz_submission_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('amz_submission_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('amz_submission_id', 'amz_feed_id');
        });
    }
};
