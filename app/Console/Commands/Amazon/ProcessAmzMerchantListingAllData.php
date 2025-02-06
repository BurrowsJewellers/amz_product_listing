<?php

namespace App\Console\Commands\Amazon;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\AmzReportController;
use App\Models\AmzRequestedReport;
use App\Models\Product;
use Exception;
use InvalidArgumentException;

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


    private const REQUIRED_COLUMNS = ['asin1', 'seller-sku', 'status', 'price', 'quantity'];
    private const BATCH_SIZE = 1000; // Process records in batches

    /**
     * Execute the console command.
     */

    public function handle()
    {
        $marketplace = 'Amazon';
        $jobType = 'processAmzMerchantListingAllData';
        $job = SyncJobController::getJob($jobType, $marketplace);

        if ($job->isRunning()) {
            Log::info("$marketplace $jobType is already running.");
            return;
        }

        Log::info("$marketplace $jobType started!");
        $job->update(['status' => 1]);

        try {
            $this->processReport($job);
        } catch (Exception $e) {
            $this->handleError($job, $e);
        }

        Log::info("$marketplace $jobType finished!");
    }

    private function processReport($job)
    {
        $reportType = 'GET_MERCHANT_LISTINGS_ALL_DATA';
        $skuArray = [];

        $reportController = new AmzReportController();
        $reportController->downloadReports();

        $report = $this->getLatestUnprocessedReport($reportType);

        if (!$report) {
            Log::info('No unprocessed reports found.');
            $job->update(['status' => 0]);
            return;
        }

        if (!Storage::exists($report->file_name)) {
            throw new InvalidArgumentException("Report file not found: {$report->file_name}");
        }

        $filePath = Storage::path($report->file_name);
        $this->processCSVFile($filePath, $report, $skuArray);

        $this->updateUnlistedProducts($skuArray);

        $job->update(['status' => 0, 'message' => null]);
    }

    private function getLatestUnprocessedReport($reportType)
    {
        return AmzRequestedReport::where([
            'report_type' => $reportType,
            'downloaded' => 1,
            'processed' => 0
        ])->orderBy('id', 'desc')->first();
    }

    private function processCSVFile($filePath, $report, &$skuArray)
    {
        $report->update(['processed' => 3]); // Mark as processing

        if (($handle = fopen($filePath, "r")) === FALSE) {
            throw new Exception("Failed to open file: $filePath");
        }

        try {
            $headers = $this->validateHeaders(fgetcsv($handle, 0, "\t"));
            $batch = [];
            $rowCount = 0;

            while (($data = fgetcsv($handle, 0, "\t")) !== FALSE) {
                if (empty(array_filter($data))) {
                    continue; // Skip empty rows
                }

                try {
                    $productData = $this->mapProductData($data, $headers);
                    $skuArray[] = $productData['sku'];
                    $batch[] = $productData;
                    $rowCount++;

                    if (count($batch) >= self::BATCH_SIZE) {
                        $this->processBatch($batch);
                        $batch = [];
                    }
                } catch (Exception $e) {
                    Log::warning("Error processing row $rowCount: " . $e->getMessage());
                    continue;
                }
            }

            // Process remaining batch
            if (!empty($batch)) {
                $this->processBatch($batch);
            }

            $report->update(['processed' => 1]);
        } finally {
            fclose($handle);
        }
    }

    private function validateHeaders($headers)
    {
        if (!$headers) {
            throw new InvalidArgumentException("Failed to read CSV headers");
        }

        $columnIndexes = [];
        foreach (self::REQUIRED_COLUMNS as $requiredColumn) {
            $index = array_search($requiredColumn, $headers);
            if ($index === false) {
                throw new InvalidArgumentException("Required column '$requiredColumn' not found in CSV");
            }
            $columnIndexes[$requiredColumn] = $index;
        }

        return $columnIndexes;
    }

    private function mapProductData($row, $headers)
    {
        if (count($row) < max(array_values($headers))) {
            throw new InvalidArgumentException("Row has insufficient columns");
        }

        return [
            'sku' => $row[$headers['seller-sku']],
            'asin' => $row[$headers['asin1']],
            'status' => $row[$headers['status']],
            'price' => $row[$headers['price']],
            'quantity' => $row[$headers['quantity']]
        ];
    }

    private function processBatch($batch)
    {
        foreach ($batch as $productData) {
            $product = Product::where('sku', $productData['sku'])->first();

            if ($product) {
                $product->update([
                    'asin' => $productData['asin'],
                    'status' => $productData['status'],
                    'published' => !empty($productData['asin']) ? 1 : 0,
                    'price' => $productData['price'],
                    'quantity' => $productData['quantity']
                ]);
            }
        }
    }

    private function updateUnlistedProducts($skuArray)
    {
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

        Product::whereNull('asin')
            ->orWhereNotIn('sku', array_values($skuArray))
            ->update($dataToBeUpdated);
    }

    private function handleError($job, Exception $e)
    {
        $errorMessage = "Error in {$e->getFile()} : {$e->getMessage()} Line : {$e->getLine()}";
        report($e);
        $job->update([
            'status' => 0,
            'message' => $errorMessage
        ]);
    }

    public function handleBackup()
    {
        $marketplace = 'Amazon';
        $jobType = 'processAmzMerchantListingAllData';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
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
                            if ($skuIndex !== false && $asinIndex !== false && $statusIndex !== false) {
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

                                        // $product = Product::where(['sku' => $productArray[$skuIndex]])->update([
                                        //     'asin' => $asin,
                                        //     'status' => $productArray[$statusIndex],
                                        //     'published' => $asin ? 1 : 0,
                                        // ]);

                                        $product = Product::where('sku', $productArray[$skuIndex])->first();

                                        if ($product) {
                                            $product->update([
                                                'asin' => $asin,
                                                'status' => $productArray[$statusIndex],
                                                'published' => $asin ? 1 : 0,
                                            ]);
                                        }
                                    } catch (\Exception $e) {
                                        var_dump($e->getMessage());
                                        Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() . ' Line : ' . $e->getLine());
                                    }
                                }

                                $processed = 1;
                            } else {
                                Log::debug('Required fields not found in report. ' . $report->file_name);
                                $processed = 2;
                            }
                        } else {
                            $processed = 2;
                            Log::debug('No records found in report. ' . $report->file_name);
                        }
                    } else {
                        $processed = 2;
                        Log::debug('Report not found. ' . $report->file_name);
                    }
                    $report->update(['processed' => $processed]);

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
                    Product::whereNotIn('sku', array_values($skuArray))->update($dataToBeUpdated);

                    // foreach ($skuArray as $sku) {
                    //     Product::where('sku', $sku)->update($dataToBeUpdated);
                    // }
                }


                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() . ' Line : ' . $e->getLine());
            }

            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
