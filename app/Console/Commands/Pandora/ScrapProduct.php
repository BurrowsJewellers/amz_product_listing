<?php

namespace App\Console\Commands\Pandora;

use App\Models\PandoraList;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;

class ScrapProduct extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pandoraScrapProducts';

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
            exit;
            $products = PandoraList::whereNull('search_response')->where(['discontinued' => false])->get();
            foreach ($products as $product) {
                try {
                    $client = new Client();
                    $jar = new CookieJar();
                    $response = $client->request('GET', "https://au.pandora.net/on/demandware.store/Sites-en-AU-Site/en_AU/SearchServices-GetSuggestions?q={$product->design_no}", [
                        'cookies' => $jar
                    ]);
                    $html = $response->getBody()->getContents();

                    // Create a new DOMDocument instance
                    $dom = new DOMDocument();

                    // Suppress errors due to malformed HTML
                    libxml_use_internal_errors(true);

                    // Load the HTML
                    $dom->loadHTML($html);

                    // Restore error handling
                    libxml_clear_errors();

                    // Create a new DOMXPath instance
                    $xpath = new DOMXPath($dom);

                    // Query for the link element
                    $link = $xpath->query('//div[@data-auto="divSearchSuggestionProduct"]/a');

                    // Query for the product name element
                    $productName = $xpath->query('//span[@data-auto="lblSearchProductName"]');

                    if ($link->length > 0 && $productName->length > 0) {
                        // Get the href attribute of the link
                        $href = $link->item(0)->getAttribute('href');

                        // Get the product name text
                        $name = $productName->item(0)->textContent;

                        $product->update(['search_response' => $response, 'product_name' => $name, 'product_url' => "https://au.pandora.net{$link}"]);

                        echo "Link: $href\n";
                        echo "Product Name: $name\n";
                    } else {
                        $product->update(['discontinued' => true]);

                        echo "No products found.\n";
                    }
                } catch (\Exception $e) {
                    $this->error($e->getMessage());
                    report($e);
                }

                sleep(60);
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            report($e);
        }
    }
}
