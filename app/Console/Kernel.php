<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        /**
         * Main job to fetch products from eWeb
         */
        $schedule->command('getProductsFromEWebMain')->everyFifteenMinutes()
            ->after(function () {
                $this->call('shopifyUpdatePriceInventory');
            });

        /**
         * Amazon Crons
         */
        $schedule->command('getBrandsFromEWeb')->dailyAt('00:05');
        $schedule->command('getProductsFromEWeb')->everyFifteenMinutes();

        // $schedule->command('generateAmzProductsXml')->cron('10,25,40,55 * * * *');
        // $schedule->command('generateAmzProductsXml')->cron('44 */3 * * *'); // XML-based command (deprecated)
        $schedule->command('generateAmzProductsJson')->cron('44 */3 * * *'); // New JSON-based command

        // $schedule->command('submitAmzXmlFeed POST_PRODUCT_DATA')->cron('12,27,42,57 * * * *');
        // $schedule->command('submitAmzXmlFeed POST_PRODUCT_DATA')->cron('47 */3 * * *'); // XML feed submission (deprecated)

        // $schedule->command('generateAmzInventoryXml')->cron('13,28,43,58 * * * *'); // XML-based inventory (deprecated)
        // $schedule->command('generateAmzPriceXml')->hourly(); // XML-based price (deprecated)
        $schedule->command('amazonUpdateInventoryPrice')->cron('13,28,43,58 * * * *'); // New JSON-based inventory and price updates

        // $schedule->command('generateAmzImagesXml')->hourly();

        // $schedule->command('submitAmzXmlFeed POST_PRODUCT_IMAGE_DATA')->hourlyAt(20); // XML feed submission (deprecated)
        // $schedule->command('submitAmzXmlFeed POST_PRODUCT_PRICING_DATA')->hourlyAt(25); // XML feed submission (deprecated)
        // $schedule->command('submitAmzXmlFeed POST_INVENTORY_AVAILABILITY_DATA')->everyTenMinutes(); // XML feed submission (deprecated)
        // $schedule->command('checkAmzFeedStatus')->everyFifteenMinutes();

        $schedule->command('getAmzMerchantListingAllData')->everyThreeHours();
        $schedule->command('processAmzMerchantListingAllData')->cron('32 */3 * * *');


        /**
         * Catch Crons
         */

        // The following three cron jobs must run in the same sequence.
        // $schedule->command('getProductsFromEWebCatch')->dailyAt('00:20');
        // $schedule->command('catchCheckIfExists')->dailyAt('00:50');
        // $schedule->command('catchListOffersOfShop')->dailyAt('01:20');

        // $schedule->command('getProductsFromEWebCatch')->dailyAt('00:20')->after(function () {
        //     $this->call('catchCheckIfExists');
        // });
        // $schedule->command('catchListOffersOfShop')->dailyAt('01:20');



        // $schedule->command('getProductsFromEWebCatch')->everyFifteenMinutes()->between('02:00', '23:59');
        // $schedule->command('catchGenerateProductsCsv')->everyTwoHours()->between('02:00','23:59');
        // $schedule->command('catchGenerateProductsCsv')->cron('18 2 */4 * *');
        // $schedule->command('catchGenerateOffersCsv')->everyFifteenMinutes()->between('02:00', '23:59')
        //     ->after(function () {
        //         $this->call('catchSubmitImports');
        //     });

        // $schedule->command('catchSubmitImports')->everyThirtyMinutes()->between('02:00', '23:59');


        /**
         * Shopify Crons
         */

        // $schedule->command('shopifyGetProducts')->everyTwoHours()->after(function () {
        //     $this->call('shopifyCreateProduct');
        // });

        $schedule->command('shopifyGetProducts')->cron("5 */3 * * *")->after(function () {
            $this->call('shopifyCreateProduct');
        });


        // $schedule->command('shopifyCreateProduct')->everyThreeHours();

        // $schedule->command('shopifyUpdateInventory')->everyFifteenMinutes(); // Replaced by shopifyUpdatePriceInventory
        // $schedule->command('shopifyUpdatePrice')->everyFifteenMinutes(); // Replaced by shopifyUpdatePriceInventory
        // $schedule->command('shopifyUpdatePriceInventory')->everyFifteenMinutes(); // Now runs after getProductsFromEWebMain
        $schedule->command('shopifyRetryFailedInventoryUpdates')->hourly(); // Retry failed inventory updates
        $schedule->command('shopifyUploadImages')->everyThreeHours();
        $schedule->command('shopifyArchiveProducts')->cron('20 */3 * * *');

        $schedule->command('shopify:update-product')->cron('0 20 * * *');

        $schedule->command('shopifyCountImages')->dailyAt('17:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
