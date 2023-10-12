<?php

namespace App\Console\Commands\Catch;

use App\Http\Controllers\Catch\ImportController;
use App\Http\Controllers\SyncJobController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SubmitImports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catchSubmitImports';

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
        $marketplace = 'Catch';
        $jobType = 'catchSubmitImports';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $importController = new ImportController();
                $importController->uploadImport();

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e) {
                report($e);
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
            }

            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }

}
