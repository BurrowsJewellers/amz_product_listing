<?php

namespace App\Console\Commands;

use App\Http\Controllers\ConfigController;
use App\Http\Controllers\EWebController;
use Illuminate\Console\Command;

class TestEWeb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'testEWeb';

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
        $eWeb = new EWebController;
        $params = ["SKU" => "001-022-04646"];
        $resp = $eWeb->call('GetActiveItemBySKU', $params);
        dd($resp);
    }
}
