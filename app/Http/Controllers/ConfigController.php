<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ConfigController extends Controller
{

    public function getEwebSoapClient() {
        $webServiceUrl = config('marketplace.eweb.wsdl_url');

        $options = [
            'location' => config('marketplace.eweb.location_url'),
            'soap_version' => 'SOAP_1_1',
            'trace' => 1
        ];

        return new \SoapClient($webServiceUrl, $options);
    }

    
    public function getEwebAuthenticationInfo() {
        return [
            "AuthenticationInfo" => [
                "ClientNum" => config('marketplace.eweb.client_num'),
                "Password" => config('marketplace.eweb.password'),
                "SecurityCode" => config('marketplace.eweb.security_code'),
            ]
        ];
    }

}
