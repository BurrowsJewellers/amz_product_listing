<?php

namespace App\Console\Commands\Pandora;

use App\Models\PandoraList;
use Illuminate\Console\Command;
use DOMDocument;
use DOMXPath;

class ExtractImagesUrl extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pandoraExtractImagesUrl';

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
            $products = PandoraList::whereNull('images')->whereNotNull('product_url')->get();
            foreach ($products as $product) {
                try {
                    $html = $product->product_response;

                    // Create a new DOMDocument object
                    $dom = new DOMDocument();

                    // Load the HTML, using the @ to suppress warnings for malformed HTML
                    @$dom->loadHTML($html);

                    // Create a new DOMXPath object
                    $xpath = new DOMXPath($dom);

                    $elements = $xpath->query("//*[contains(@class, 'd-block') and contains(@class, 'img-fluid') and contains(@class, 'js-product-image')]");

                    $links = [];

                    if (is_array($elements)) {
                        foreach ($elements as $element) {
                            $data_img = $element->getAttribute('data-img');
                            if ($data_img) {
                                try {
                                    $img_data = json_decode($data_img, true);
                                    if (isset($img_data['hires'])) {
                                        $links[] = $img_data['hires'];
                                    }
                                } catch (\Exception $e) {
                                    echo "Could not parse JSON: " . $data_img . "\n";
                                }
                            }
                        }
                    }

                    // Convert the links array to JSON
                    $links_json = json_encode($links, JSON_PRETTY_PRINT);

                    if (!empty($links)) {
                        $product->update(['images' => $links_json]);
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
