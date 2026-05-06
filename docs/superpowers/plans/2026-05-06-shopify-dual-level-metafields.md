# Shopify Dual-Level Metafields & Variant Design Number — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mirror uniform ISD metafields onto both product and variant levels, add a `design_number_variant` metafield carrying the full RetailEdge design number on every variant, and reconcile the existing 12k+ products via a one-time backfill command.

**Architecture:** Modify `MetafieldAssignmentService` so its existing return shape (`product_metafields`, `variant_metafields`) carries dual-level entries for uniform values. Append a `design_number_variant` write at the variant level inside `CreateProduct` and `UpdateProduct`. Add a new `BackfillMetafields` console command (mirrors the shape of `DeleteDuplicateProducts`) that re-runs the assignment service against every live Shopify product and `metafieldsSet`s only the missing/changed entries.

**Tech Stack:** Laravel 11, MySQL, PHP 8.2+, Shopify GraphQL Admin API, existing traits `ShopifyCleanupTrait` / `ShopifyErrorFormatterTrait`, `SyncLogger`.

**Spec:** `docs/superpowers/specs/2026-05-06-shopify-dual-level-metafields-design.md`

**Verification approach:** This codebase does not yet have a phpunit testing culture for these flows (only `ExampleTest.php`). Verification at each step uses `php artisan tinker --execute` snippets against real data, and the `BackfillMetafields` command is staged via `--dry-run` and `--limit` exactly as `DeleteDuplicateProducts` was. Formal phpunit coverage for `MetafieldAssignmentService` is a future improvement (noted at end).

---

## File Structure

**Create:**
- `app/Console/Commands/Shopify/BackfillMetafields.php` — new artisan command, ~350 lines, mirrors `DeleteDuplicateProducts.php` shape.

**Modify:**
- `app/Services/MetafieldAssignmentService.php` — change `analyzeForMultiVariant()` and `getAllProductISDs()` so uniform values populate both arrays.
- `app/Console/Commands/Shopify/ShopifyCreateMetafieldDefinitions.php` — add one synthetic `design_number_variant` definition after the ISD loop.
- `app/Console/Commands/Shopify/CreateProduct.php` — append `design_number_variant` to `$metafieldsToSet` for each variant inside `handleMetafieldsAfterCreation()`.
- `app/Console/Commands/Shopify/UpdateProduct.php` — same append in the variant-metafield section (around lines 257–280).
- `app/Services/SyncLogger.php` — add `OP_METAFIELD_BACKFILL` constant alongside the existing `OP_METAFIELD_UPDATE`.

**No changes** to `JobOrchestrator.php` in this work — the backfill is on-demand only per the spec.

---

## Task 1: Add `OP_METAFIELD_BACKFILL` constant to SyncLogger

**Files:**
- Modify: `app/Services/SyncLogger.php` near line 41

- [ ] **Step 1: Add the constant**

Insert after the existing `OP_METAFIELD_UPDATE` definition (line 41):

```php
public const OP_METAFIELD_UPDATE = 'metafield_update';

public const OP_METAFIELD_BACKFILL = 'metafield_backfill';
```

- [ ] **Step 2: Add it to the operation list method**

Find the array of all OP_ constants returned by the helper that lists valid operations (around line 276 — the array starts with `self::OP_PRODUCT_CREATE`). Add the new constant in alphabetical/grouping order with the other metafield operations:

```php
self::OP_METAFIELD_UPDATE,
self::OP_METAFIELD_BACKFILL,
```

- [ ] **Step 3: Verify by booting the app**

Run: `php artisan list --raw | head -3`
Expected: command list prints without fatal errors.

- [ ] **Step 4: Commit**

```bash
git add app/Services/SyncLogger.php
git commit -m "Add OP_METAFIELD_BACKFILL constant for backfill logging"
```

---

## Task 2: Update `MetafieldAssignmentService` for dual-level writes

**Files:**
- Modify: `app/Services/MetafieldAssignmentService.php`

**Behavior change:**
- `analyzeForMultiVariant()`: uniform values → also written into `variant_metafields[$childSku]` for every child (today: only `commonMetafields` → `product_metafields`).
- `getAllProductISDs()` (used when `childrenCount <= 1`): when `childrenCount === 1`, also emit a variant entry keyed by the single child's SKU. When `childrenCount === 0`, behavior unchanged (product only).

- [ ] **Step 1: Update `getAllProductISDs()` to emit dual-level for single-child case**

Replace the existing method body (lines 34–54) with:

```php
private function getAllProductISDs(RetailEdgeProduct $product): array
{
    $productMetafields = [];
    $variantMetafields = [];

    $singleChild = $product->children->count() === 1 ? $product->children->first() : null;
    $targetSku = $singleChild ? $singleChild->sku : $product->sku;

    $isds = RetailEdgeProductIsd::where('sku', $targetSku)->get();

    foreach ($isds as $isd) {
        if (empty($isd->isd_value)) {
            continue;
        }

        $productMetafields[] = [
            'isd_name' => $isd->isd_name,
            'value' => $isd->isd_value,
            'key_suffix' => '_product',
        ];

        if ($singleChild) {
            $variantMetafields[$singleChild->sku][] = [
                'isd_name' => $isd->isd_name,
                'value' => $isd->isd_value,
                'key_suffix' => '_variant',
            ];
        }
    }

    return [
        'product_metafields' => $productMetafields,
        'variant_metafields' => $variantMetafields,
    ];
}
```

- [ ] **Step 2: Update the early-return in `determineMetafieldAssignment()` to read from the new shape**

Replace lines 18–25 with:

```php
if ($childrenCount <= 1) {
    $isds = $this->getAllProductISDs($product);
    return [
        'type' => 'PRODUCT_ONLY',
        'product_metafields' => $isds['product_metafields'],
        'variant_metafields' => $isds['variant_metafields'],
    ];
}
```

(Note: the `'type' => 'PRODUCT_ONLY'` label is kept for log-output stability even though variant entries may now also exist; renaming the label is out of scope.)

- [ ] **Step 3: Update `analyzeForMultiVariant()` to mirror uniform values to variants**

Replace the body of the `if (count($uniqueValues) === 1)` branch (lines 87–94) with:

```php
if (count($uniqueValues) === 1) {
    $sharedValue = reset($uniqueValues);

    $commonMetafields[] = [
        'isd_name' => $isdName,
        'value' => $sharedValue,
        'key_suffix' => '_product',
    ];

    foreach (array_keys($values) as $childSku) {
        $variantMetafields[$childSku][] = [
            'isd_name' => $isdName,
            'value' => $sharedValue,
            'key_suffix' => '_variant',
        ];
    }
} else {
```

(The `else` branch — differing values → variant only — remains as-is.)

- [ ] **Step 4: Verify against real data via tinker**

Pick a known multi-variant parent SKU (use one returned in Task 5's verification, or any SKU where `retail_edge_products.old_key = sku` AND children exist). Replace `<PARENT_SKU>` below:

Run:
```bash
php artisan tinker --execute="
use App\\Models\\RetailEdgeProduct;
use App\\Services\\MetafieldAssignmentService;

\$parent = RetailEdgeProduct::where('sku','<PARENT_SKU>')->first();
\$assignment = (new MetafieldAssignmentService)->determineMetafieldAssignment(\$parent);

echo 'type: '.\$assignment['type'].PHP_EOL;
echo 'product_metafields count: '.count(\$assignment['product_metafields']).PHP_EOL;
foreach (\$assignment['variant_metafields'] as \$sku => \$mfs) {
    echo 'variant '.\$sku.': '.count(\$mfs).' metafields'.PHP_EOL;
}
"
```

Expected: when there are uniform-across-children ISDs, each variant SKU prints a non-zero count, AND `product_metafields count` is non-zero. Before this task it would have been one or the other.

- [ ] **Step 5: Verify the single-child case**

Pick a parent SKU with exactly one child (or a standalone SKU where `old_key = sku` and no children exist with `old_key = sku` — see verification helper below):

```bash
php artisan tinker --execute="
use App\\Models\\RetailEdgeProduct;

\$rows = RetailEdgeProduct::whereColumn('old_key','sku')
    ->whereHas('children', null, '=', 1)
    ->limit(3)->pluck('sku');
foreach (\$rows as \$s) echo \$s.PHP_EOL;
"
```

Then run the Step 4 snippet against one of those SKUs. Expected: `variant_metafields` contains exactly one SKU key with a non-empty array.

- [ ] **Step 6: Commit**

```bash
git add app/Services/MetafieldAssignmentService.php
git commit -m "Mirror uniform ISD metafields to both product and variant levels"
```

---

## Task 3: Register `design_number_variant` definition

**Files:**
- Modify: `app/Console/Commands/Shopify/ShopifyCreateMetafieldDefinitions.php` after the ISD loop (around line 185, before `$this->info('Shopify metafield definition creation process finished.')`)

- [ ] **Step 1: Append synthetic definition after the ISD loop**

Insert directly before line 187 (`$this->info('Shopify metafield definition creation process finished.');`):

```php
$this->createSyntheticDefinition(
    name: 'Design Number',
    namespace: $defaultNamespace,
    key: 'design_number_variant',
    type: 'single_line_text_field',
    ownerType: 'PRODUCTVARIANT',
);
```

- [ ] **Step 2: Add the helper method**

Add a private method to the same class (above `handle()` is fine; alongside other private helpers if the class has them — otherwise at the bottom of the class):

```php
private function createSyntheticDefinition(
    string $name,
    string $namespace,
    string $key,
    string $type,
    string $ownerType,
): void {
    $existing = ShopifyMetafield::where('namespace', $namespace)
        ->where('key', $key)
        ->where('owner_type', $ownerType)
        ->first();

    if ($existing) {
        $this->line("Synthetic metafield definition '{$name}' ({$ownerType}) already exists with GID: {$existing->gid}. Skipping.");
        return;
    }

    $mutation = <<<'GRAPHQL'
    mutation CreateMetafieldDefinition($definition: MetafieldDefinitionInput!) {
      metafieldDefinitionCreate(definition: $definition) {
        createdDefinition { id name namespace key type { name } ownerType }
        userErrors { field message }
      }
    }
    GRAPHQL;

    $variables = [
        'definition' => [
            'name' => $name,
            'namespace' => $namespace,
            'key' => $key,
            'type' => $type,
            'ownerType' => $ownerType,
            'description' => "Metafield for {$name} ({$ownerType})",
        ],
    ];

    try {
        $session = $this->shopifyConnectionService->getSession();
        $client = new Graphql($session->getShop(), $session->getAccessToken());
        $response = $client->query(['query' => $mutation, 'variables' => $variables]);

        $resultBody = json_decode($response->getBody()->getContents(), true);
        $result = $resultBody['data']['metafieldDefinitionCreate'] ?? null;
        $errors = $resultBody['errors'] ?? ($result['userErrors'] ?? []);

        if (! empty($errors)) {
            foreach ($errors as $error) {
                $this->error("Shopify API Error for '{$name}' ({$ownerType}): ".($error['message'] ?? 'Unknown error'));
                Log::error("Shopify API Error for synthetic metafield '{$name}' ({$ownerType}): ".json_encode($error));
            }
            return;
        }

        if (! empty($result['createdDefinition'])) {
            $created = $result['createdDefinition'];
            $row = ShopifyMetafield::create([
                'name' => $created['name'],
                'namespace' => $created['namespace'],
                'key' => $created['key'],
                'type' => $created['type']['name'],
                'owner_type' => $created['ownerType'],
                'gid' => $created['id'],
            ]);
            $this->info("Successfully created synthetic metafield '{$row->name}' ({$ownerType}) with GID: {$row->gid}");
        }
    } catch (\Exception $e) {
        $this->error("Exception while creating synthetic metafield '{$name}' ({$ownerType}): ".$e->getMessage());
        Log::error("Exception for synthetic metafield '{$name}' ({$ownerType}): ".$e->getMessage(), ['exception' => $e]);
    }
}
```

- [ ] **Step 3: Verify by re-running the command (idempotent)**

Run: `php artisan shopify:create-metafield-definitions`
Expected: existing ISD definitions print "Skipping creation" lines (already-created), AND a new line for `Design Number (PRODUCTVARIANT)` either creates it (first run) or prints "Skipping" (subsequent runs).

- [ ] **Step 4: Confirm the row landed in `shopify_metafields`**

```bash
php artisan tinker --execute="
\$m = \DB::table('shopify_metafields')
    ->where('key','design_number_variant')
    ->where('owner_type','PRODUCTVARIANT')
    ->first();
echo \$m ? ('OK gid='.\$m->gid) : 'MISSING';
echo PHP_EOL;
"
```
Expected: `OK gid=gid://shopify/MetafieldDefinition/...`

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/Shopify/ShopifyCreateMetafieldDefinitions.php
git commit -m "Register design_number_variant metafield definition"
```

---

## Task 4: Append `design_number_variant` write in `CreateProduct`

**Files:**
- Modify: `app/Console/Commands/Shopify/CreateProduct.php` inside `handleMetafieldsAfterCreation()`, after the existing variant-metafield foreach (around line 748, before `processMetafieldsInBatches`)

- [ ] **Step 1: Insert the design-number write loop**

Directly after the close of the `foreach ($assignment['variant_metafields'] as $sku => $metafields)` block (line 748), and before the `// Batch process metafields in chunks of 250` comment (line 750), insert:

```php
// design_number_variant — full RetailEdge real_design_number per variant
$designDef = ShopifyMetafield::where('namespace', 'custom')
    ->where('key', 'design_number_variant')
    ->where('owner_type', 'PRODUCTVARIANT')
    ->first();

if ($designDef) {
    $variantSkus = $product->children->isNotEmpty()
        ? $product->children->pluck('sku', 'sku')
        : collect([$product->sku => $product->sku]);

    foreach ($variantSkus as $variantSku) {
        $variantId = $this->findVariantIdBySku($createdProductData, $variantSku);
        $variantRep = RetailEdgeProduct::where('sku', $variantSku)->first();

        if (! $variantId || ! $variantRep || empty($variantRep->real_design_number)) {
            continue;
        }

        $metafieldsToSet[] = [
            'ownerId' => $variantId,
            'namespace' => $designDef->namespace,
            'key' => $designDef->key,
            'type' => $designDef->type,
            'value' => (string) $variantRep->real_design_number,
        ];
        $this->line("Added design_number_variant: {$variantSku} = {$variantRep->real_design_number}");
    }
} else {
    $this->warn('design_number_variant definition not found in shopify_metafields. Run shopify:create-metafield-definitions.');
}
```

- [ ] **Step 2: Confirm `RetailEdgeProduct` is already imported**

The file already uses `App\Models\RetailEdgeProduct` (it's referenced as the type of `$product` parameter — verify the `use` line at the top exists). If not, add: `use App\Models\RetailEdgeProduct;`

- [ ] **Step 3: Static smoke test by syntax-checking the file**

Run: `php -l app/Console/Commands/Shopify/CreateProduct.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Lint check**

Run: `./vendor/bin/pint app/Console/Commands/Shopify/CreateProduct.php`
Expected: pint reports either no changes or applies trivial whitespace fixes only.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/Shopify/CreateProduct.php
git commit -m "Write design_number_variant per variant during product creation"
```

---

## Task 5: Append `design_number_variant` write in `UpdateProduct`

**Files:**
- Modify: `app/Console/Commands/Shopify/UpdateProduct.php` after the variant-metafield section (around line 280, just before the metafield batch is dispatched).

- [ ] **Step 1: Locate the insertion point**

Open `UpdateProduct.php`. Find the close of the `foreach ($assignment['variant_metafields'][$variant->sku] as $metafield)` block (around line 280–290). Then continue past the surrounding `foreach ($shopifyProduct->variants as $variant)` outer loop close. Insert AFTER the outer variants loop closes, BEFORE the call that batches and sends metafields (look for `processMetafieldsInBatches` or its equivalent — same shape as in `CreateProduct.php`).

- [ ] **Step 2: Insert the same write block, adapted to UpdateProduct's variable names**

Drop in the equivalent of Task 4's block. The differences from CreateProduct:
- Variant GID lookup: `UpdateProduct` already has `$shopifyProduct->variants` with each variant's `variant_id` (use that field — no `findVariantIdBySku` needed).
- `$createdProductData` → `$shopifyProduct` (existing local Eloquent model).

```php
// design_number_variant — full RetailEdge real_design_number per variant
$designDef = ShopifyMetafield::where('namespace', 'custom')
    ->where('key', 'design_number_variant')
    ->where('owner_type', 'PRODUCTVARIANT')
    ->first();

if ($designDef) {
    foreach ($shopifyProduct->variants as $variant) {
        if (empty($variant->variant_id) || empty($variant->sku)) {
            continue;
        }

        $variantRep = RetailEdgeProduct::where('sku', $variant->sku)->first();
        if (! $variantRep || empty($variantRep->real_design_number)) {
            continue;
        }

        $metafieldsToSet[] = [
            'ownerId' => "gid://shopify/ProductVariant/{$variant->variant_id}",
            'namespace' => $designDef->namespace,
            'key' => $designDef->key,
            'type' => $designDef->type,
            'value' => (string) $variantRep->real_design_number,
        ];
        $this->line("Added design_number_variant: {$variant->sku} = {$variantRep->real_design_number}");
    }
} else {
    $this->warn('design_number_variant definition not found in shopify_metafields. Run shopify:create-metafield-definitions.');
}
```

(Confirm the GID format — read 5 lines around any existing `gid://shopify/ProductVariant/` string in `UpdateProduct.php`. If the class stores the full GID in a different column like `variant_gid`, use that column directly instead of constructing the GID.)

- [ ] **Step 3: Confirm imports**

If `App\Models\RetailEdgeProduct` and `App\Models\ShopifyMetafield` are not yet imported at the top of `UpdateProduct.php`, add the `use` statements.

- [ ] **Step 4: Syntax + lint check**

```bash
php -l app/Console/Commands/Shopify/UpdateProduct.php
./vendor/bin/pint app/Console/Commands/Shopify/UpdateProduct.php
```

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/Shopify/UpdateProduct.php
git commit -m "Write design_number_variant per variant during product update"
```

---

## Task 6: Scaffold `BackfillMetafields` command (signature + dry-run shell)

**Files:**
- Create: `app/Console/Commands/Shopify/BackfillMetafields.php`

- [ ] **Step 1: Create the file with full skeleton**

Paste the full contents — read `DeleteDuplicateProducts.php` for the helper plumbing pattern (`SyncJob::startJob`, `ShopifyConnectionService`, `Graphql` client, `ShopifyErrorFormatterTrait`):

```php
<?php

namespace App\Console\Commands\Shopify;

use App\Models\RetailEdgeProduct;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Services\MetafieldAssignmentService;
use App\Services\ShopifyConnectionService;
use App\Services\SyncLogger;
use App\Traits\ShopifyErrorFormatterTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;

class BackfillMetafields extends Command
{
    use ShopifyErrorFormatterTrait;

    protected $signature = 'shopify:backfill-metafields
        {--dry-run : Plan changes without writing to Shopify}
        {--force : Run without confirmation}
        {--sku= : Limit to a single parent SKU}
        {--limit= : Cap the number of products processed}';

    protected $description = 'Backfill Shopify metafields to dual-level placement and set design_number_variant on every variant.';

    public function __construct(private ShopifyConnectionService $shopifyConnectionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sku = $this->option('sku');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if (! $dryRun && ! $this->option('force')) {
            if (! $this->confirm('This will write metafields to Shopify. Continue?')) {
                return self::SUCCESS;
            }
        }

        $this->info($dryRun ? '[DRY RUN] No writes will be sent to Shopify.' : 'Live mode: writes will be sent to Shopify.');

        $products = $this->collectProducts($sku, $limit);
        $this->info('Products in scope: '.$products->count());

        $stats = ['scanned' => 0, 'product_writes' => 0, 'variant_writes' => 0, 'errors' => 0];

        foreach ($products as $row) {
            $stats['scanned']++;
            try {
                $this->processProduct($row, $dryRun, $stats);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("Failed to process product GID={$row->gid}: ".$e->getMessage());
                Log::error('shopify:backfill-metafields failure', ['gid' => $row->gid, 'exception' => $e]);
            }
        }

        $this->table(['metric','value'], collect($stats)->map(fn($v,$k) => [$k,$v])->values()->all());

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function collectProducts(?string $sku, ?int $limit)
    {
        $query = DB::table('shopify_products as sp')
            ->join('shopify_product_variants as spv', 'spv.shopify_product_id', '=', 'sp.id')
            ->whereNull('sp.deleted_at')
            ->select('sp.id as shopify_products_id', 'sp.gid as gid', 'spv.sku as variant_sku')
            ->groupBy('sp.id');

        if ($sku) {
            $query->where('spv.sku', $sku);
        }

        $rows = $query->get();
        if ($limit !== null) {
            $rows = $rows->take($limit);
        }

        return $rows;
    }

    private function processProduct(object $row, bool $dryRun, array &$stats): void
    {
        // Implemented in Task 7
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l app/Console/Commands/Shopify/BackfillMetafields.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verify command registration**

Run: `php artisan list | grep backfill-metafields`
Expected: line `shopify:backfill-metafields  Backfill Shopify metafields to dual-level placement...`

- [ ] **Step 4: Verify dry-run shell**

Run: `php artisan shopify:backfill-metafields --dry-run --limit=5`
Expected: prints "[DRY RUN] No writes will be sent to Shopify.", a "Products in scope: 5" line, and an empty stats table (everything 0). No exceptions.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/Shopify/BackfillMetafields.php
git commit -m "Scaffold shopify:backfill-metafields command (dry-run shell)"
```

---

## Task 7: Implement `processProduct()` — diff and write

**Files:**
- Modify: `app/Console/Commands/Shopify/BackfillMetafields.php`

- [ ] **Step 1: Add a helper to load the parent RetailEdgeProduct from a variant SKU**

Inside `BackfillMetafields`:

```php
private function resolveParent(string $variantSku): ?RetailEdgeProduct
{
    $variantRep = RetailEdgeProduct::where('sku', $variantSku)->first();
    if (! $variantRep) {
        return null;
    }

    if (empty($variantRep->old_key) || $variantRep->old_key === $variantRep->sku) {
        return $variantRep; // standalone or self-parent
    }

    return RetailEdgeProduct::where('sku', $variantRep->old_key)->first() ?? $variantRep;
}
```

- [ ] **Step 2: Implement `processProduct()` body**

Replace the empty `processProduct()` with:

```php
private function processProduct(object $row, bool $dryRun, array &$stats): void
{
    $shopifyProduct = ShopifyProduct::with('variants')->find($row->shopify_products_id);
    if (! $shopifyProduct) {
        return;
    }

    $variantSkus = $shopifyProduct->variants->pluck('sku')->filter()->values();
    if ($variantSkus->isEmpty()) {
        return;
    }

    $parent = $this->resolveParent($variantSkus->first());
    if (! $parent) {
        return;
    }

    $service = new MetafieldAssignmentService();
    $assignment = $service->determineMetafieldAssignment($parent);

    $batch = $this->buildMetafieldBatch($shopifyProduct, $assignment);
    if (empty($batch)) {
        return;
    }

    if ($dryRun) {
        $this->line(" [DRY] {$shopifyProduct->gid}: would set ".count($batch).' metafields');
        $stats['product_writes'] += count(array_filter($batch, fn($m) => str_contains($m['ownerId'], '/Product/')));
        $stats['variant_writes'] += count(array_filter($batch, fn($m) => str_contains($m['ownerId'], '/ProductVariant/')));
        return;
    }

    $this->writeBatch($batch);
    $stats['product_writes'] += count(array_filter($batch, fn($m) => str_contains($m['ownerId'], '/Product/')));
    $stats['variant_writes'] += count(array_filter($batch, fn($m) => str_contains($m['ownerId'], '/ProductVariant/')));
    usleep(100_000);
}
```

- [ ] **Step 3: Implement `buildMetafieldBatch()`**

```php
private function buildMetafieldBatch(ShopifyProduct $shopifyProduct, array $assignment): array
{
    $batch = [];

    foreach ($assignment['product_metafields'] as $mf) {
        $def = ShopifyMetafield::where('name', $mf['isd_name'])
            ->where('owner_type', 'PRODUCT')
            ->first();
        if (! $def || empty($mf['value'])) {
            continue;
        }
        $batch[] = [
            'ownerId' => $shopifyProduct->gid,
            'namespace' => $def->namespace,
            'key' => $def->key,
            'type' => $def->type,
            'value' => (string) $mf['value'],
        ];
    }

    foreach ($assignment['variant_metafields'] as $variantSku => $metafields) {
        $variantRow = $shopifyProduct->variants->firstWhere('sku', $variantSku);
        if (! $variantRow || empty($variantRow->variant_id)) {
            continue;
        }
        $ownerId = "gid://shopify/ProductVariant/{$variantRow->variant_id}";

        foreach ($metafields as $mf) {
            $def = ShopifyMetafield::where('name', $mf['isd_name'])
                ->where('owner_type', 'PRODUCTVARIANT')
                ->first();
            if (! $def || empty($mf['value'])) {
                continue;
            }
            $batch[] = [
                'ownerId' => $ownerId,
                'namespace' => $def->namespace,
                'key' => $def->key,
                'type' => $def->type,
                'value' => (string) $mf['value'],
            ];
        }
    }

    $designDef = ShopifyMetafield::where('namespace', 'custom')
        ->where('key', 'design_number_variant')
        ->where('owner_type', 'PRODUCTVARIANT')
        ->first();

    if ($designDef) {
        foreach ($shopifyProduct->variants as $variantRow) {
            if (empty($variantRow->variant_id) || empty($variantRow->sku)) {
                continue;
            }
            $rep = RetailEdgeProduct::where('sku', $variantRow->sku)->first();
            if (! $rep || empty($rep->real_design_number)) {
                continue;
            }
            $batch[] = [
                'ownerId' => "gid://shopify/ProductVariant/{$variantRow->variant_id}",
                'namespace' => $designDef->namespace,
                'key' => $designDef->key,
                'type' => $designDef->type,
                'value' => (string) $rep->real_design_number,
            ];
        }
    }

    return $batch;
}
```

- [ ] **Step 4: Implement `writeBatch()` against Shopify**

```php
private function writeBatch(array $batch): void
{
    $session = $this->shopifyConnectionService->getSession();
    $client = new Graphql($session->getShop(), $session->getAccessToken());

    $mutation = <<<'GRAPHQL'
    mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) {
        metafields { id namespace key value ownerType }
        userErrors { field message }
      }
    }
    GRAPHQL;

    foreach (array_chunk($batch, 25) as $chunk) {
        $response = $client->query(['query' => $mutation, 'variables' => ['metafields' => $chunk]]);
        $body = json_decode($response->getBody()->getContents(), true);

        $userErrors = $body['data']['metafieldsSet']['userErrors'] ?? ($body['errors'] ?? []);
        if (! empty($userErrors)) {
            $msg = $this->formatGraphQLErrorMessage(['userErrors' => $userErrors, 'errors' => $body['errors'] ?? []]);
            Log::warning('shopify:backfill-metafields userErrors', ['msg' => $msg, 'count' => count($chunk)]);
            $this->warn('userErrors: '.$msg);
        }
    }
}
```

- [ ] **Step 5: Syntax + lint**

```bash
php -l app/Console/Commands/Shopify/BackfillMetafields.php
./vendor/bin/pint app/Console/Commands/Shopify/BackfillMetafields.php
```

- [ ] **Step 6: Dry-run on a single SKU**

Pick a parent SKU known to live on Shopify (use any output from Task 2's verification). Replace `<PARENT_SKU>`:

```bash
php artisan shopify:backfill-metafields --dry-run --sku=<PARENT_SKU>
```
Expected: prints `[DRY] gid://shopify/Product/...: would set N metafields`, stats table shows non-zero `product_writes` and/or `variant_writes`.

- [ ] **Step 7: Dry-run with limit**

```bash
php artisan shopify:backfill-metafields --dry-run --limit=10
```
Expected: 10 products scanned, write counts plausible, no exceptions.

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/Shopify/BackfillMetafields.php
git commit -m "Implement BackfillMetafields write path with metafieldsSet batching"
```

---

## Task 8: Push to remote

- [ ] **Step 1: Push the feature branch**

```bash
git push origin feature/shopify-code-improvements
```

- [ ] **Step 2: Confirm CI / pint locally on the modified files**

```bash
./vendor/bin/pint --test app/Services/MetafieldAssignmentService.php app/Console/Commands/Shopify/BackfillMetafields.php app/Console/Commands/Shopify/CreateProduct.php app/Console/Commands/Shopify/UpdateProduct.php app/Console/Commands/Shopify/ShopifyCreateMetafieldDefinitions.php app/Services/SyncLogger.php
```
Expected: `PASS` or report of formatting that pint-without-`--test` would have fixed (none, ideally).

---

## Task 9: Production rollout (manual)

This is intentionally a manual checklist — do NOT run these from agent sessions. The user runs them on the production server.

- [ ] **Step 1: Register definitions**

```bash
php artisan shopify:create-metafield-definitions
```
Expected: idempotent. Creates `design_number_variant` if absent, plus any of the 5 audit-flagged definitions whose underlying ISD rows now exist. Existing definitions skip.

- [ ] **Step 2: Backfill dry-run (full)**

```bash
php artisan shopify:backfill-metafields --dry-run
```
Expected: scans ~12k products, prints write counts. No errors.

- [ ] **Step 3: Backfill live, limited**

```bash
php artisan shopify:backfill-metafields --force --limit=100
```
Expected: 100 products processed, writes confirmed in Shopify admin spot-check (open one variant's metafields panel and confirm `design_number_variant` is set + uniform ISDs appear at both levels).

- [ ] **Step 4: Backfill live, full**

```bash
php artisan shopify:backfill-metafields --force
```
Expected: completes for the remaining ~12.1k products. Errors should be 0 or near-zero.

- [ ] **Step 5: Verify cross-placement audit findings resolved**

Re-run the corrected query (NOT the buggy one in `SHOPIFY_METAFIELDS_AUDIT.md` §8) — joining via `shopify_product_variants`:

```bash
php artisan tinker --execute="
\$cnt = \DB::table('shopify_product_metafields as pm')
    ->join('shopify_product_variants as spv','spv.sku','=','pm.product_sku')
    ->join('shopify_products as sp','sp.id','=','spv.shopify_product_id')
    ->join('shopify_product_variant_metafields as vm', function(\$j){
        \$j->on('vm.sku','=','spv.sku')->whereColumn('vm.isd_name','pm.isd_name');
    })
    ->whereNull('sp.deleted_at')
    ->whereColumn('pm.value','!=','vm.value')
    ->count();
echo 'cross-placed mismatches: '.\$cnt.PHP_EOL;
"
```
Expected: 0 (or near 0). Pre-backfill this number is ~6,177.

---

## Self-review notes

- **Spec coverage:** §1 Task 2 covers spec section 1 (dual-level). §2 Task 4–5 cover spec section 2 (variant design_number write path). §3 Task 3 covers spec section 2 (definition). §4 Task 6–7 cover spec section 3 (backfill). Spec section 4 (chain integration) is intentionally not implemented per spec §6. Spec section "Production rollout" maps to Task 9.
- **Type consistency:** `ShopifyMetafield` lookups use `name` for ISD-derived definitions (existing pattern) and `namespace + key + owner_type` for the synthetic `design_number_variant` (necessary because there's no ISD with `name = 'Design Number'` to look up by name). Consistent across CreateProduct, UpdateProduct, BackfillMetafields. `ownerId` GID format `gid://shopify/ProductVariant/{variant_id}` is consistent across all three.
- **Future improvement (out of scope for this plan):** add phpunit unit tests for `MetafieldAssignmentService` once a testing infrastructure (factories, sqlite-in-memory env) is established. Currently relies on tinker-based verification per the existing team pattern.
