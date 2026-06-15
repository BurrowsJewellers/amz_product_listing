<?php

namespace Tests\Feature;

use App\Console\Commands\Shopify\CreateProduct;
use App\Models\RetailEdgeProduct;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * The existing-product reconcile path must sync the COMPLETE family (parent + children)
 * onto a live Shopify product via productSet — so the parent's own variant is added and
 * flags are set from what actually went live. Hard-isolated to in-memory sqlite.
 */
class SyncExistingProductVariantsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.sync_sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('sync_sqlite');
        DB::purge('sync_sqlite');
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        Schema::create('retail_edge_products', function ($t) {
            $t->increments('id');
            $t->string('sku')->nullable();
            $t->string('old_key')->nullable();
            $t->string('title')->nullable();
            $t->text('marketing_description')->nullable();
            $t->string('brand_id')->nullable();
            $t->string('barcode')->nullable();
            $t->string('retail_price1')->nullable();
            $t->string('retail_price2')->nullable();
            $t->integer('quantity')->default(1);
            $t->integer('uploaded_to_shopify')->default(0);
            $t->string('id3')->nullable();
            $t->string('s_cat')->nullable();
            $t->string('s_sub_cat')->nullable();
            $t->string('s_web_menu')->nullable();
            $t->string('s_stone_type')->nullable();
            $t->string('metal_colour')->nullable();
            $t->string('s_metal_type')->nullable();
            $t->string('pendant_style')->nullable();
            $t->string('ring_size')->nullable();
            $t->string('bracelet_length')->nullable();
            $t->string('real_design_number')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('retail_edge_products');
        parent::tearDown();
    }

    public function test_sync_adds_parent_variant_and_marks_family_live(): void
    {
        // Ring family already on Shopify with only the children listed; the parent's own
        // size (52) is missing. productSet must add it and mark the whole family synced.
        DB::table('retail_edge_products')->insert([
            ['sku' => '021-09535', 'old_key' => '021-09535', 'title' => 'Two-Tone Ring', 'id3' => 'VT1', 's_cat' => 'Rings', 'ring_size' => '52', 'retail_price1' => '100', 'retail_price2' => '100', 'uploaded_to_shopify' => 0],
            ['sku' => '021-09536', 'old_key' => '021-09535', 'title' => 'Two-Tone Ring', 'id3' => 'VT1', 's_cat' => 'Rings', 'ring_size' => '54', 'retail_price1' => '100', 'retail_price2' => '100', 'uploaded_to_shopify' => 1],
            ['sku' => '021-09537', 'old_key' => '021-09535', 'title' => 'Two-Tone Ring', 'id3' => 'VT1', 's_cat' => 'Rings', 'ring_size' => '56', 'retail_price1' => '100', 'retail_price2' => '100', 'uploaded_to_shopify' => 1],
        ]);

        $product = RetailEdgeProduct::where('sku', '021-09535')->with('children')->first();
        $product->setRelation('brand', null);

        $productGid = 'gid://shopify/Product/555';

        // Fake client: productSet captures input + identifier and reports success; the
        // refresh query returns the full variant set including the now-added parent.
        $fullEdges = collect(['021-09535', '021-09536', '021-09537'])
            ->map(fn ($sku) => ['node' => ['sku' => $sku]])->all();
        $client = $this->fakeClient($fullEdges);

        $cmd = new CreateProduct;
        $cmd->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        $result = $this->invokeSync($cmd, $product, $productGid, $client);

        // productSet was called targeting the existing product, with the parent in the set.
        $this->assertNotEmpty($client->productSetCalls, 'productSet must be called');
        $vars = $client->productSetCalls[0];
        $this->assertSame($productGid, $vars['identifier']['id'] ?? null, 'must target the existing product via identifier');
        $variantSkus = array_map(fn ($v) => $v['inventoryItem']['sku'], $vars['input']['variants']);
        $this->assertContains('021-09535', $variantSkus, 'parent SKU must be in the synced variant set');

        // Flags: every family row is now live.
        $this->assertContains('021-09535', $result['created']);
        $this->assertEmpty($result['blocked']);
        $this->assertSame(1, (int) RetailEdgeProduct::where('sku', '021-09535')->value('uploaded_to_shopify'));
    }

    private function invokeSync(CreateProduct $cmd, RetailEdgeProduct $product, string $productGid, $client): array
    {
        $m = new \ReflectionMethod(CreateProduct::class, 'syncExistingProductVariants');
        $m->setAccessible(true);

        return $m->invoke($cmd, $product, $productGid, $client);
    }

    private function fakeClient(array $refreshEdges): object
    {
        return new class($refreshEdges)
        {
            public array $productSetCalls = [];

            public function __construct(private array $refreshEdges) {}

            public function query(array $args)
            {
                $q = $args['query'];
                if (str_contains($q, 'productSet(')) {
                    $this->productSetCalls[] = $args['variables'];
                    $body = ['data' => ['productSet' => ['product' => ['id' => 'gid://shopify/Product/555'], 'productSetOperation' => null, 'userErrors' => []]]];
                } elseif (str_contains($q, 'getProduct')) {
                    $body = ['data' => ['product' => ['id' => 'gid://shopify/Product/555', 'variants' => ['edges' => $this->refreshEdges]]]];
                } else {
                    $body = ['data' => []];
                }

                return new class(json_encode($body))
                {
                    public function __construct(private string $json) {}

                    public function getBody()
                    {
                        return new class($this->json)
                        {
                            public function __construct(private string $json) {}

                            public function getContents()
                            {
                                return $this->json;
                            }
                        };
                    }
                };
            }
        };
    }
}
