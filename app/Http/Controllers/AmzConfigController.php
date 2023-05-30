<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SellingPartnerApi\Configuration;

class AmzConfigController extends Controller
{
    public static function getConfig($region = 'NA', $debug = false){
        $options = [
            'lwaRefreshToken'       => config('spapi.refresh_token'),
            'lwaClientId'           => config('spapi.client_id'),
            'lwaClientSecret'       => config('spapi.client_secret'),
            'awsAccessKeyId'        => config('spapi.access_key'),
            'awsSecretAccessKey'    => config('spapi.secret_key'),
            'endpoint'              => constant("\SellingPartnerApi\Endpoint::$region"),
            'roleArn'               => config('spapi.role_arn'),
        ];

        $config = new Configuration($options);

        if($debug){
            $config->setDebug(true);
            $config->setDebugFile(storage_path() .'/logs/amz-api-debug.log');
        }

        return $config;
    }

}