<?php

namespace App\Providers;

use App\Services\Amazon\AmazonSpApiService;
use App\Services\Amazon\CatalogService;
use App\Services\Amazon\ListingService;
use Illuminate\Support\ServiceProvider;

class AmazonServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(CatalogService::class, function ($app) {
            return new CatalogService(
                $app->make(AmazonSpApiService::class),
                $app->make(ListingService::class)
            );
        });

        $this->app->bind(ListingService::class, function ($app) {
            return new ListingService(
                $app->make(AmazonSpApiService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
