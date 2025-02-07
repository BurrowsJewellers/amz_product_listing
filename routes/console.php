<?php

use App\Console\Commands\Amazon\GetAmzMerchantListingAllData;
use App\Console\Commands\Amazon\GetProductsFromEWeb;
use App\Console\Commands\Amazon\ProcessAmzMerchantListingAllData;
use App\Console\Commands\Catch\CheckIfExists;
use App\Console\Commands\Catch\GenerateOffersCsv;
use App\Console\Commands\Catch\GenerateProductsCsv;
use App\Console\Commands\Catch\GetProductsFromEWebCatch;
use App\Console\Commands\Catch\ListOffersOfShop;
use App\Console\Commands\Catch\SubmitImports;
use App\Console\Commands\EWeb\GetProductsFromEWebMain;
use App\Console\Commands\GetBrandsFromEWeb;
use App\Console\Commands\Shopify\ArchiveProducts;
use App\Console\Commands\Shopify\CountImages;
use App\Console\Commands\Shopify\CreateProduct;
use App\Console\Commands\Shopify\GetProducts;
use App\Console\Commands\Shopify\UpdateInventory;
use App\Console\Commands\Shopify\UpdatePrice;
use App\Console\Commands\Shopify\UpdateProduct;
use App\Console\Commands\Shopify\UploadImages;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


/**
 * Main job to fetch products from eWeb
 */
Schedule::call(GetProductsFromEWebMain::class)->everyFifteenMinutes()->after(function () {
    Artisan::call(GetProductsFromEWeb::class); //Amazon products
});

Schedule::call(GetBrandsFromEWeb::class)->dailyAt('00:05');

Schedule::call(GetAmzMerchantListingAllData::class)->everyThreeHours()->after(function () {
    sleep(600);
    Artisan::call(ProcessAmzMerchantListingAllData::class);
});

Schedule::call(ProcessAmzMerchantListingAllData::class)->cron('32 */3 * * *');


/**
 * Catch Crons
 */

// The following three cron jobs must run in the same sequence.
// Schedule::call('getProductsFromEWebCatch')->dailyAt('00:20');
// Schedule::call('catchCheckIfExists')->dailyAt('00:50');
// Schedule::call('catchListOffersOfShop')->dailyAt('01:20');

Schedule::call(GetProductsFromEWebCatch::class)->dailyAt('00:20')->after(function () {
    $this->call(CheckIfExists::class);
});
Schedule::call(ListOffersOfShop::class)->dailyAt('01:20');



Schedule::call(GetProductsFromEWebCatch::class)->everyFifteenMinutes()->between('02:00', '23:59');
// Schedule::call('catchGenerateProductsCsv')->everyTwoHours()->between('02:00','23:59');
Schedule::call(GenerateProductsCsv::class)->cron('18 2 */4 * *');
Schedule::call(GenerateOffersCsv::class)->everyFifteenMinutes()->between('02:00', '23:59')
    ->after(function () {
        $this->call(SubmitImports::class);
    });

// Schedule::call('catchSubmitImports')->everyThirtyMinutes()->between('02:00', '23:59');


/**
 * Shopify Crons
 */

// Schedule::call('shopifyGetProducts')->everyTwoHours()->after(function () {
//     $this->call('shopifyCreateProduct');
// });

Schedule::call(GetProducts::class)->cron("5 */2 * * *")->after(function () {
    $this->call(CreateProduct::class);
});


// Schedule::call('shopifyCreateProduct')->everyThreeHours();

Schedule::call(UpdateInventory::class)->everyFifteenMinutes();
Schedule::call(UpdatePrice::class)->everyFifteenMinutes();
Schedule::call(UploadImages::class)->everyThreeHours();
Schedule::call(ArchiveProducts::class)->cron('20 */3 * * *');

Schedule::call(UpdateProduct::class)->cron('0 20 * * 6');

Schedule::call(CountImages::class)->dailyAt('17:00');
