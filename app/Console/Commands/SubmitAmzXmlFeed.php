<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\AmzFeedController;
use Illuminate\Support\Facades\Log;

class SubmitAmzXmlFeed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'submitAmzXmlFeed {type}';

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
            $type = $this->argument('type');
            $feedController = new AmzFeedController();
            $feedController->submitFeed($type);
        } catch (\Exception $e) {
            Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() . ' Line : ' . $e->getLine());
        }
    }
}
