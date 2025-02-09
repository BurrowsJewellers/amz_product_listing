<?php

namespace App\Services\Amazon;

use App\Http\Controllers\AmzReportController;
use App\Models\Amazon\AmazonListings;
use App\Models\AmzRequestedReport;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ListingsReportService
{
    public function importListingsFromTsv(string $filePath, AmzRequestedReport $report): array
    {
        if (!Storage::exists($filePath)) {
            throw new \Exception("TSV file not found at path: {$filePath}");
            $report->update(['processed' => 2, 'api_response' => "TSV file not found at path: {$filePath}"]);
        }

        $content = Storage::get($filePath);
        $lines = explode("\n", $content);

        // Remove header row
        $header = str_getcsv(array_shift($lines), "\t");
        $header = array_map(function ($column) {
            return $this->normalizeColumnName($column);
        }, $header);

        $statistics = [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $statistics['total']++;

            try {
                $row = str_getcsv($line, "\t");
                $data = array_combine($header, $row);

                // Transform data
                $data = $this->transformListingData($data);

                // Create or update the listing
                AmazonListings::updateOrCreate(
                    ['listing_id' => $data['listing_id']],
                    $data
                );

                isset($data['id']) ? $statistics['updated']++ : $statistics['created']++;
            } catch (\Exception $e) {
                $statistics['failed']++;
                $statistics['errors'][] = "Error processing line {$statistics['total']}: {$e->getMessage()}";
            }
        }
        $report->update(['processed' => 1]);
        return $statistics;
    }

    private function normalizeColumnName(string $column): string
    {
        // Convert column names to snake_case and remove special characters
        $column = strtolower(trim($column));
        $column = str_replace('-', '_', $column);
        return $column;
    }

    private function transformListingData(array $data): array
    {
        // Convert y/n to boolean for item_is_marketplace
        $data['item_is_marketplace'] = strtolower($data['item_is_marketplace']) === 'y';

        // Parse date
        if (!empty($data['open_date'])) {
            $data['open_date'] = Carbon::createFromFormat(
                'd/m/Y H:i:s T',
                $data['open_date']
            );
        }

        // Convert price to decimal
        $data['price'] = floatval($data['price']);

        // Convert quantities to integers
        $data['quantity'] = intval($data['quantity']);
        $data['pending_quantity'] = intval($data['pending_quantity']);

        return $data;
    }

    public function processReports(): void
    {
        $reportType = 'GET_MERCHANT_LISTINGS_ALL_DATA';

        $reportController = new AmzReportController();
        $reportController->downloadReports();

        $report = AmzRequestedReport::where([
            'report_type' => $reportType,
            'downloaded' => 1,
            'processed' => 0
        ])->orderBy('id', 'desc')->first();

        if (!$report) {
            Log::info('No unprocessed reports found.');
            return;
        }

        if (!Storage::exists($report->file_name)) {
            throw new InvalidArgumentException("Report file not found: {$report->file_name}");
        }

        $this->importListingsFromTsv($report->file_name, $report);
    }
}
