<?php

namespace App\Console\Commands\Amazon;

use App\Http\Controllers\SyncJobController;
use App\Services\Amazon\CatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ListProducts extends Command
{
    protected $signature = 'amazonListProducts';
    protected $description = 'List new products on Amazon Marketplace';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Amazon';
        $jobType = 'amazonListProducts';
        $job = SyncJobController::getJob($jobType, $marketplace);

        if ($job->isRunning()) {
            Log::info("$marketplace $jobType is already running.");
            return;
        }

        Log::info("$marketplace $jobType started!");
        $job->update(['status' => 1]);

        try {
            (new CatalogService())->searchItem();
            $job->update(['status' => 0]);
        } catch (\Exception $e) {
            $this->handleError($job, $e);
        }

        Log::info("$marketplace $jobType finished!");
    }


    private function handleError($job, \Exception $e)
    {
        $errorMessage = "Error in {$e->getFile()} : {$e->getMessage()} Line : {$e->getLine()}";
        // Log::error($errorMessage);
        report($e);
        $job->update([
            'status' => 0,
            'message' => $errorMessage
        ]);
    }
}
