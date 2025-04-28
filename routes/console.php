<?php

use App\Console\Commands\Amazon\GetAmzMerchantListingAllData;
use App\Console\Commands\Amazon\GetOrders;
use App\Console\Commands\Amazon\GetProductsFromEWeb;
use App\Console\Commands\Amazon\ProcessAmzMerchantListingAllData;
use App\Console\Commands\Amazon\UpdateProduct as AmazonUpdateProduct;
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




Schedule::command('telescope:prune --hours=3')->everyFourHours();

/**
 * Main job to fetch products from eWeb
 */
Schedule::command(GetProductsFromEWebMain::class)->everyFifteenMinutes()->after(function () {
    Artisan::call(GetProductsFromEWeb::class); //Amazon products
});

Schedule::command(GetBrandsFromEWeb::class)->dailyAt('00:05');
Schedule::command(GetAmzMerchantListingAllData::class)->everyThreeHours();

// Schedule::command(GetAmzMerchantListingAllData::class)->everyThreeHours()->after(function () {
//     sleep(600);
//     Artisan::call(ProcessAmzMerchantListingAllData::class);
// });

// Schedule::command(ProcessAmzMerchantListingAllData::class)->cron('32 */3 * * *');


Schedule::command(AmazonUpdateProduct::class)->everyFifteenMinutes();
Schedule::command(GetOrders::class)->everyFifteenMinutes();

/**
 * Catch Crons
 */

// The following three cron jobs must run in the same sequence.
// Schedule::command('getProductsFromEWebCatch')->dailyAt('00:20');
// Schedule::command('catchCheckIfExists')->dailyAt('00:50');
// Schedule::command('catchListOffersOfShop')->dailyAt('01:20');

// Schedule::command(GetProductsFromEWebCatch::class)->dailyAt('00:20')->after(function () {
//     $this->call(CheckIfExists::class);
// });
// Schedule::command(ListOffersOfShop::class)->dailyAt('01:20');



// Schedule::command(GetProductsFromEWebCatch::class)->everyFifteenMinutes()->between('02:00', '23:59');
// Schedule::command('catchGenerateProductsCsv')->everyTwoHours()->between('02:00','23:59');
// Schedule::command(GenerateProductsCsv::class)->cron('18 2 */4 * *');
// Schedule::command(GenerateOffersCsv::class)->everyFifteenMinutes()->between('02:00', '23:59')
//     ->after(function () {
//         $this->call(SubmitImports::class);
//     });

// Schedule::command('catchSubmitImports')->everyThirtyMinutes()->between('02:00', '23:59');


/**
 * Shopify Crons
 */

Schedule::command(GetProducts::class)->everyTwoHours()->after(function () {
    $this->call(CreateProduct::class);
});

Schedule::command(GetProducts::class)->cron("5 */2 * * *")->after(function () {
    $this->call(CreateProduct::class);
});


// Schedule::command('shopifyCreateProduct')->everyThreeHours();

Schedule::command(UpdateInventory::class)->everyFifteenMinutes();
Schedule::command(UpdatePrice::class)->everyFifteenMinutes();
Schedule::command('shopifyRetryFailedInventoryUpdates')->hourly(); // Retry failed inventory updates hourly
Schedule::command(UploadImages::class)->everyThreeHours();
Schedule::command(ArchiveProducts::class)->cron('20 */3 * * *');

Schedule::command(UpdateProduct::class)->cron('0 20 * * 6');

Schedule::command(CountImages::class)->dailyAt('17:00');
