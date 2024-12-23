<?php

namespace App\Console\Commands\Pandora;

use App\Models\PandoraList;
use App\Models\RetailEdgeProduct;
use App\Services\PandoraScraperService;
use Illuminate\Console\Command;

class ScrapeImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pandoraScrapeImages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $brandId = '1-21'; //Pandora
            $retailEdgeProducts = RetailEdgeProduct::where('brand_id', $brandId)->with('pandoraScraped:id,design_no,images')->select('id', 'sku', 'real_design_number', 'brand_id')->get();

            foreach ($retailEdgeProducts as $retailEdgeProduct) {
                $this->info($retailEdgeProduct->sku);
                if (is_null($retailEdgeProduct->pandoraScraped?->images)) {
                    $this->info('Scraping Pandora Images for ' . $retailEdgeProduct->sku);
                    $pandoraService = new PandoraScraperService();
                    $pandoraService->getPandoraProductByDesignNo($retailEdgeProduct->real_design_number);
                    PandoraList::where('design_no', $retailEdgeProduct->real_design_number)->update(['sku' => $retailEdgeProduct->sku]);
                    $this->info('Pandora Images scraped for ' . $retailEdgeProduct->sku);
                }
                sleep(10);
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            report($e);
        }
    }
}
