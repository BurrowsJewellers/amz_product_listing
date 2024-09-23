<?php

namespace App\Services;

use App\Models\PandoraList;
use Symfony\Component\Process\Process;

class PandoraScraperService
{

    public function scrape(string $designNo): bool
    {
        try {
            $process = new Process(['python3', '/opt/bitnami/projects/amz_product_listing/pandora-scraper/scrape_v2.py', $designNo]);
            $process->run();
            if ($process->isSuccessful()) {
                return true;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    public function getImageUrls(string $designNo): array
    {
        try {
            $pandoraProduct = $this->getPandoraProductByDesignNo($designNo);
            return json_decode($pandoraProduct->images);
        } catch (\Exception $e) {
            report($e);
            return [];
        }
    }

    public function getPandoraProductByDesignNo(string $designNo): PandoraList|null
    {
        try {
            $pandoraProduct = PandoraList::where('design_no', $designNo)->whereNotNull('images')->first();

            if (!$pandoraProduct) {
                $this->scrape($designNo);
            }

            return PandoraList::where('design_no', $designNo)->whereNotNull('images')->firstOrFail();
        } catch (\Exception $e) {
            report($e);
            return null;
        }
    }
}
