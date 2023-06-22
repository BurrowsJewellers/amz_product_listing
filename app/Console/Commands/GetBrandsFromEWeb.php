<?php

namespace App\Console\Commands;

use App\Http\Controllers\EWebController;
use App\Models\Brand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GetBrandsFromEWeb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'getBrandsFromEWeb';

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
        try {
            $eWeb = new EWebController;
            $resp = $eWeb->call('GetAllBrands');

            foreach ($resp->GetAllBrandsResult->Brand as $brand){
                Brand::firstOrCreate(['name' => $brand->Name, 'brand_id' => $brand->ID]);
                $this->info($brand->Name);
            }
        } catch (\Exception $e) {
            Log::debug('getBrandsFromEWeb : '. $e->getMessage());
            dd($e->getMessage());
        }
    }
}
