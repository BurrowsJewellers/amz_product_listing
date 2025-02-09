<?php

namespace App\Console\Commands\Amazon;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\SyncJobController;
use App\Http\Controllers\AmzReportController;
use App\Models\AmzRequestedReport;
use App\Models\Product;
use App\Services\Amazon\ListingsReportService;
use Exception;
use InvalidArgumentException;

class ProcessAmzMerchantListingAllData extends Command
{
    protected $signature = 'processAmzMerchantListingAllData';
    protected $description = 'Process Amazon Merchant Listing All Data Report';

    private const REQUIRED_COLUMNS = ['asin1', 'seller-sku', 'status', 'price', 'quantity'];
    private const BATCH_SIZE = 1000; // Process records in batches

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

        (new ListingsReportService)->processReports();
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
                    'exists_on_amazon' => !empty($productData['asin']) ? 1 : 0,
                    'price' => $productData['price'],
                    'quantity' => $productData['quantity']
                ]);
            }
        }
    }

    private function updateUnlistedProducts($skuArray)
    {
        $dataToBeUpdated = [
            'json_generated' => 0,
            'submitted' => 0,
            'exists_on_amazon' => 0,
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
        // Log::error($errorMessage);
        report($e);
        $job->update([
            'status' => 0,
            'message' => $errorMessage
        ]);
    }
}
