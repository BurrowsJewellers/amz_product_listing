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
            $table->boolean('is_paused')->default(false)->after('status');
            $table->timestamp('paused_at')->nullable()->after('is_paused');
            $table->string('paused_by')->nullable()->after('paused_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->dropColumn(['is_paused', 'paused_at', 'paused_by']);
        });
    }
};