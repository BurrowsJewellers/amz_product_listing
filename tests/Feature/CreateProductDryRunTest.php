<?php

namespace Tests\Feature;

use App\Console\Commands\Shopify\CreateProduct;
use App\Models\RetailEdgeProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The --dry-run path must report exactly what productSet WOULD receive for one family,
 * without calling Shopify or writing to the DB. Hard-isolated to in-memory sqlite.
 */
class CreateProductDryRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.dryrun_sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('dryrun_sqlite');
        DB::purge('dryrun_sqlite');
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        Schema::create('retail_edge_products', function ($t) {
            $t->increments('id');
            $t->string('sku')->nullable();
            $t->string('old_key')->nullable();
            $t->string('title')->nullable();
            $t->string('id3')->nullable();
            $t->string('s_cat')->nullable();
            $t->string('metal_colour')->nullable();
            $t->string('s_metal_type')->nullable();
            $t->string('pendant_style')->nullable();
            $t->string('ring_size')->nullable();
            $t->string('bracelet_length')->nullable();
            $t->string('barcode')->nullable();
            $t->string('retail_price1')->nullable();
            $t->string('retail_price2')->nullable();
            $t->integer('quantity')->default(1);
            $t->integer('uploaded_to_shopify')->default(0);
            $t->timestamps();
        });
        Schema::create('shopify_product_variants', function ($t) {
            $t->increments('id');
            $t->string('sku')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('retail_edge_products');
        Schema::dropIfExists('shopify_product_variants');
        parent::tearDown();
    }

    public function test_plan_for_existing_chain_family_includes_parent_and_all_distinct_lengths(): void
    {
        // The real 022-06122 chain family: parent + 3 children, already on Shopify (only
        // 022-06123 listed). The plan must show all four as distinct (Color, Length) variants
        // — including the parent and the two Sterling Silver lengths that used to collapse.
        DB::table('retail_edge_products')->insert([
            ['sku' => '022-06122', 'old_key' => '022-06122', 'title' => 'Rope Chain', 'id3' => 'VT1 - VT2', 's_cat' => 'Chains', 'bracelet_length' => '45', 'metal_colour' => 'Yellow Gold', 'retail_price1' => '169', 'retail_price2' => '169'],
            ['sku' => '022-06123', 'old_key' => '022-06122', 'title' => 'Rope Chain', 'id3' => 'VT1 - VT2', 's_cat' => 'Chains', 'bracelet_length' => '70cm', 'metal_colour' => 'Yellow Gold', 'retail_price1' => '169', 'retail_price2' => '169'],
            ['sku' => '022-06125', 'old_key' => '022-06122', 'title' => 'Rope Chain', 'id3' => 'VT1 - VT2', 's_cat' => 'Chains', 'bracelet_length' => '45', 'metal_colour' => 'Sterling Silver', 'retail_price1' => '99', 'retail_price2' => '99'],
            ['sku' => '022-06126', 'old_key' => '022-06122', 'title' => 'Rope Chain', 'id3' => 'VT1 - VT2', 's_cat' => 'Chains', 'bracelet_length' => '70cm', 'metal_colour' => 'Sterling Silver', 'retail_price1' => '99', 'retail_price2' => '99'],
        ]);
        // Product already exists on Shopify (one child listed) → sync_existing path.
        DB::table('shopify_product_variants')->insert(['sku' => '022-06123', 'product_id' => 555]);

        $product = RetailEdgeProduct::where('sku', '022-06122')->with('children')->first();
        $product->setRelation('brand', null);

        $plan = $this->invokePlan(new CreateProduct, $product);

        $this->assertSame('sync_existing', $plan['path']);
        $this->assertSame('gid://shopify/Product/555', $plan['existing_product_gid']);
        $this->assertEqualsCanonicalizing(
            ['022-06122', '022-06123', '022-06125', '022-06126'],
            array_column($plan['variants'], 'sku')
        );
        $this->assertEmpty($plan['blocked']);
        // Two options (Size + Color), and no DB writes happened.
        $this->assertSame(['Size', 'Color'], array_column($plan['product_options'], 'name'));
        $this->assertSame(0, (int) RetailEdgeProduct::where('sku', '022-06122')->value('uploaded_to_shopify'));
    }

    public function test_plan_for_new_family_uses_create_path(): void
    {
        DB::table('retail_edge_products')->insert([
            ['sku' => 'R-1', 'old_key' => 'R-1', 'title' => 'Ring', 'id3' => 'VT1', 's_cat' => 'Rings', 'ring_size' => '50', 'retail_price1' => '10', 'retail_price2' => '10'],
            ['sku' => 'R-2', 'old_key' => 'R-1', 'title' => 'Ring', 'id3' => 'VT1', 's_cat' => 'Rings', 'ring_size' => '52', 'retail_price1' => '10', 'retail_price2' => '10'],
        ]);

        $product = RetailEdgeProduct::where('sku', 'R-1')->with('children')->first();
        $product->setRelation('brand', null);

        $plan = $this->invokePlan(new CreateProduct, $product);

        $this->assertSame('create', $plan['path']);
        $this->assertNull($plan['existing_product_gid']);
        $this->assertContains('R-1', array_column($plan['variants'], 'sku'));
    }

    private function invokePlan(CreateProduct $cmd, RetailEdgeProduct $product): array
    {
        $m = new \ReflectionMethod(CreateProduct::class, 'dryRunPlan');
        $m->setAccessible(true);

        return $m->invoke($cmd, $product);
    }
}
