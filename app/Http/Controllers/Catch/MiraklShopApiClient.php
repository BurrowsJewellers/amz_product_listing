<?php

namespace App\Http\Controllers\Catch;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Mirakl\MMP\Shop\Client\ShopApiClient;

class MiraklShopApiClient extends Controller
{

    public static function getShopApiClient() {
        return new ShopApiClient(config('catch.api_url'), config('catch.api_key'));
    }
}
