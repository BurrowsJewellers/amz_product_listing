<?php

namespace App\Console\Commands\Pandora;

use App\Models\PandoraList;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;

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
            $ids = PandoraList::whereNull('images')->whereNotNull('product_url')->pluck('id')->toArray();

            foreach ($ids as $id) {
                try {
                    $product = PandoraList::find($id);

                    if ($product) {
                        $this->info("Product design_no: {$product->design_no}");

                        $html = $product->product_response;

                        libxml_use_internal_errors(true); // Suppress libxml errors
                        $dom = new DOMDocument;
                        $dom->loadHTML($html);
                        libxml_clear_errors();

                        // Create a new DOMXPath object
                        $xpath = new DOMXPath($dom);

                        // Find all elements with the specified class
                        $elements = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' d-block img-fluid js-product-image ')]");

                        $links = [];

                        foreach ($elements as $element) {
                            $data_img = $element->getAttribute('data-img');
                            if ($data_img) {
                                $img_data = json_decode($data_img, true);
                                if (json_last_error() === JSON_ERROR_NONE && isset($img_data['hires'])) {
                                    $links[] = $img_data['hires'];
                                } else {
                                    echo "Could not parse JSON or 'hires' key not found: ".$data_img."\n";
                                }
                            }
                        }

                        // Convert the links array to JSON
                        $links_json = json_encode($links, JSON_PRETTY_PRINT);

                        if (! empty($links)) {
                            $product->update(['images' => $links_json]);
                        }
                    } else {
                        $this->error("Product not found with id {$product->id}");
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
