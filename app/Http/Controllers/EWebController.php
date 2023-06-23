<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class EWebController extends ConfigController
{

    public function call($method, $params = [], $auth = true) {
        ini_set("default_socket_timeout", 600);
        $client = $this->getEwebSoapClient();
        $resp = $client->__soapCall($method, [$this->formatParams($params, $auth)]);
        $request = $client->__getLastRequest();
        // var_dump($request);
        return $resp;
    }

    public function formatParams($params, $auth) {
        return $auth ? array_merge($this->getEwebAuthenticationInfo(), $params) : $params;
    }

}
