<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get the Catch marketplace ID
        $catchMarketplace = DB::table('marketplaces')->where('name', 'Catch')->first();

        if ($catchMarketplace) {
            $catchMarketplaceId = $catchMarketplace->id;

            // Delete Catch-related e_web_short_codes
            DB::table('e_web_short_codes')->where('marketplace_id', $catchMarketplaceId)->delete();

            // Delete Catch-related categories
            DB::table('categories')->where('marketplace_id', $catchMarketplaceId)->delete();

            // Delete Catch-related sync_jobs if table and column exist
            if (Schema::hasTable('sync_jobs') && Schema::hasColumn('sync_jobs', 'marketplace_id')) {
                DB::table('sync_jobs')->where('marketplace_id', $catchMarketplaceId)->delete();
            }

            // Finally, delete the Catch marketplace
            DB::table('marketplaces')->where('id', $catchMarketplaceId)->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to restore deleted data
    }
};
