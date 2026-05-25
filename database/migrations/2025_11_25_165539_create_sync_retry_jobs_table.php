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
        Schema::create('sync_retry_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_type'); // 'manual_retry_all', 'manual_retry_single'
            $table->string('triggered_by')->nullable(); // User email/ID
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->index();

            // Progress tracking
            $table->integer('total_items')->default(0);
            $table->integer('processed_items')->default(0);
            $table->integer('successful_items')->default(0);
            $table->integer('failed_items')->default(0);

            // Details
            $table->json('items_to_retry')->nullable(); // Array of variant IDs
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_retry_jobs');
    }
};
