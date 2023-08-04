<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request as Req;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SellingPartnerApi\Api\ReportsV20210630Api;
use SellingPartnerApi\Model\ReportsV20210630\CreateReportSpecification;
use App\Http\Controllers\Amazon\AmazonConfigController;
use SellingPartnerApi\Document;
use App\Models\AmzRequestedReport;

class AmzReportController extends Controller
{
    public function requestReport($reportType, $marketplace, $params){
        try {
            $specification = new CreateReportSpecification();
            $specification->setReportType($reportType);
            $specification->setMarketplaceIds([$marketplace->marketplace_id]);
    
            if (isset($params['fromDate']) && isset($params['toDate'])) {
                $specification->setDataStartTime($params['fromDate']);
                $specification->setDataEndTime($params['toDate']);
            }

            $amz = new AmzConfigController();
            $config = $amz->getConfig($marketplace->region);

            $apiInstance = new ReportsV20210630Api($config);
            $response = $apiInstance->createReport($specification);
    
            if ($response->getReportId()) {
                $reportId = $response->getReportId();
                $file_name = $reportType.'_'. $reportId .'.txt';
    
                $requested_report = AmzRequestedReport::create([
                    'report_id' => $reportId,
                    'report_type' => $reportType,
                    'file_name' => $file_name,
                    'amz_marketplace_id' => $marketplace->id,
                ]);
            } else {
                Log::debug(print_r($response, true));
            }
        } catch (\Exception $e) {
            Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
        }
    }

    public static function downloadReports()
    {
        try {
            $reports = AmzRequestedReport::with('marketplace')->whereNotNull('report_id')->where(['downloaded' => 0, 'processed' => 0])->get();

            // dd($reports);
            if ($reports->count()) {
                foreach ($reports as $report) {
                    $amz = new AmzConfigController();
                    $config = $amz->getConfig($report->marketplace->region);
        
                    $apiInstance = new ReportsV20210630Api($config);
                    Log::info("Downloading $report->report_type report.");
                    $resp = $apiInstance->getReport($report->report_id);
    
                    $reportDocumentId = $resp->getReportDocumentId();
                    $processingStatus = $resp->getProcessingStatus();
    
                    if($reportDocumentId && $processingStatus){
                        if($processingStatus == 'DONE'){
                            $reportDocumentInfo = $apiInstance->getReportDocument($reportDocumentId, $report->report_type);
    
                            $reportType = constant("SellingPartnerApi\ReportType::$report->report_type");

                            $docToDownload = new Document($reportDocumentInfo, $reportType);
                            $contents = $docToDownload->download();
                
                            Storage::disk('local')->put($report->file_name, $contents);
                            $report->update(['downloaded' => 1]);
                            Log::info("Downloaded $report->report_type");
                        } elseif($processingStatus == 'CANCELLED' || $processingStatus == 'FATAL') {
                            $report->update(['downloaded'=> 2, 'processed' => 2]);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Error : " . $e->getFile() . ' : ' . $e->getMessage() .' Line : '. $e->getLine());
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