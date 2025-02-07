<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MainCommands extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mainCommands';

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
        $this->call('getProductsFromEWebMain');
        $this->call('getProductsFromEWebAmazon');
    }
}
