<?php

namespace App\Services\Amazon;

use SellingPartnerApi\SellingPartnerApi;
use SellingPartnerApi\Seller\SellerConnector;

class AmazonSpApiService
{
    public static function getSellerConnector($region = 'FE', $debug = false): SellerConnector
    {
        return SellingPartnerApi::seller(
            clientId: config('amazon.spapi.client_id'),
            clientSecret: config('amazon.spapi.client_secret'),
            refreshToken: config('amazon.spapi.refresh_token'),
            endpoint: constant("SellingPartnerApi\Enums\Endpoint::$region"),
        );
    }
}
