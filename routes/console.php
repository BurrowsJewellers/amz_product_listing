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
 * - Main sync (getProductsFromEWebMain, shopify:verify-sync-prices, shopifyUpdatePriceInventory)
 * - Amazon sync (generateAmzProductsJson, amazonUpdateInventoryPrice, getAmzMerchantListingAllData, processAmzMerchantListingAllData)
 * - Shopify sync (shopifyGetProducts, shopifyCreateProduct, shopifyUploadImages, shopifyArchiveProducts)
 */

use App\Console\Commands\GetBrandsFromEWeb;
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
// ORCHESTRATED JOB CHAINS
// ========================================

// MAIN PRODUCT SYNC CHAIN - Every 30 minutes
Schedule::command('job:orchestrator main-sync')
    ->cron('*/30 * * * *')
    ->description('Main product sync: EWeb → Verification → Shopify price updates');

// AMAZON OPERATIONS CHAIN - Every 3 hours at :15
Schedule::command('job:orchestrator amazon-sync')
    ->cron('15 */3 * * *')
    ->description('Amazon operations: Products → Inventory/Price → Merchant listings');

// SHOPIFY OPERATIONS CHAIN - Every 3 hours at :45
Schedule::command('job:orchestrator shopify-sync')
    ->cron('45 */3 * * *')
    ->description('Shopify operations: Get products → Create → Upload images → Archive');

// ========================================
// INDEPENDENT JOBS (with pause checking)
// ========================================

// Daily jobs
Schedule::command(GetBrandsFromEWeb::class)
    ->dailyAt('00:05')
    ->when(function () {
        return ! \App\Http\Controllers\SyncJobController::isPaused('getBrandsFromEWeb');
    });

Schedule::command(CountImages::class)
    ->dailyAt('17:00')
    ->when(function () {
        return ! \App\Http\Controllers\SyncJobController::isPaused('shopifyCountImages');
    });

Schedule::command(UpdateProduct::class)
    ->dailyAt('20:00')
    ->when(function () {
        return ! \App\Http\Controllers\SyncJobController::isPaused('shopify:update-product');
    });

// Hourly retry jobs
Schedule::command('shopifyRetryFailedInventoryUpdates')
    ->hourly()
    ->when(function () {
        return ! \App\Http\Controllers\SyncJobController::isPaused('shopifyRetryFailedInventoryUpdates');
    });

// Legacy single job (kept for backward compatibility, but with pause checking)
Schedule::command('getProductsFromEWeb')
    ->everyFifteenMinutes()
    ->when(function () {
        return ! \App\Http\Controllers\SyncJobController::isPaused('getProductsFromEWeb');
    });

// ========================================
// DISABLED/DEPRECATED JOBS
// ========================================

/**
 * The following jobs are now handled by orchestrated chains:
 *
 * MOVED TO MAIN-SYNC CHAIN (every 30 minutes):
 * - GetProductsFromEWebMain (replaced by orchestrator main-sync)
 * - shopifyUpdatePriceInventory (via orchestrator main-sync)
 *
 * MOVED TO AMAZON-SYNC CHAIN (every 3 hours at :15):
 * - generateAmzProductsJson (via orchestrator amazon-sync)
 * - amazonUpdateInventoryPrice (via orchestrator amazon-sync)
 * - getAmzMerchantListingAllData (via orchestrator amazon-sync)
 * - processAmzMerchantListingAllData (via orchestrator amazon-sync)
 *
 * MOVED TO SHOPIFY-SYNC CHAIN (every 3 hours at :45):
 * - shopifyGetProducts (via orchestrator shopify-sync)
 * - shopifyCreateProduct (via orchestrator shopify-sync)
 * - shopifyUploadImages (via orchestrator shopify-sync)
 * - shopifyArchiveProducts (via orchestrator shopify-sync)
 *
 * COMPLETELY DISABLED (commented out):
 * - Individual Amazon update commands (replaced by orchestrated amazon-sync)
 * - Individual Shopify inventory/price updates (replaced by orchestrated main-sync)
 */
