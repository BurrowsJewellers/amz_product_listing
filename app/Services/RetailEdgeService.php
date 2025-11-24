<?php

namespace App\Services;

use App\Models\DownloadLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class RetailEdgeService extends RetailEdgeConnectionService
{
    const STORAGE_FILE = 'retail_edge.json';

    private function getCacheMinutes(): int
    {
        return (int) config('retail_edge.cache_minutes', 15);
    }

    public function getAllActiveItems()
    {
        if ($this->hasValidCache()) {
            echo "Retail edge file is already in latest version!\n";

            return $this->getCachedItems();
        }

        echo "Downloading Retail edge file!\n";

        return $this->fetchAndCacheItems();
    }

    public function getActiveItemBySKU(string $sku)
    {
        $skuParts = explode('-', $sku);

        if (count($skuParts) == 2) {
            $sku = '001-'.$skuParts[0].'-'.$skuParts[1];
        }

        $resp = $this->call('GetActiveItemBySKU', ['SKU' => $sku]);

        return $resp->GetActiveItemBySKUResult;
    }

    public function hasValidCache(): bool
    {
        // First check if the cache file exists
        if (! Storage::exists(self::STORAGE_FILE)) {
            return false;
        }

        // Then check the database record and timestamp
        $lastDownload = DownloadLog::where('type', 'retail_edge')
            ->latest('last_download')
            ->first();

        if (! $lastDownload) {
            return false;
        }

        // Check if the cache is still fresh
        $now = Carbon::now();
        $cacheExpiryTime = $lastDownload->last_download->addMinutes($this->getCacheMinutes());

        return $now->lt($cacheExpiryTime); // Returns true if current time is less than expiry time
    }

    private function getCachedItems()
    {
        $cachedData = Storage::get(self::STORAGE_FILE);

        return json_decode($cachedData);
    }

    private function fetchAndCacheItems()
    {
        $resp = $this->call('GetAllActiveItems');
        $activeItems = $resp->GetAllActiveItemsResult->ActiveItem;

        // Save the data
        Storage::put(self::STORAGE_FILE, json_encode($activeItems));

        // Update the download log
        DownloadLog::updateOrCreate(
            ['type' => 'retail_edge'],
            ['last_download' => Carbon::now()]
        );

        return $activeItems;
    }
}
