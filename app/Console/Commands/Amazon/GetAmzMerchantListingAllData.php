<?php

namespace App\Console\Commands\Amazon;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\AmzReportController;
use App\Models\AmzMarketplace;
use Illuminate\Support\Facades\Artisan;

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

        try {
            $job = SyncJobController::getJob($jobType, $marketplace);

            if (!$job->isRunning()) {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                try {
                    $marketplaces = AmzMarketplace::active()->get();
                    $reportType = 'GET_MERCHANT_LISTINGS_ALL_DATA';
                    $params = [];

                    if ($marketplaces->count()) {
                        $params['fromDate'] = now()->subDay()->startOfDay()->format('Y-m-d');
                        $params['toDate'] = now()->format('Y-m-d');

                        $reportController = new AmzReportController();
                        foreach ($marketplaces as $amzMarketplace) {
                            try {
                                $reportController->requestReport($reportType, $amzMarketplace, $params);
                            } catch (\Exception $e) {
                                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                                report($e);
                            }
                        }
                    }

                    $job->update(['status' => 0, 'message' => null]);
                } catch (\Exception $e) {
                    $job->update(['status' => 0, 'message' => $e->getMessage()]);
                    report($e);
                }

                Log::info("$marketplace $jobType finished!");
            } else {
                Log::info("$marketplace $jobType is already running.");
            }
        } catch (\Exception $e) {
            // Handle any errors that might occur during job retrieval or status checking
            if (isset($job)) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
            }
            report($e);
            Log::error("$marketplace $jobType failed: " . $e->getMessage());
        }

        sleep(900);

        Artisan::call('processAmzMerchantListingAllData');
    }
}
