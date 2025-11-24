<?php

namespace App\Console\Commands;

use App\Services\ShopifyService;
use Illuminate\Console\Command;
use Shopify\Auth\Session;
use Shopify\Rest\Admin2025_04\Order;
use Shopify\Rest\Admin2025_04\Product;

class ShopifyTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyTest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $session = (new ShopifyService)->getSession();

        // $p = Product::all(
        //     $session, // Session
        // );

        $p = Order::count(
            $session, // Session
        );

        var_dump($p);
        exit();
    }
}
