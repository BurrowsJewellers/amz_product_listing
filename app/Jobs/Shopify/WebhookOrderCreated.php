<?php

namespace App\Jobs\Shopify;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class WebhookOrderCreated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $topic;
    public string $shop;
    public array $requestBody;

    /**
     * Create a new job instance.
     */
    public function __construct(string $topic, string $shop, array $requestBody)
    {
        $this->topic = $topic;
        $this->shop = $shop;
        $this->requestBody = $requestBody;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::debug('Message logged from WebhookOrderCreated job');
        Log::debug('topic : ' . $this->topic);
        Log::debug('shop : ' . $this->shop);
        Log::debug('requestBody : ' . print_r($this->requestBody, true));
    }
}
