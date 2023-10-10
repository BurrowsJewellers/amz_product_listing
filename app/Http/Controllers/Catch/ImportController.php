<?php

namespace App\Http\Controllers\Catch;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    private $api;

    public function __construct()
    {
        $this->api = MiraklShopApiClient::getShopApiClient();        
    }


    
    

}
