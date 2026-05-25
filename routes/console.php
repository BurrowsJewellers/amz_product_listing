<?php

/**
 * ========================================
 * CONSOLE ROUTES FOR ORCHESTRATED JOBS
 * ========================================
 *
 * This file contains only the schedules that are NOT handled by
 * the orchestrated chains in Kernel.php
 *
 * Orchestrated chains handle:
 * - Main sync (getProductsFromEWebMain, shopify:verify-sync-prices, shopify:update-price-inventory-batch)
 * - Shopify sync (shopifyGetProducts, shopifyCreateProduct, shopifyUploadImages, shopifyArchiveProducts)
 * - Amazon sync (processAmzMerchantListingAllData, getProductsFromEWebAmazon, generateAmzProductsJson, checkAmzFeedStatus, amazonUpdateInventoryPrice)
 */

use App\Console\Commands\EWeb\GetBrandsFromEWeb;
use App\Console\Commands\Shopify\CountImages;
use App\Console\Commands\Shopify\UpdateProduct;
use Illuminate\Support\Facades\Schedule;

// ========================================
// TELESCOPE MAINTENANCE
// ========================================
Schedule::command('telescope:prune --hours=3')->everyFourHours();

// ========================================
// SYNC MONITORING MAINTENANCE
// ========================================
Schedule::command('sync:cleanup-logs')
    ->daily()
    ->description('Clean up old sync failure logs and completed retry jobs');

// ========================================
// JOB RECOVERY
// ========================================
// Automatically recover stuck jobs every 5 minutes
Schedule::command('job:recover')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->description('Recover stuck jobs that have exceeded their timeout');

// ========================================
// ORCHESTRATED JOB CHAINS (with pause checking)
// ========================================

// MAIN PRODUCT SYNC CHAIN - Every 30 minutes
Schedule::command('job:orchestrator main-sync')
    ->cron('*/30 * * * *')
    ->when(function () {
        return ! \App\Http\Controllers\SyncJobController::isChainPaused(
            ['getProductsFromEWebMain', 'shopify:verify-sync-prices', 'shopify:update-price-inventory-batch'],
            'EWeb'
        );
    })
    ->description('Main product sync: EWeb → Verification → Shopify price updates');

// SHOPIFY OPERATIONS CHAIN - Every 3 hours at :45
Schedule::command('job:orchestrator shopify-sync')
    ->cron('45 */3 * * *')
    ->when(function () {
        return ! \App\Http\Controllers\SyncJobController::isChainPaused(
            ['shopifyGetProducts', 'shopifyCreateProduct', 'shopifyUploadImages', 'shopifyArchiveProducts'],
            'Shopify'
        );
    })
    ->description('Shopify operations: Get products → Create → Upload images → Archive');

// AMAZON OPERATIONS CHAIN - Every hour at :15
Schedule::command('job:orchestrator amazon-sync')
    ->cron('15 */1 * * *')
    ->when(function () {
        return ! \App\Http\Controllers\SyncJobController::isChainPaused(
            ['processAmzMerchantListingAllData', 'getProductsFromEWebAmazon', 'generateAmzProductsJson', 'checkAmzFeedStatus', 'amazonUpdateInventoryPrice'],
            'Amazon'
        );
    })
    ->description('Amazon operations: Download report → Import products → Generate listings → Check status → Update inventory/prices');

// ========================================
// INDEPENDENT JOBS (with pause checking)
// ========================================

// Daily jobs
Schedule::command(GetBrandsFromEWeb::class)
    ->dailyAt('00:05')
    ->when(function () {
        return ! \App\Http\Controllers\SyncJobController::isPaused('getBrandsFromEWeb');
    });

// Schedule::command(CountImages::class)
//     ->dailyAt('17:00')
//     ->when(function () {
//         return ! \App\Http\Controllers\SyncJobController::isPaused('shopifyCountImages');
//     });

Schedule::command(UpdateProduct::class)
    ->dailyAt('20:00')
    ->when(function () {
        return ! \App\Http\Controllers\SyncJobController::isPaused('shopify:update-product');
    });

// Note: shopifyRetryFailedInventoryUpdates removed - redundant with UpdatePriceInventoryBatch::processFailedUpdates()

// Removed: legacy `getProductsFromEWeb` schedule. No command has that exact signature
// (only getProductsFromEWebMain / getProductsFromEWebAmazon), so it threw
// "Command getProductsFromEWeb is ambiguous." every 15 minutes and never ran. The main
// EWeb sync is handled by the main-sync orchestrator chain (getProductsFromEWebMain).

// ========================================
// DISABLED/DEPRECATED JOBS
// ========================================

/**
 * The following jobs are now handled by orchestrated chains:
 *
 * MOVED TO MAIN-SYNC CHAIN (every 30 minutes):
 * - GetProductsFromEWebMain (replaced by orchestrator main-sync)
 * - shopify:verify-sync-prices (via orchestrator main-sync)
 * - shopify:update-price-inventory-batch (via orchestrator main-sync)
 *
 * MOVED TO SHOPIFY-SYNC CHAIN (every 3 hours at :45):
 * - shopifyGetProducts (via orchestrator shopify-sync)
 * - shopifyCreateProduct (via orchestrator shopify-sync)
 * - shopifyUploadImages (via orchestrator shopify-sync)
 * - shopifyArchiveProducts (via orchestrator shopify-sync)
 *
 * MOVED TO AMAZON-SYNC CHAIN (every hour at :15):
 * - processAmzMerchantListingAllData (via orchestrator amazon-sync)
 * - getProductsFromEWebAmazon (via orchestrator amazon-sync)
 * - generateAmzProductsJson (via orchestrator amazon-sync)
 * - checkAmzFeedStatus (via orchestrator amazon-sync)
 * - amazonUpdateInventoryPrice (via orchestrator amazon-sync)
 *
 * DEPRECATED:
 * - Pandora-related commands (moved to Deprecated folder)
 */
