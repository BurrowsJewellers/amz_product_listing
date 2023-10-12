<?php

namespace App\Http\Controllers\Catch;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use App\Models\Catch\CatchImport;
use Mirakl\MMP\Shop\Request\Offer\Importer\OfferImportRequest;

class ImportController extends Controller
{
    private $api;

    public function __construct()
    {
        $this->api = MiraklShopApiClient::getShopApiClient();
    }

    public function uploadImport($importType = 'product')
    {
        try {
            $imports = CatchImport::where(['import_type' => $importType, 'submitted' => 0])->get();

            $api = MiraklShopApiClient::getShopApiClient();        
            foreach ($imports as $import) {
                try {
                    $filePath = storage_path('/app') . '/' .$import->file_name;
                    $file = new \SplFileObject($filePath);
    
                    $request = new OfferImportRequest($file);

                    if ($import->import_type == 'product') {
                        $request->setWithProducts(true); // Optional
                    }
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
