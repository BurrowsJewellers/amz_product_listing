<?php

namespace App\Http\Controllers;

use SellingPartnerApi\Configuration;

class AmzConfigController extends Controller
{
    public static function getConfig($region = 'FE', $debug = false)
    {
        $options = [
            'lwaRefreshToken' => config('amazon.spapi.refresh_token'),
            'lwaClientId' => config('amazon.spapi.client_id'),
            'lwaClientSecret' => config('amazon.spapi.client_secret'),
            'awsAccessKeyId' => config('amazon.spapi.access_key'),
            'awsSecretAccessKey' => config('amazon.spapi.secret_key'),
            'endpoint' => constant("\SellingPartnerApi\Endpoint::$region"),
            'roleArn' => config('amazon.spapi.role_arn'),
        ];

        $config = new Configuration($options);

        if ($debug) {
            $config->setDebug(true);
            $config->setDebugFile(storage_path().'/logs/laravel.log');
        }

        return $config;
    }
}
