<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('price_inventory_logs');
        Schema::dropIfExists('sync_failure_logs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // These tables are being replaced by sync_operation_logs.
        // If you need to restore them, recreate manually from the original migrations.
    }
};
