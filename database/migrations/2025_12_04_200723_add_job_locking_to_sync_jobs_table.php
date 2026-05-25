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
        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('last_heartbeat')->nullable()->after('started_at');
            $table->string('process_id', 50)->nullable()->after('last_heartbeat');
            $table->integer('timeout_minutes')->default(30)->after('process_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'last_heartbeat', 'process_id', 'timeout_minutes']);
        });
    }
};
