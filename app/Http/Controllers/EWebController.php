<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class EWebController extends ConfigController
{

    public function call($method, $params = [], $auth = true) {
        $client = $this->getEwebSoapClient();
        $resp = $client->__soapCall($method, [$this->formatParams($params, $auth)]);
        return $resp;
    }

    public function formatParams($params, $auth) {
        return $auth ? array_merge($this->getEwebAuthenticationInfo(), $params) : $params;
    }


}
