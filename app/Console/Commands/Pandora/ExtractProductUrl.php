<?php

namespace App\Console\Commands\Pandora;

use App\Models\PandoraList;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;

class ExtractProductUrl extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pandoraExtractProductUrl';

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
            $products = PandoraList::whereNull('product_url')->whereNotNull('search_response')->get();
            foreach ($products as $product) {
                try {
                    $html = $product->search_response;

                    // Create a new DOMDocument object
                    $dom = new DOMDocument();

                    // Load the HTML, using the @ to suppress warnings for malformed HTML
                    @$dom->loadHTML($html);

                    // Create a new DOMXPath object
                    $xpath = new DOMXPath($dom);

                    // Find the div with data-auto="divSearchSuggestionProduct"
                    $productDiv = $xpath->query("//*[@data-auto='divSearchSuggestionProduct']")->item(0);

                    if ($productDiv) {
                        // Find the 'a' tag within this div
                        $link = $xpath->query(".//a", $productDiv)->item(0);

                        $pData = [];
                        if ($link) {
                            $href = $link->getAttribute('href');

                            $pData['product_url'] = "https://au.pandora.net" . $href;

                            echo "Href: " . $href . "\n";
                            // Find the span with data-auto="lblSearchProductName"
                            $nameSpan = $xpath->query("//*[@data-auto='lblSearchProductName']")->item(0);

                            if ($nameSpan) {
                                $productName = $nameSpan->textContent;
                                $pData['product_name'] = $productName;
                                echo "Product Name: " . $productName . "\n";
                            } else {
                                echo "Product name not found\n";
                            }

                            $product->update($pData);
                        } else {
                            echo "Link not found\n";
                        }
                    } else {
                        echo "Product div not found\n";
                    }
                } catch (\Exception $e) {
                    $this->error($e->getMessage());
                    report($e);
                }
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            report($e);
        }
    }
}
