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
         * Amazon Crons
         */
        $schedule->command('getBrandsFromEWeb')->dailyAt('00:05');
        $schedule->command('getProductsFromEWeb')->everyFifteenMinutes();

        // $schedule->command('generateAmzProductsXml')->cron('10,25,40,55 * * * *');
        $schedule->command('generateAmzProductsXml')->cron('44 */3 * * *');
        // $schedule->command('submitAmzXmlFeed POST_PRODUCT_DATA')->cron('12,27,42,57 * * * *');
        $schedule->command('submitAmzXmlFeed POST_PRODUCT_DATA')->cron('47 */3 * * *');

        $schedule->command('generateAmzInventoryXml')->cron('13,28,43,58 * * * *');
        $schedule->command('generateAmzPriceXml')->hourly();
        $schedule->command('generateAmzImagesXml')->hourly();

        $schedule->command('submitAmzXmlFeed POST_PRODUCT_IMAGE_DATA')->hourlyAt(20);
        $schedule->command('submitAmzXmlFeed POST_PRODUCT_PRICING_DATA')->hourlyAt(25);
        $schedule->command('submitAmzXmlFeed POST_INVENTORY_AVAILABILITY_DATA')->everyTenMinutes();
        $schedule->command('checkAmzFeedStatus')->everyFifteenMinutes();

        $schedule->command('getAmzMerchantListingAllData')->everyThreeHours();
        $schedule->command('processAmzMerchantListingAllData')->cron('32 */3 * * *');


        /**
         * Catch Crons
         */

        // The following three cron jobs must run in the same sequence.
        $schedule->command('getProductsFromEWebCatch')->dailyAt('00:20');
        $schedule->command('catchCheckIfExists')->dailyAt('00:50');
        $schedule->command('catchListOffersOfShop')->dailyAt('01:20');
        
        $schedule->command('getProductsFromEWebCatch')->everyFifteenMinutes()->between('02:00','23:59');
        $schedule->command('catchGenerateProductsCsv')->everyFifteenMinutes()->between('02:00','23:59');
        $schedule->command('catchGenerateOffersCsv')->everyFifteenMinutes()->between('02:00','23:59');
        $schedule->command('catchSubmitImports')->hourly()->between('02:00','23:59');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
