<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\AmzReportController;
use App\Models\AmzMarketplace;

class GetAmzMerchantListingAllData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'getAmzMerchantListingAllData';

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
        $marketplace = 'Amazon';
        $jobType = 'getAmzMerchantListingAllData';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $marketplaces = AmzMarketplace::active()->get();
                $reportType = 'GET_MERCHANT_LISTINGS_ALL_DATA';
                $params = [];

                if($marketplaces->count()){
                    $params['fromDate'] = now()->subDay()->startOfDay()->toISOString();
                    $params['toDate'] = now()->toISOString();
    
                    $reportController = new AmzReportController();
                    foreach($marketplaces as $marketplace){
                        $reportController->requestReport($reportType, $marketplace, $params);
                    }
                }

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e){
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
            }

            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
