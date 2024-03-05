<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Webhook\Shopify\Handlers\OrderCreated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shopify\Webhooks\Registry;
use Shopify\Webhooks\Topics;

class WebhookController extends Controller
{

    public function ordersCreate(Request $request)
    {
        try {
            Registry::addHandler(Topics::ORDERS_CREATE, new OrderCreated());
            $response = Registry::process($request->header(), $request->getContent());

            if ($response->isSuccess()) {
                return response('ok');
            } else {
                Log::error("Webhook handler failed with message: " . $response->getErrorMessage());
                return response('failed', 500);
            }
        } catch (\Exception $e) {
            report($e);
            return response('failed', 500);
        }
    }
}
