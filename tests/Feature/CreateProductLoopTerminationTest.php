<?php

namespace Tests\Feature;

use App\Console\Commands\Shopify\CreateProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards against the production incident where shopifyCreateProduct spun forever
 * on a parent whose children partially exist in Shopify: the parent kept matching
 * the "has pending children" query and was re-selected every iteration (117k log
 * lines in 3 hours). The loop must process each parent at most once.
 *
 * Hard-isolated to an in-memory sqlite connection — this test never touches the
 * configured (production) MySQL database and never runs migrations.
 */
class CreateProductLoopTerminationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.loopguard_sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('loopguard_sqlite');
        DB::purge('loopguard_sqlite');

        // Refuse to run unless we are truly on an isolated sqlite connection.
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        Schema::create('retail_edge_products', function ($t) {
            $t->increments('id');
            $t->string('sku')->nullable();
            $t->string('old_key')->nullable();
            $t->integer('quantity')->default(0);
            $t->integer('uploaded_to_shopify')->default(0);
            $t->timestamps();
        });
        Schema::create('shopify_product_variants', function ($t) {
            $t->increments('id');
            $t->string('sku')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('retail_edge_products');
        Schema::dropIfExists('shopify_product_variants');
        parent::tearDown();
    }

    public function test_pending_parent_query_excludes_already_processed_parents(): void
    {
        // Parent with mixed children: one child already in Shopify, one still pending.
        // This is exactly the shape that caused the infinite loop.
        DB::table('retail_edge_products')->insert([
            ['sku' => '011-00742', 'old_key' => '011-00742', 'quantity' => 1, 'uploaded_to_shopify' => 1], // parent
            ['sku' => '011-00737', 'old_key' => '011-00742', 'quantity' => 1, 'uploaded_to_shopify' => 1], // child, in Shopify
            ['sku' => '011-00743', 'old_key' => '011-00742', 'quantity' => 1, 'uploaded_to_shopify' => 0], // child, pending
        ]);
        DB::table('shopify_product_variants')->insert(['sku' => '011-00737']);

        $cmd = new CreateProduct;

        // The parent qualifies (it has a pending child and is not itself in Shopify).
        $first = $cmd->nextPendingProduct([]);
        $this->assertNotNull($first, 'Parent with a pending child should be selected.');
        $this->assertSame('011-00742', $first->sku);

        // Once processed, it must not be selected again — otherwise the loop spins forever.
        $next = $cmd->nextPendingProduct([$first->id]);
        $this->assertNull($next, 'A processed parent must be excluded so the loop terminates.');
    }

    public function test_parent_is_pending_when_its_own_variant_is_missing_even_if_all_children_are_uploaded(): void
    {
        // The dropped-parent bug class: every child is already a Shopify variant, but the
        // parent's own SKU was never listed (parent uploaded=0, not in shopify_product_variants).
        // The pending query must still select it so the parent's own variant gets added.
        DB::table('retail_edge_products')->insert([
            ['sku' => '021-09535', 'old_key' => '021-09535', 'quantity' => 1, 'uploaded_to_shopify' => 0], // parent, own variant missing
            ['sku' => '021-09536', 'old_key' => '021-09535', 'quantity' => 1, 'uploaded_to_shopify' => 1], // child, in Shopify
            ['sku' => '021-09537', 'old_key' => '021-09535', 'quantity' => 1, 'uploaded_to_shopify' => 1], // child, in Shopify
        ]);
        DB::table('shopify_product_variants')->insert([['sku' => '021-09536'], ['sku' => '021-09537']]);

        $cmd = new CreateProduct;

        $product = $cmd->nextPendingProduct([]);
        $this->assertNotNull($product, 'Parent whose own variant is missing must be selected even if all children are uploaded.');
        $this->assertSame('021-09535', $product->sku);
    }

    public function test_parent_flagged_needs_review_does_not_churn_in_pending_query(): void
    {
        // A parent flagged STATUS_NEEDS_REVIEW (3) — e.g. it genuinely cannot be listed — must
        // not be re-selected every run, even though its own SKU is absent from Shopify.
        DB::table('retail_edge_products')->insert([
            ['sku' => 'NR-1', 'old_key' => 'NR-1', 'quantity' => 1, 'uploaded_to_shopify' => 3],
            ['sku' => 'NR-2', 'old_key' => 'NR-1', 'quantity' => 1, 'uploaded_to_shopify' => 1],
        ]);
        DB::table('shopify_product_variants')->insert(['sku' => 'NR-2']);

        $this->assertNull((new CreateProduct)->nextPendingProduct([]), 'A needs-review parent must not churn.');
    }

    public function test_next_pending_product_can_be_restricted_to_a_single_sku(): void
    {
        // Two standalone parents that both qualify (in stock, not in Shopify, no children).
        DB::table('retail_edge_products')->insert([
            ['sku' => 'P-1', 'old_key' => 'P-1', 'quantity' => 1, 'uploaded_to_shopify' => 0],
            ['sku' => 'P-2', 'old_key' => 'P-2', 'quantity' => 1, 'uploaded_to_shopify' => 0],
        ]);

        $cmd = new CreateProduct;

        // Restricting to P-2 must return only P-2, enabling a safe single-product live test.
        $only = $cmd->nextPendingProduct([], 'P-2');
        $this->assertNotNull($only);
        $this->assertSame('P-2', $only->sku);

        // And once excluded, nothing else matches the P-2 restriction.
        $this->assertNull($cmd->nextPendingProduct([$only->id], 'P-2'));
    }

    public function test_mark_flags_flags_parent_and_children_that_did_not_become_variants(): void
    {
        // Parent + 3 children; only C1 ends up as a real Shopify variant (the parent's own
        // variant and C2/C3 did not). Each row is flagged by what actually went live.
        DB::table('retail_edge_products')->insert([
            ['sku' => 'PAR', 'old_key' => 'PAR', 'quantity' => 1, 'uploaded_to_shopify' => 0],
            ['sku' => 'C1', 'old_key' => 'PAR', 'quantity' => 1, 'uploaded_to_shopify' => 0],
            ['sku' => 'C2', 'old_key' => 'PAR', 'quantity' => 1, 'uploaded_to_shopify' => 0],
            ['sku' => 'C3', 'old_key' => 'PAR', 'quantity' => 1, 'uploaded_to_shopify' => 0],
        ]);

        $product = \App\Models\RetailEdgeProduct::where('sku', 'PAR')->with('children')->first();
        $createdData = ['variants' => ['edges' => [['node' => ['sku' => 'C1']]]]];

        $result = $this->markFlags(new CreateProduct, $product, $createdData);

        $this->assertSame(['C1'], $result['created']);
        $this->assertEqualsCanonicalizing(['C2', 'C3', 'PAR'], $result['blocked']);

        $val = fn ($sku) => (int) \App\Models\RetailEdgeProduct::where('sku', $sku)->value('uploaded_to_shopify');
        $this->assertSame(1, $val('C1'), 'A row that became a variant is uploaded.');
        $this->assertSame(CreateProduct::STATUS_NEEDS_REVIEW, $val('C2'), 'A row with no variant is flagged, not falsely synced.');
        $this->assertSame(CreateProduct::STATUS_NEEDS_REVIEW, $val('C3'));
        $this->assertSame(CreateProduct::STATUS_NEEDS_REVIEW, $val('PAR'), 'Parent is flagged when any row is blocked.');
    }

    public function test_mark_flags_marks_all_uploaded_when_every_row_is_live(): void
    {
        DB::table('retail_edge_products')->insert([
            ['sku' => 'PAR', 'old_key' => 'PAR', 'quantity' => 1, 'uploaded_to_shopify' => 0],
            ['sku' => 'C1', 'old_key' => 'PAR', 'quantity' => 1, 'uploaded_to_shopify' => 0],
            ['sku' => 'C2', 'old_key' => 'PAR', 'quantity' => 1, 'uploaded_to_shopify' => 0],
        ]);

        $product = \App\Models\RetailEdgeProduct::where('sku', 'PAR')->with('children')->first();
        // Parent's own variant is live too — the dropped-parent regression is fixed.
        $createdData = ['variants' => ['edges' => [['node' => ['sku' => 'PAR']], ['node' => ['sku' => 'C1']], ['node' => ['sku' => 'C2']]]]];

        $result = $this->markFlags(new CreateProduct, $product, $createdData);

        $this->assertEqualsCanonicalizing(['PAR', 'C1', 'C2'], $result['created']);
        $this->assertSame([], $result['blocked']);
        $this->assertSame(1, (int) \App\Models\RetailEdgeProduct::where('sku', 'PAR')->value('uploaded_to_shopify'));
    }

    private function markFlags(CreateProduct $cmd, \App\Models\RetailEdgeProduct $product, array $createdData): array
    {
        $m = new \ReflectionMethod(CreateProduct::class, 'markFlagsFromProductSet');
        $m->setAccessible(true);

        return $m->invoke($cmd, $product, $createdData);
    }

    public function test_classify_product_fetch_distinguishes_live_gone_and_error(): void
    {
        $cmd = new CreateProduct;

        // Product exists -> add missing variants.
        $this->assertSame('live', $cmd->classifyProductFetch([
            'data' => ['product' => ['id' => 'gid://shopify/Product/1', 'title' => 'X']],
        ]));

        // Query succeeded but product is null -> deleted in Shopify (stale local mirror).
        $this->assertSame('gone', $cmd->classifyProductFetch(['data' => ['product' => null]]));

        // GraphQL errors present (e.g. throttled) -> transient, do NOT treat as deleted.
        $this->assertSame('error', $cmd->classifyProductFetch([
            'errors' => [['message' => 'Throttled']],
            'data' => ['product' => null],
        ]));

        // No body at all (request threw) -> error, never delete the mirror.
        $this->assertSame('error', $cmd->classifyProductFetch(null));

        // Malformed body (no data key) -> error.
        $this->assertSame('error', $cmd->classifyProductFetch(['extensions' => []]));
    }
}
