<?php

namespace App\Console\Commands;

use App\Http\Controllers\AmzFeedController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CheckAmzFeedStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'checkAmzFeedStatus';

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
        $contents = Storage::disk('local')->get('52233019520_response.xml');
        $data = simplexml_load_string($contents);

        // dd($data);
        if ($data) {
            try {
                // $feed->update(['processing_status' => 3]);

                // $feed = $feed->refresh();

                $ProcessingReport = $data->Message->ProcessingReport;

                $MessagesWithError      = (int) $ProcessingReport->ProcessingSummary->MessagesWithError;
                $MessagesWithWarning    = (int) $ProcessingReport->ProcessingSummary->MessagesWithWarning;

                if ($MessagesWithError > 0 || $MessagesWithWarning > 0) {
                    foreach ($ProcessingReport->Result as $r) {
                        $sku        = $r->AdditionalInfo->SKU;
                        $message    = html_entity_decode($r->ResultDescription, ENT_QUOTES | ENT_HTML5);

                        Log::debug($sku . "\t". $message);
                        // $product = Product::where('sku', $sku)->first();

                        // if ($product) {
                        //     $update = $product->update([
                        //         // 'message'   => "$product->message\n\n $message",
                        //         'message'   => "$message",
                        //     ]);
                        // }
                    }
                }

                // $feed->update(['processing_status' => 4]);
            } catch (\Exception $e) {
                Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() . ' Line : ' . $e->getLine());
            }
        }








        // try {
        //     $feedController = new AmzFeedController();
        //     $feedController->checkFeedStatus();
        // } catch(\Exception $e) {
        //     Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
        // }
    }
}
