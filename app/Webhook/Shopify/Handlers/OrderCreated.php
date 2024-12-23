<?php

namespace App\Webhook\Shopify\Handlers;

use App\Jobs\Shopify\WebhookOrderCreated;
use Shopify\Webhooks\Handler;
use Shopify\Webhooks\Registry;

class OrderCreated implements Handler
{

    public function handle(string $topic, string $shop, array $requestBody): void
    {
        WebhookOrderCreated::dispatch($topic, $shop, $requestBody);
    }
}
