<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\EWebController;
use App\Http\Controllers\SyncJobController;
use App\Models\Brand;

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
        $marketplace = 'EWeb';
        $jobType = 'getBrandsFromEWeb';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $eWeb = new EWebController;
                $resp = $eWeb->call('GetAllBrands');

                foreach ($resp->GetAllBrandsResult->Brand as $brand){
                    Brand::firstOrCreate(['name' => $brand->Name, 'brand_id' => $brand->ID]);
                    $this->info($brand->Name);
                }
                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e) {
                Log::debug('getBrandsFromEWeb : '. $e->getMessage());
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
            }

            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }

    }
}
