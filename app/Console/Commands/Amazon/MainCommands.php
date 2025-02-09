<?php

namespace App\Console\Commands\Amazon;

use Illuminate\Console\Command;

class MainCommands extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mainCommandsAmazon';

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
        // $this->call('getProductsFromEWebMain');
        $this->call('getProductsFromEWebAmazon');
    }
}
