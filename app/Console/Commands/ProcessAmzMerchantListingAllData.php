<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\AmzReportController;
use App\Models\AmzRequestedReport;
use App\Models\Product;

class ProcessAmzMerchantListingAllData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'processAmzMerchantListingAllData';

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
        $jobType = 'processAmzMerchantListingAllData';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if(!$job->isRunning()){
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $reportType = 'GET_MERCHANT_LISTINGS_ALL_DATA';
                $skuArray = [];

                $reportController = new AmzReportController();
                $reportController->downloadReports();

                $report = AmzRequestedReport::where(['report_type' => $reportType, 'downloaded' => 1, 'processed' => 0])->orderBy('id', 'desc')->first();

                if ($report) {
                    $processed = 2;
                    if (Storage::exists($report->file_name)) {
                        $contents = Storage::disk('local')->get($report->file_name);
            
                        $list = explode("\n",  $contents);
            
                        if (!empty($list)) {
                            $headings = explode("\t", $list[0]);
                            
                            $asinIndex = array_search('asin1', $headings);
                            $skuIndex = array_search('seller-sku', $headings);
                            $statusIndex = array_search('status', $headings);
                            $priceIndex = array_search('price', $headings);
                            $quantityIndex = array_search('quantity', $headings);
            
                            // dd($headings);
                            $product = null;
                            if($skuIndex !== false && $asinIndex !== false && $statusIndex !== false) {
                                // dd('ok');
                                $report->update(['processed' => 3]);
                                $report = $report->refresh();
                                // dd(count($list));

                                for ($i = 1; $i < count($list) - 1; $i++) {
                                    try {
                                        $productArray = array();
                                        $productArray = explode("\t", $list[$i]);
                                        // $this->info('count '. count($productArray));
        
                                        $asin = $asinIndex ? $productArray[$asinIndex] : null;
    
                                        // $this->info('asin '. $productArray[$asinIndex]);
                                        $skuArray[] = $productArray[$skuIndex];
        
                                        $product = Product::where(['sku' => $productArray[$skuIndex]])->update([
                                            'asin' => $asin,
                                            // 'price_feed_status' => !$productArray[$priceIndex] ? 0 : 1,
                                            // 'inventory_feed_status' => $productArray[$quantityIndex] == 0 ? 0 : 1,
                                            'status' => $productArray[$statusIndex],
                                            'published' => $asin ? 1 : 0,
                                        ]);
                                    } catch (\Exception $e) {
                                        var_dump($e->getMessage());
                                        Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
                                    }
                                }
    
                                $processed = 1;
                            } else {
                                Log::debug('Required fields not found in report. '.$report->file_name);
                                $processed = 2;
                            }
                        } else {
                            $processed = 2;
                            Log::debug('No records found in report. '.$report->file_name);
                        }
                    } else {
                        $processed = 2;
                        Log::debug('Report not found. '.$report->file_name);
                    }
                    $report->update(['processed' => $processed]);
                }
    
                // set the published to 0 for the products which does not have ASIN

                $dataToBeUpdated = [
                    'xml_generated' => 0,
                    'submitted' => 0,
                    'published' => 0,
                    'price_feed_status' => 0,
                    'image_feed_status' => 0,
                    'inventory_feed_status' => 0,
                    'status' => null,
                    'message' => null
                ];

                Product::whereNull('asin')->update($dataToBeUpdated);
                Product::whereNotIn('sku', $skuArray)->update($dataToBeUpdated);

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
