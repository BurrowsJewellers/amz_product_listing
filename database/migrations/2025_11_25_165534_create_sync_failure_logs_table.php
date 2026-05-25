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
        Schema::create('sync_failure_logs', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace', 50)->index();
            $table->string('job_name')->index();
            $table->string('item_identifier')->index(); // SKU
            $table->enum('operation_type', ['price', 'inventory', 'price_inventory'])->index();
            $table->enum('flag_value', ['1', '2', '3'])->index(); // What flag was when it failed

            // Full error details
            $table->text('error_message');
            $table->json('api_request')->nullable(); // What we sent
            $table->json('api_response')->nullable(); // Full API response
            $table->json('user_errors')->nullable(); // GraphQL userErrors
            $table->json('graphql_errors')->nullable(); // GraphQL errors

            // Data comparison
            $table->json('current_data')->nullable(); // Current DB values
            $table->json('target_data')->nullable(); // What we tried to update

            // Error location
            $table->string('error_file')->nullable();
            $table->integer('error_line')->nullable();

            // Related records
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->unsignedBigInteger('retry_job_id')->nullable()->index(); // Link to retry jobs

            $table->timestamps();
            $table->index('created_at'); // For cleanup query

            // Note: Foreign keys will be added after all tables are created
            // For now we use soft references via indexes
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_failure_logs');
    }
};
