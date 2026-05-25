<?php

namespace App\Console\Commands\EWeb;

use App\Http\Controllers\EWebController;
use App\Http\Controllers\SyncJobController;
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
    public function handle(): int
    {
        $marketplace = 'EWeb';
        $jobType = 'getBrandsFromEWeb';

        $job = SyncJobController::acquireLock($jobType, $marketplace);

        if (! $job) {
            $this->warn('Job is paused or already running.');

            return Command::SUCCESS;
        }

        Log::info("$marketplace $jobType started!");

        try {
            $eWeb = new EWebController;
            $resp = $eWeb->call('GetAllBrands');

            foreach ($resp->GetAllBrandsResult->Brand as $brand) {
                Brand::updateOrCreate(
                    [
                        'brand_id' => $brand->ID,
                    ],
                    [
                        'name' => $brand->Name,
                    ]
                );
                $this->info($brand->Name);
            }
            $job->finishJob();
        } catch (\Exception $e) {
            report($e);
            $job->finishJob($e->getMessage());
        }

        Log::info("$marketplace $jobType finished!");

        return Command::SUCCESS;
    }
}
