<?php

use App\Console\Commands\EWeb\GetProductsFromEWebMain;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();


/**
 * Main job to fetch products from eWeb
 */
Schedule::command(GetProductsFromEWebMain::class)->everyFifteenMinutes();

/**
 * Amazon Crons
 */
Schedule::call('getBrandsFromEWeb')->dailyAt('00:05');
Schedule::call('getProductsFromEWeb')->everyFifteenMinutes();

Schedule::call('getAmzMerchantListingAllData')->everyThreeHours();
Schedule::call('processAmzMerchantListingAllData')->cron('32 */3 * * *');


/**
 * Catch Crons
 */

// The following three cron jobs must run in the same sequence.
// Schedule::call('getProductsFromEWebCatch')->dailyAt('00:20');
// Schedule::call('catchCheckIfExists')->dailyAt('00:50');
// Schedule::call('catchListOffersOfShop')->dailyAt('01:20');

Schedule::call('getProductsFromEWebCatch')->dailyAt('00:20')->after(function () {
    $this->call('catchCheckIfExists');
});
Schedule::call('catchListOffersOfShop')->dailyAt('01:20');



Schedule::call('getProductsFromEWebCatch')->everyFifteenMinutes()->between('02:00', '23:59');
// Schedule::call('catchGenerateProductsCsv')->everyTwoHours()->between('02:00','23:59');
Schedule::call('catchGenerateProductsCsv')->cron('18 2 */4 * *');
Schedule::call('catchGenerateOffersCsv')->everyFifteenMinutes()->between('02:00', '23:59')
    ->after(function () {
        $this->call('catchSubmitImports');
    });

// Schedule::call('catchSubmitImports')->everyThirtyMinutes()->between('02:00', '23:59');


/**
 * Shopify Crons
 */

// Schedule::call('shopifyGetProducts')->everyTwoHours()->after(function () {
//     $this->call('shopifyCreateProduct');
// });

Schedule::call('shopifyGetProducts')->cron("5 */2 * * *")->after(function () {
    $this->call('shopifyCreateProduct');
});


// Schedule::call('shopifyCreateProduct')->everyThreeHours();

Schedule::call('shopifyUpdateInventory')->everyFifteenMinutes();
Schedule::call('shopifyUpdatePrice')->everyFifteenMinutes();
Schedule::call('shopifyUploadImages')->everyThreeHours();
Schedule::call('shopifyArchiveProducts')->cron('20 */3 * * *');

Schedule::call('shopifyUpdateProduct')->cron('0 20 * * 6');

Schedule::call('shopifyCountImages')->dailyAt('17:00');
