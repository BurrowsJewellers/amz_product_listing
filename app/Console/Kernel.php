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
        $schedule->command('getBrandsFromEWeb')->dailyAt('00:05');
        $schedule->command('getProductsFromEWeb')->everyThirtyMinutes();

        $schedule->command('submitAmzXmlFeed POST_PRODUCT_DATA')->hourlyAt(10);
        $schedule->command('submitAmzXmlFeed POST_PRODUCT_IMAGE_DATA')->hourlyAt(20);
        $schedule->command('submitAmzXmlFeed POST_PRODUCT_PRICING_DATA')->hourlyAt(25);
        $schedule->command('submitAmzXmlFeed POST_INVENTORY_AVAILABILITY_DATA')->everyTenMinutes();
        $schedule->command('checkAmzFeedStatus')->everyFifteenMinutes();

        $schedule->command('getAmzMerchantListingAllData')->dailyAt('00:10');
        $schedule->command('processAmzMerchantListingAllData')->dailyAt('02:10');
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
