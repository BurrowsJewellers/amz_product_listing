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
        Schema::create('amz_requested_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->nullable();
            $table->string('report_type');
            $table->string('file_name')->nullable();
            $table->foreignId('amz_marketplace_id')->references('id')->on('amz_marketplaces')->cascadeOnDelete();
            $table->boolean('downloaded')->default(false);
            $table->boolean('processed')->default(false);
            $table->string('api_response', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amz_requested_reports');
    }
};
