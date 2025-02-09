<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request as Req;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SellingPartnerApi\Api\ReportsV20210630Api;
use SellingPartnerApi\Document;
use App\Models\AmzRequestedReport;
use App\Services\Amazon\AmazonSpApiService;
use SellingPartnerApi\Seller\ReportsV20210630\Dto\CreateReportSpecification;
use DateTime;

class AmzReportController extends Controller
{
    public function requestReport($reportType, $marketplace, $params)
    {
        try {
            $dataStartTime = null;
            $dataEndTime = null;

            if (isset($params['fromDate'])) {
                $dataStartTime = new DateTime($params['fromDate']);
            }

            if (isset($params['toDate'])) {
                $dataEndTime = new DateTime($params['toDate']);
            }

            $specification = new CreateReportSpecification(reportType: $reportType, marketplaceIds: [$marketplace->marketplace_id], dataStartTime: $dataStartTime, dataEndTime: $dataEndTime);

            $sellerConnector = (new AmazonSpApiService())->getSellerConnector();
            $reportsApi = $sellerConnector->reportsV20210630();

            $response = $reportsApi->createReport($specification);

            $createReportResponse = $response->dto();

            if ($createReportResponse->reportId) {
                $reportId = $createReportResponse->reportId;
                $file_name = $reportType . '_' . $reportId . '.txt';

                AmzRequestedReport::create([
                    'report_id' => $reportId,
                    'report_type' => $reportType,
                    'file_name' => $file_name,
                    'amz_marketplace_id' => $marketplace->id,
                ]);
            } else {
                Log::debug(print_r($response, true));
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public static function downloadReports()
    {
        try {
            $reports = AmzRequestedReport::with('marketplace')->whereNotNull('report_id')->where(['downloaded' => 0, 'processed' => 0])->get();

            if ($reports->count()) {
                foreach ($reports as $report) {
                    $sellerConnector = (new AmazonSpApiService())->getSellerConnector();
                    $reportsApi = $sellerConnector->reportsV20210630();
                    Log::info("Downloading $report->report_type report.");

                    $response = $reportsApi->getReport($report->report_id);

                    $response = $response->dto();

                    $reportDocumentId = $response->reportDocumentId;
                    $processingStatus = $response->processingStatus;

                    echo "The report {$report->report_type} is in {$processingStatus} status with document id {$reportDocumentId} \n";

                    if ($reportDocumentId && $processingStatus) {
                        if ($processingStatus == 'DONE') {
                            $response = $reportsApi->getReportDocument($reportDocumentId, $report->report_type);
                            $reportDocument = $response->dto();

                            /*
                             * - Array of arrays, where each sub array corresponds to a row of the report, if this is a TSV, CSV, or XLSX report
                             * - A nested associative array (from json_decode) if this is a JSON report
                             * - The raw report data if this is a TXT or PDF report
                             * - A SimpleXMLElement object if this is an XML report
                             */
                            // $contents = $reportDocument->download(documentType: $report->report_type, postProcess: false);
                            $contents = $reportDocument->download(postProcess: false);

                            // Storage::disk('local')->put($report->file_name, json_encode($contents));
                            Storage::disk('local')->put($report->file_name, $contents);
                            $report->update(['downloaded' => 1]);
                            Log::info("Downloaded $report->report_type");
                        } elseif ($processingStatus == 'CANCELLED' || $processingStatus == 'FATAL') {
                            $report->update(['downloaded' => 2, 'processed' => 2]);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // report($e);
            throw $e;
        }
    }

    public function amazonReports()
    {
        if (request()->ajax()) {
            $reports = AmzRequestedReport::with('marketplace');
            return datatables()->of($reports)
                ->addColumn('download', function ($report) {
                    $link = route('amazon.report.download', ['id' => $report->id, 'type' => 'feed']);
                    return '<a href="' . $link . '">Download</a>';
                })
                ->editColumn('created_at', function ($report) {
                    return $report->created_at->format('Y-m-d H:i:s');
                })
                ->editColumn('updated_at', function ($report) {
                    return $report->updated_at->format('Y-m-d H:i:s');
                })
                ->rawColumns(['download'])
                ->toJson();
        }
        return view('amazon.reports');
    }


    public function downloadReport(Req $request)
    {
        if ($request->filled('id')) {
            $report = AmzRequestedReport::where('id', $request->id)->first();

            if ($report) {
                if (!Storage::disk('local')->exists($report->file_name)) {
                    return 'Report not found!';
                }

                return response()->download(storage_path('/app/' . $report->file_name));
            } else {
                return 'Report not found!';
            }
        } else {
            return 'Report not found!';
        }
    }
}
