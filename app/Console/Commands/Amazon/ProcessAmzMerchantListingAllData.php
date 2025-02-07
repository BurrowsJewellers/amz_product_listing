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
use JsonException;

class ProcessAmzMerchantListingAllData extends Command
{
    protected $signature = 'processAmzMerchantListingAllData';
    protected $description = 'Process Amazon Merchant Listing All Data Report';

    private const REQUIRED_FIELDS = ['asin1', 'seller-sku', 'status', 'price', 'quantity'];
    private const BATCH_SIZE = 1000;

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

        $this->processJSONFile($report, $skuArray);
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

    private function processJSONFile($report, &$skuArray)
    {
        $report->update(['processed' => 3]);

        try {
            $jsonContent = Storage::get($report->file_name);
            $data = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                throw new InvalidArgumentException("Invalid JSON structure");
            }

            $this->validateJsonStructure($data);
            $batch = [];

            foreach ($data as $index => $item) {
                try {
                    $productData = $this->mapProductData($item);
                    $skuArray[] = $productData['sku'];
                    $batch[] = $productData;

                    if (count($batch) >= self::BATCH_SIZE) {
                        $this->processBatch($batch);
                        $batch = [];
                    }
                } catch (Exception $e) {
                    Log::warning("Error processing item $index: " . $e->getMessage());
                    continue;
                }
            }

            if (!empty($batch)) {
                $this->processBatch($batch);
            }

            $report->update(['processed' => 1]);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("Invalid JSON format: " . $e->getMessage());
        }
    }

    private function validateJsonStructure($data)
    {
        if (empty($data)) {
            throw new InvalidArgumentException("Empty JSON data");
        }

        $firstItem = reset($data);
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($firstItem[$field])) {
                throw new InvalidArgumentException("Required field '$field' not found in JSON");
            }
        }
    }

    private function mapProductData($item)
    {
        return [
            'sku' => $item['seller-sku'],
            'asin' => $item['asin1'],
            'status' => $item['status'],
            'price' => $item['price'],
            'quantity' => $item['quantity'],
            'name' => $item['item-name'] ?? null,
            'description' => $item['item-description'] ?? null,
            'product_id' => $item['product-id'] ?? null
        ];
    }

    private function processBatch($batch)
    {
        foreach ($batch as $productData) {
            try {
                $product = Product::where('sku', $productData['sku'])->first();

                if ($product) {
                    $product->update([
                        'asin' => $productData['asin'],
                        'status' => $productData['status'],
                        'published' => !empty($productData['asin']) ? 1 : 0,
                        'price' => $productData['price'],
                        'quantity' => $productData['quantity'],
                        'name' => $productData['name'],
                        'description' => $productData['description'],
                        'product_id' => $productData['product_id']
                    ]);
                }
            } catch (Exception $e) {
                Log::error("Error updating product {$productData['sku']}: " . $e->getMessage());
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
        report($e);
        $errorMessage = "Error in {$e->getFile()} : {$e->getMessage()} Line : {$e->getLine()}";
        $job->update([
            'status' => 0,
            'message' => $errorMessage
        ]);
    }
}
