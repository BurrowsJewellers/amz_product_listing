<?php

namespace App\Http\Controllers\Catch;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Catch\CatchImport;
use Illuminate\Support\Facades\Storage;
use Mirakl\MMP\Shop\Request\Offer\Importer\OfferImportRequest;

class ImportController extends Controller
{
    private $api;

    public function __construct()
    {
        $this->api = MiraklShopApiClient::getShopApiClient();
    }

    public function index()
    {
        if (request()->ajax()) {
            $imports = CatchImport::query();
            return datatables()->of($imports)
                ->addColumn('import_csv', function ($import) {
                    $link = route('catch.import.download', ['id' => $import->id, 'type' => 'import']);
                    return '<a href="' . $link . '">Download</a>';
                })
                ->addColumn('response_csv', function ($import) {
                    if ($import->response_file_name) {
                        $link = route('catch.import.download', ['id' => $import->id, 'type' => 'response']);
                        return '<a href="' . $link . '">Download</a>';
                    } else {
                        return '';
                    }
                })
                ->editColumn('created_at', function ($import) {
                    return $import->created_at->format('Y-m-d H:i:s');
                })
                ->editColumn('updated_at', function ($import) {
                    return $import->updated_at->format('Y-m-d H:i:s');
                })

                ->rawColumns(['import_csv', 'response_csv'])
                ->toJson();
        }
        return view('catch.imports.index');
    }

    public function downloadCsv(Request $request)
    {
        if ($request->filled('id') && $request->filled('type')) {
            $import = CatchImport::where('id', $request->id)->first();

            $file = null;
            if ($request->type == 'import') {
                $file = $import->file_name;
            } elseif ($request->type == 'response') {
                $file = $import->response_file_name;
            }

            if (!Storage::disk('local')->exists($file)) {
                return 'Report not found!';
            }

            if ($file) {
                return response()->download(storage_path('/app/' . $file));
            } else {
                return 'Report not found!';
            }
        } else {
            return 'Report not found!';
        }
    }


    public function uploadImport($importTypes)
    {
        try {
            $imports = CatchImport::whereIn('import_type', $importTypes)->where(['submitted' => 0])->get();

            $api = MiraklShopApiClient::getShopApiClient();        
            foreach ($imports as $import) {
                try {
                    $filePath = storage_path('/app') . '/' .$import->file_name;
                    $file = new \SplFileObject($filePath);
    
                    $request = new OfferImportRequest($file);

                    if ($import->import_type == 'product') {
                        $request->setWithProducts(true); // Optional
                    }

                    // if ($import->import_type == 'offer') {
                        $request->setImportMode('NORMAL');
                    // }

                    // $request->setImportMode(\Mirakl\MMP\OperatorShop\Domain\Offer\Importer\ImportMode::PARTIAL_UPDATE); // Optional

                    $result = $api->importOffers($request);
                    $importId = $result->getImportId();

                    $import->update([
                        'import_id' => $importId,
                        'submitted' => 1,
                    ]);

                    // $result => @see \Mirakl\MMP\OperatorShop\Domain\Offer\Importer\OfferImportTracking
                } catch (\Exception $e) {
                    report($e);
                }
            }
        } catch (\Exception $e) {
            report($e);
        }
    }
}
