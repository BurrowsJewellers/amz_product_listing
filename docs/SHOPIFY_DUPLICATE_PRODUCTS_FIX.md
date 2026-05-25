# Shopify Duplicate Products/Variants - Technical Analysis & Fix Documentation

**Document Version:** 1.1
**Date Created:** December 18, 2025
**Last Updated:** December 22, 2025
**Status:** Analysis Complete, Implementation Pending
**Priority:** High - Data Integrity Issue

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Issue Description](#2-issue-description)
3. [Technical Background](#3-technical-background)
4. [Root Cause Analysis](#4-root-cause-analysis)
5. [Solution Design](#5-solution-design)
6. [Implementation Details](#6-implementation-details)
7. [Testing Plan](#7-testing-plan)
8. [Rollback Plan](#8-rollback-plan)
9. [Appendix](#9-appendix)

---

## 1. Executive Summary

### 1.1 Problem Statement

Thousands of duplicate Shopify products have been incorrectly created in the system. Child SKUs that should exist as **variants on their parent product** were instead created as **separate standalone products**. This causes:

- Inventory sync failures
- Incorrect product counts on Shopify storefront
- API quota consumption
- Customer confusion with duplicate listings
- Data integrity issues between RetailEdge and Shopify

### 1.2 Impact Assessment

| Metric | Value |
|--------|-------|
| SKUs with duplicates | 433+ |
| Example: SKU `022-04392` | 126 duplicate products |
| Example: SKU `017-02357` | 124 duplicate products |
| Example: SKU `021-06563` | 124 duplicate products |
| Affected product categories | All categories with parent/child relationships |
| Systems affected | Shopify storefront, inventory sync, order management |

### 1.3 Resolution Summary

1. **Immediate Fix:** Update `DeleteDuplicateVariants` command to use parent/child relationship logic
2. **Prevention Fix:** Update `CreateProduct` command to filter out child products
3. **Model Fix:** Update `RetailEdgeProduct.children()` relationship to exclude self-reference
4. **Data Cleanup:** Run delete command to remove incorrect products, then resync

---

## 2. Issue Description

### 2.1 Symptoms Observed

1. **Duplicate SKU Detection:**
   ```bash
   php artisan shopify:delete-duplicate-variants --dry-run
   ```
   Output showed 433+ SKUs with duplicates, some with 100+ duplicate entries.

2. **Command Failure:**
   The delete command was skipping duplicates with message:
   ```
   Skipping - cannot delete the last variant of a product
   ```

3. **Database Analysis:**
   Top 10 duplicate SKUs from `shopify_product_variants`:
   | SKU | Duplicate Count |
   |-----|-----------------|
   | 022-04392 | 126 |
   | 017-02357 | 124 |
   | 021-06563 | 124 |
   | 021-08237 | 122 |
   | 021-08070 | 115 |
   | 011-00737 | 113 |
   | 021-08962 | 84 |
   | 025-02647 | 84 |
   | 021-08725 | 83 |
   | 023-06600 | 80 |

### 2.2 Example Case Study: SKU `022-04392`

#### RetailEdge Database State
```sql
SELECT sku, old_key, title FROM retail_edge_products WHERE sku = '022-04392';
```
| sku | old_key | title |
|-----|---------|-------|
| 022-04392 | 022-05646 | Kirstin Ash Sterling Silver Outline Initial 'F' |

**Interpretation:** SKU `022-04392` is a **child** of parent `022-05646` (because `old_key != sku`).

#### Parent Product Analysis
```sql
SELECT sku, old_key, title FROM retail_edge_products WHERE sku = '022-05646';
```
| sku | old_key | title |
|-----|---------|-------|
| 022-05646 | 022-05646 | Kirstin Ash 18ct Gold Vermeil Outline Alphabet Pendant |

**Parent has 61 children** (products with `old_key = '022-05646'`), including the parent itself.

#### Expected Shopify State
- **ONE** Shopify product for parent `022-05646`
- Product should contain 60+ variants including SKU `022-04392`

#### Actual Shopify State
```sql
SELECT COUNT(*) FROM shopify_product_variants WHERE sku = '022-04392';
-- Result: 126

SELECT COUNT(DISTINCT product_id) FROM shopify_product_variants WHERE sku = '022-04392';
-- Result: 126 different product_ids

SELECT * FROM shopify_product_variants WHERE sku = '022-05646';
-- Result: 0 rows - Parent NOT FOUND!
```

**Critical Finding:**
- **126 separate Shopify products** were created for child SKU `022-04392`
- Each product has **only 1 variant** (the child SKU)
- The **parent product `022-05646` was never created** in Shopify

---

## 3. Technical Background

### 3.1 RetailEdge Data Model

#### Parent/Child Relationship via `old_key` Field

The `retail_edge_products` table uses the `old_key` column to establish product hierarchy:

| Product Type | `sku` | `old_key` | Relationship |
|--------------|-------|-----------|--------------|
| Parent/Standalone | `022-05646` | `022-05646` | Self-referencing (old_key = sku) |
| Child/Variant | `022-04392` | `022-05646` | Points to parent (old_key = parent's sku) |
| Child/Variant | `022-04393` | `022-05646` | Points to parent (old_key = parent's sku) |

**Key Rule:**
- `old_key = sku` → Product is a **parent** or **standalone** (should create Shopify product)
- `old_key != sku` → Product is a **child** (should be variant on parent's Shopify product)

#### Database Statistics (as of December 22, 2025)
```sql
-- Parents/Standalone products (old_key = sku)
SELECT COUNT(*) FROM retail_edge_products WHERE old_key = sku;
-- Result: 7,027

-- Child products (old_key != sku AND old_key != '')
SELECT COUNT(*) FROM retail_edge_products WHERE old_key != sku AND old_key != '';
-- Result: 4,558

-- Products with EMPTY old_key
SELECT COUNT(*) FROM retail_edge_products WHERE old_key = '';
-- Result: 3,691

-- Products with NULL old_key
SELECT COUNT(*) FROM retail_edge_products WHERE old_key IS NULL;
-- Result: 0
```

**Note:** Products with empty `old_key` are treated as standalone products (not children).

### 3.2 Shopify Product Structure

#### Expected Hierarchy
```
Shopify Product (created from parent SKU)
├── Product ID: 10489653330225
├── Title: "Kirstin Ash 18ct Gold Vermeil Outline Alphabet Pendant"
├── Handle: "kirstin-ash-alphabet-pendant"
└── Variants:
    ├── Variant 1: SKU = 022-05646 (parent SKU - default variant)
    ├── Variant 2: SKU = 022-04392 (child - Initial 'F')
    ├── Variant 3: SKU = 022-04393 (child - Initial 'G')
    └── ... (60+ variants total)
```

**Important:** When creating a Shopify product via `productCreate` mutation, Shopify **automatically creates a default variant**. The code updates this default variant with the first child's SKU.

#### Actual (Incorrect) State
```
Shopify Product #1 (WRONG - standalone product for child)
├── Product ID: 10473289548081
└── Variants:
    └── Variant: SKU = 022-04392 (should be variant on parent!)

Shopify Product #2 (WRONG)
├── Product ID: 10475850924337
└── Variants:
    └── Variant: SKU = 022-04392

... (124 more incorrect products)

Parent Product (SHOULD EXIST but doesn't!)
├── Product ID: ???
└── Variants: (should contain all 60+ children)
```

### 3.3 Laravel Model Relationships

**File:** `app/Models/RetailEdgeProduct.php`

```php
/**
 * Get child products (variants) of this parent
 * Children have old_key = this product's sku
 */
public function children(): HasMany
{
    return $this->hasMany(RetailEdgeProduct::class, 'old_key', 'sku');
}

/**
 * Get parent product
 * Parent's sku = this product's old_key
 */
public function parent(): BelongsTo
{
    return $this->belongsTo(RetailEdgeProduct::class, 'old_key', 'sku');
}
```

#### Critical Issue: Self-Referencing in `children()` Relationship

**Verified via database query:**
```php
$parent = RetailEdgeProduct::where('sku', '022-05646')->first();
$children = $parent->children;
// Returns 61 records - includes the parent itself!
$parentInChildren = $children->where('sku', '022-05646')->count();
// Result: 1 - Parent IS included in its own children!
```

**Problem:** For a parent product where `old_key = sku = '022-05646'`:
- `children()` finds products where `old_key = '022-05646'`
- This includes the parent itself because `parent.old_key = parent.sku`

**Impact on CreateProduct:**
- `$product->children->first()` may return the parent, not an actual child
- Variant creation loops through all "children" including parent

### 3.4 Valid Single-Variant Products

**Important Distinction:** Many products legitimately have only a single variant. These are:
- Standalone products where `old_key = sku`
- Products without size/color variations

The deletion logic must **only delete** products where:
1. The SKU is a **child** (`old_key != sku` in `retail_edge_products`)
2. AND the product is on the **wrong** Shopify product (not on the parent's product)

**DO NOT delete** single-variant products where the SKU is a parent/standalone (`old_key = sku`).

---

## 4. Root Cause Analysis

### 4.1 Primary Bug Locations

1. **File:** `app/Console/Commands/Shopify/CreateProduct.php`
2. **File:** `app/Models/RetailEdgeProduct.php`

### 4.2 Bug #1: Initial Query Includes Child Products

**Location:** `CreateProduct.php` Lines 56-60

**Problematic Code:**
```php
$pendingProductIds = DB::select("
    SELECT rep.id, rep.sku
    FROM retail_edge_products rep
    LEFT JOIN shopify_product_variants spv ON rep.sku = spv.sku
    WHERE spv.id IS NULL
");
```

**Problem Analysis:**
- This query finds ALL products in `retail_edge_products` that don't have a matching entry in `shopify_product_variants`
- It does NOT distinguish between:
  - Parent products (`old_key = sku`) - should be processed
  - Child products (`old_key != sku`) - should NOT be processed

### 4.3 Bug #2: Product Fetch Inconsistent with Count Query

**Location:** `CreateProduct.php` Lines 80-93

**Count Query (Line 80-84):**
```php
$countQuery = RetailEdgeProduct::whereIn('id', $pendingProductIds)
    ->whereHas('children', function ($children) {
        $children->where('uploaded_to_shopify', 0);
    })
    ->where('quantity', '>', 0);
```

**Product Fetch Query (Line 91-93):**
```php
$product = RetailEdgeProduct::withWhereHas('children', function ($children) {
    $children->where('uploaded_to_shopify', 0);
})->with(['brand'])->where('quantity', '>', 0)->first();
```

**Problem:** The product fetch does NOT use `whereIn('id', $pendingProductIds)`, causing inconsistency.

### 4.4 Bug #3: `children()` Relationship Includes Parent

**Location:** `RetailEdgeProduct.php` Lines 63-66

```php
public function children(): HasMany
{
    return $this->hasMany(RetailEdgeProduct::class, 'old_key', 'sku');
}
```

**Problem:** For parent products where `old_key = sku`, this relationship includes the parent itself as a "child".

### 4.5 How Duplicates Were Created

Based on analysis, the duplicates occurred due to a combination of factors:

1. **Incorrect Processing Order:**
   - Child products were processed before or instead of parent products
   - Each child was created as a standalone Shopify product

2. **Repeated Processing:**
   - Since child SKU wasn't properly linked to parent, it kept appearing as "not in Shopify"
   - Each sync run created another standalone product for the same child SKU
   - Result: 126 separate products for one child SKU

3. **Parent Never Created:**
   - The parent product `022-05646` was never created in Shopify
   - Its `uploaded_to_shopify` flag is still 0
   - Has 44 children still with `uploaded_to_shopify = 0`

---

## 5. Solution Design

### 5.1 Overview

Four-part solution:
1. **Cleanup:** Fix `DeleteDuplicateVariants` command to properly identify and delete incorrect products
2. **Prevention:** Fix `CreateProduct` command to prevent future duplicates
3. **Model Fix:** Fix `RetailEdgeProduct.children()` to exclude self-reference
4. **Resync:** After cleanup, resync parent products with correct children

### 5.2 Solution Part 1: Delete Command Fix

#### Current Behavior (Incorrect)
```
For each duplicate SKU:
1. Find all variants with this SKU
2. Keep the one with highest variant_id (newest)
3. Delete older variants
4. SKIP if variant is only one on product (Shopify restriction)
```

#### New Behavior (Correct)
```
For each duplicate SKU:
1. Check if SKU is a child (old_key != sku in retail_edge_products)
2. If NOT a child (standalone) → Skip (valid single-variant product)
3. If IS a child:
   a. Find parent SKU from old_key
   b. Find correct Shopify product (contains parent SKU)
   c. For each variant with this SKU:
      - If on correct parent product → KEEP
      - If on wrong product (standalone):
        - If only variant → DELETE ENTIRE PRODUCT
        - If multiple variants → DELETE JUST VARIANT
4. Reset uploaded_to_shopify flag for affected products
```

#### Logic Flow Diagram
```
┌─────────────────────────────────────────────────────────────────┐
│                    processDuplicateSku(sku)                     │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  1. Is this SKU a child? (old_key != sku)                       │
│     retailEdge = RetailEdgeProduct::where('sku', sku)->first()  │
│     isChild = retailEdge->old_key != retailEdge->sku            │
└─────────────────────────────────────────────────────────────────┘
                              │
              ┌───────────────┴───────────────┐
              ▼                               ▼
┌──────────────────────────┐    ┌──────────────────────────────────┐
│ isChild = FALSE          │    │ isChild = TRUE                   │
│ (old_key = sku)          │    │ (old_key != sku)                 │
│                          │    │                                  │
│ → SKIP (valid single-    │    │ → Continue processing            │
│   variant product)       │    │                                  │
└──────────────────────────┘    └──────────────────────────────────┘
                                              │
                                              ▼
                ┌─────────────────────────────────────────────────────┐
                │  2. Find correct Shopify product (parent)           │
                │     parentSku = retailEdge->old_key                 │
                │     correctProductId = ShopifyProductVariant        │
                │         ::where('sku', parentSku)->product_id       │
                └─────────────────────────────────────────────────────┘
                                              │
                                              ▼
                ┌─────────────────────────────────────────────────────┐
                │  3. For each variant with this SKU:                 │
                └─────────────────────────────────────────────────────┘
                                              │
                              ┌───────────────┴───────────────┐
                              ▼                               ▼
                ┌──────────────────────────┐    ┌──────────────────────────┐
                │ On correct parent        │    │ On wrong product         │
                │ product_id               │    │ (standalone)             │
                │                          │    │                          │
                │ → KEEP this variant      │    │ → DELETE                 │
                └──────────────────────────┘    └──────────────────────────┘
                                                              │
                                              ┌───────────────┴───────────────┐
                                              ▼                               ▼
                                ┌──────────────────────────┐    ┌──────────────────────────┐
                                │ Only variant on product  │    │ Multiple variants on     │
                                │                          │    │ product                  │
                                │ → DELETE ENTIRE PRODUCT  │    │ → DELETE JUST VARIANT    │
                                │   (productDelete)        │    │   (productVariantsBulk   │
                                │                          │    │    Delete)               │
                                └──────────────────────────┘    └──────────────────────────┘
```

### 5.3 Solution Part 2: Prevention Fix

#### Fix Location
**File:** `app/Console/Commands/Shopify/CreateProduct.php`

#### Change 1: Filter Initial Query
```php
// BEFORE (problematic):
$pendingProductIds = DB::select("
    SELECT rep.id, rep.sku
    FROM retail_edge_products rep
    LEFT JOIN shopify_product_variants spv ON rep.sku = spv.sku
    WHERE spv.id IS NULL
");

// AFTER (fixed):
$pendingProductIds = DB::select("
    SELECT rep.id, rep.sku
    FROM retail_edge_products rep
    LEFT JOIN shopify_product_variants spv ON rep.sku = spv.sku
    WHERE spv.id IS NULL
    AND (rep.old_key = rep.sku OR rep.old_key = '')  -- Only parent/standalone products
");
```

#### Change 2: Add `$pendingProductIds` to Product Fetch
```php
// BEFORE:
$product = RetailEdgeProduct::withWhereHas('children', function ($children) {
    $children->where('uploaded_to_shopify', 0);
})->with(['brand'])->where('quantity', '>', 0)->first();

// AFTER:
$product = RetailEdgeProduct::whereIn('id', $pendingProductIds)
    ->withWhereHas('children', function ($children) {
        $children->where('uploaded_to_shopify', 0);
    })->with(['brand'])->where('quantity', '>', 0)->first();
```

#### Change 3: Add Defensive Check
```php
// Add before processing any product:
if ($product->old_key !== $product->sku && $product->old_key !== '') {
    Log::warning("CreateProduct: Skipping child product", [
        'sku' => $product->sku,
        'old_key' => $product->old_key,
        'should_be_variant_of' => $product->old_key,
    ]);
    continue;
}
```

### 5.4 Solution Part 3: Model Fix

#### Fix Location
**File:** `app/Models/RetailEdgeProduct.php`

```php
// BEFORE:
public function children(): HasMany
{
    return $this->hasMany(RetailEdgeProduct::class, 'old_key', 'sku');
}

// AFTER:
public function children(): HasMany
{
    return $this->hasMany(RetailEdgeProduct::class, 'old_key', 'sku')
        ->whereRaw('old_key != sku');  // Exclude self-referencing parent
}
```

---

## 6. Implementation Details

### 6.1 Files to Modify

| File | Changes | Priority |
|------|---------|----------|
| `app/Console/Commands/Shopify/DeleteDuplicateVariants.php` | Add parent lookup, product deletion, child-only logic | High |
| `app/Console/Commands/Shopify/CreateProduct.php` | Add `old_key = sku` filter, use `$pendingProductIds` | High |
| `app/Models/RetailEdgeProduct.php` | Fix `children()` relationship | Medium |

### 6.2 DeleteDuplicateVariants.php Changes

#### 6.2.1 Add Import
```php
use App\Models\RetailEdgeProduct;
```

#### 6.2.2 Update Stats Array
```php
private array $stats = [
    'duplicates_found' => 0,
    'skipped_standalone' => 0,
    'variants_deleted' => 0,
    'products_deleted' => 0,
    'kept_on_correct_product' => 0,
    'deleted_database' => 0,
    'errors' => 0,
];
```

#### 6.2.3 Add isChildProduct Method
```php
/**
 * Check if a SKU is a child product (old_key != sku)
 *
 * @param string $sku The SKU to check
 * @return array{is_child: bool, parent_sku: string|null, retail_edge: RetailEdgeProduct|null}
 */
private function isChildProduct(string $sku): array
{
    $retailEdge = RetailEdgeProduct::where('sku', $sku)->first();

    if (!$retailEdge) {
        return ['is_child' => false, 'parent_sku' => null, 'retail_edge' => null];
    }

    // Empty old_key means standalone product
    if (empty($retailEdge->old_key)) {
        return ['is_child' => false, 'parent_sku' => null, 'retail_edge' => $retailEdge];
    }

    // If old_key = sku, it's a parent/standalone
    $isChild = $retailEdge->old_key !== $retailEdge->sku;

    return [
        'is_child' => $isChild,
        'parent_sku' => $isChild ? $retailEdge->old_key : null,
        'retail_edge' => $retailEdge,
    ];
}
```

#### 6.2.4 Add getCorrectProductId Method
```php
/**
 * Find the correct Shopify product ID for a child SKU
 *
 * @param string $parentSku The parent SKU to find
 * @return int|null The correct product_id, or null if not found
 */
private function getCorrectProductId(string $parentSku): ?int
{
    $parentVariant = ShopifyProductVariant::where('sku', $parentSku)->first();

    if (!$parentVariant) {
        Log::warning("DeleteDuplicateVariants: Parent SKU not found in Shopify", [
            'parent_sku' => $parentSku,
        ]);
        return null;
    }

    return $parentVariant->product_id;
}
```

#### 6.2.5 Update processDuplicateSku Method
```php
/**
 * Process a single SKU with duplicates
 * For child SKUs: keeps variants on correct parent product, deletes others
 * For parent/standalone SKUs: skips (valid single-variant products)
 */
private function processDuplicateSku(string $sku, bool $isDryRun): void
{
    $this->newLine();
    $this->line("  Processing SKU: {$sku}");

    // Check if this is a child product
    $childCheck = $this->isChildProduct($sku);

    if (!$childCheck['is_child']) {
        $this->info("    SKIP: Not a child product (old_key = sku or empty)");
        $this->stats['skipped_standalone']++;
        return;
    }

    $parentSku = $childCheck['parent_sku'];
    $this->line("    Parent SKU: {$parentSku}");

    // Find the correct product based on parent
    $correctProductId = $this->getCorrectProductId($parentSku);
    $variants = $this->getVariantsForSku($sku);

    $this->line("    Total variants found: {$variants->count()}");
    $this->line("    Correct product_id: " . ($correctProductId ?? 'NOT FOUND (parent not in Shopify)'));

    foreach ($variants as $variant) {
        // Check if this variant is on the correct product
        if ($correctProductId && $variant->product_id == $correctProductId) {
            $this->info("    KEEP variant_id: {$variant->variant_id} (on correct parent product)");
            $this->stats['kept_on_correct_product']++;
            continue;
        }

        // This variant is on wrong product - needs deletion
        $this->line("    DELETE variant_id: {$variant->variant_id} (on wrong product {$variant->product_id})");

        if ($isDryRun) {
            $productVariantCount = ShopifyProductVariant::where('product_id', $variant->product_id)->count();
            if ($productVariantCount <= 1) {
                $this->line("      [DRY RUN] Would delete entire product {$variant->product_id}");
            } else {
                $this->line("      [DRY RUN] Would delete variant from product");
            }
            continue;
        }

        // Check if this is the only variant on the product
        $productVariantCount = ShopifyProductVariant::where('product_id', $variant->product_id)->count();

        if ($productVariantCount <= 1) {
            // Delete entire product (can't delete last variant)
            $this->line("      Deleting entire product (only variant)...");
            $result = $this->deleteProductFromShopify($variant->product_id);

            if ($result['success']) {
                $this->stats['products_deleted']++;
                $this->cleanupStaleVariant($variant, 'DeleteDuplicateVariants');
                $this->stats['deleted_database']++;
                $this->info("      Deleted product successfully");
            } else {
                $this->handleDeletionError($result, $variant, $sku);
            }
        } else {
            // Delete just this variant
            $this->line("      Deleting variant from product...");
            $result = $this->deleteVariantFromShopify($variant->product_id, $variant->variant_id);

            if ($result['success']) {
                $this->stats['variants_deleted']++;
                $this->cleanupStaleVariant($variant, 'DeleteDuplicateVariants');
                $this->stats['deleted_database']++;
                $this->info("      Deleted variant successfully");
            } else {
                $this->handleDeletionError($result, $variant, $sku);
            }
        }

        // Rate limiting
        usleep(100000); // 100ms delay
    }
}
```

#### 6.2.6 Add deleteProductFromShopify Method
```php
/**
 * Delete an entire product from Shopify using GraphQL
 *
 * @param int $productId The Shopify product ID to delete
 * @return array Result with success status and any errors
 */
private function deleteProductFromShopify(int $productId): array
{
    $mutation = <<<'GRAPHQL'
    mutation productDelete($input: ProductDeleteInput!) {
      productDelete(input: $input) {
        deletedProductId
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $productGid = "gid://shopify/Product/{$productId}";

    try {
        $response = $this->client->query([
            'query' => $mutation,
            'variables' => [
                'input' => ['id' => $productGid],
            ],
        ]);

        $resultBody = json_decode($response->getBody()->getContents(), true);

        $userErrors = $resultBody['data']['productDelete']['userErrors'] ?? [];
        $graphqlErrors = $resultBody['errors'] ?? [];

        if (!empty($userErrors) || !empty($graphqlErrors)) {
            return [
                'success' => false,
                'user_errors' => $userErrors,
                'graphql_errors' => $graphqlErrors,
            ];
        }

        Log::info("DeleteDuplicateVariants: Deleted product from Shopify", [
            'product_id' => $productId,
            'deleted_id' => $resultBody['data']['productDelete']['deletedProductId'] ?? null,
        ]);

        return [
            'success' => true,
            'user_errors' => [],
            'graphql_errors' => [],
        ];
    } catch (\Exception $e) {
        Log::error("DeleteDuplicateVariants: Exception during product deletion", [
            'product_id' => $productId,
            'exception' => $e->getMessage(),
        ]);

        return [
            'success' => false,
            'user_errors' => [],
            'graphql_errors' => [['message' => $e->getMessage()]],
        ];
    }
}
```

#### 6.2.7 Add handleDeletionError Method
```php
/**
 * Handle deletion errors with appropriate logging and stats
 */
private function handleDeletionError(array $result, ShopifyProductVariant $variant, string $sku): void
{
    $errorMessage = $this->formatGraphQLErrorMessage($result);

    // Check if resource doesn't exist on Shopify (already deleted)
    if ($this->isResourceNotExistsError($errorMessage)) {
        $this->warn("      Not found on Shopify - cleaning database only");
        $this->cleanupStaleVariant($variant, 'DeleteDuplicateVariants');
        $this->stats['deleted_database']++;
    } else {
        $this->error("      Failed: {$errorMessage}");
        $this->stats['errors']++;

        Log::error("DeleteDuplicateVariants: Failed to delete", [
            'sku' => $sku,
            'variant_id' => $variant->variant_id,
            'product_id' => $variant->product_id,
            'error' => $errorMessage,
        ]);
    }
}
```

#### 6.2.8 Update displaySummary Method
```php
private function displaySummary(bool $isDryRun): void
{
    $this->newLine();
    $this->info('Summary:');
    $this->info('========');
    $this->info("  Duplicate SKUs processed: {$this->stats['duplicates_found']}");
    $this->info("  Skipped (standalone products): {$this->stats['skipped_standalone']}");
    $this->info("  Kept on correct product: {$this->stats['kept_on_correct_product']}");

    if ($isDryRun) {
        $this->warn('  [DRY RUN] No changes were made');
    } else {
        $this->info("  Products deleted from Shopify: {$this->stats['products_deleted']}");
        $this->info("  Variants deleted from Shopify: {$this->stats['variants_deleted']}");
        $this->info("  Database records cleaned: {$this->stats['deleted_database']}");
        $this->info("  Errors: {$this->stats['errors']}");
    }

    if ($this->stats['errors'] > 0) {
        $this->error("Completed with {$this->stats['errors']} errors. Check logs for details.");
    } else {
        $this->info('Completed successfully.');
    }
}
```

### 6.3 CreateProduct.php Changes

#### 6.3.1 Update Initial Query (Lines 56-60)
```php
// Add filter to only process parent/standalone products
$pendingProductIds = DB::select("
    SELECT rep.id, rep.sku
    FROM retail_edge_products rep
    LEFT JOIN shopify_product_variants spv ON rep.sku = spv.sku
    WHERE spv.id IS NULL
    AND (rep.old_key = rep.sku OR rep.old_key = '')
");
```

#### 6.3.2 Update Product Fetch to Use $pendingProductIds (Line 91-93)
```php
$product = RetailEdgeProduct::whereIn('id', $pendingProductIds)
    ->withWhereHas('children', function ($children) {
        $children->where('uploaded_to_shopify', 0);
    })->with(['brand'])->where('quantity', '>', 0)->first();
```

#### 6.3.3 Add Defensive Check (Before Processing Loop)
```php
// Verify this is a parent/standalone product before processing
if ($product->old_key !== $product->sku && $product->old_key !== '') {
    Log::warning("CreateProduct: Attempted to process child product as parent", [
        'sku' => $product->sku,
        'old_key' => $product->old_key,
    ]);
    continue;
}
```

### 6.4 RetailEdgeProduct.php Changes

```php
public function children(): HasMany
{
    return $this->hasMany(RetailEdgeProduct::class, 'old_key', 'sku')
        ->whereRaw('old_key != sku');  // Exclude self-referencing parent
}
```

---

## 7. Testing Plan

### 7.1 Pre-Implementation Testing

```sql
-- Count current duplicates
SELECT COUNT(*) as duplicate_skus FROM (
    SELECT sku, COUNT(*) as cnt
    FROM shopify_product_variants
    GROUP BY sku
    HAVING cnt > 1
) t;

-- Find child SKUs with duplicates
SELECT rep.sku, rep.old_key, COUNT(spv.id) as shopify_count
FROM retail_edge_products rep
JOIN shopify_product_variants spv ON rep.sku = spv.sku
WHERE rep.old_key != rep.sku AND rep.old_key != ''
GROUP BY rep.sku, rep.old_key
HAVING COUNT(spv.id) > 1
ORDER BY shopify_count DESC
LIMIT 10;
```

### 7.2 Dry Run Testing

```bash
# Test with specific child SKU first
php artisan shopify:delete-duplicate-variants --dry-run --sku=022-04392

# Review output to verify:
# - SKU is identified as child
# - Parent SKU is correct (022-05646)
# - Correct product is identified (if parent exists)
# - All 126 wrong products would be deleted
```

### 7.3 Limited Production Test

```bash
# Test with single child SKU (actual deletion)
php artisan shopify:delete-duplicate-variants --sku=022-04392 --force
```

### 7.4 Full Execution

```bash
# Full dry run
php artisan shopify:delete-duplicate-variants --dry-run

# Full execution
php artisan shopify:delete-duplicate-variants --force
```

### 7.5 Post-Execution Verification

```sql
-- Verify no more child duplicates
SELECT rep.sku, COUNT(spv.id) as cnt
FROM retail_edge_products rep
JOIN shopify_product_variants spv ON rep.sku = spv.sku
WHERE rep.old_key != rep.sku AND rep.old_key != ''
GROUP BY rep.sku
HAVING cnt > 1;

-- Verify parent products can now be created
SELECT COUNT(*) FROM retail_edge_products
WHERE old_key = sku AND uploaded_to_shopify = 0;
```

---

## 8. Rollback Plan

### 8.1 If Deletion Goes Wrong

The `cleanupStaleVariant` method from `ShopifyCleanupTrait` automatically:
1. Deletes variant from local database
2. Resets `uploaded_to_shopify = 0` in `retail_edge_products`

**To recreate deleted products:**
```bash
# Run create product command - will pick up products with uploaded_to_shopify = 0
php artisan shopify:create-product
```

### 8.2 Manual Recovery

```sql
-- Find SKUs that need recreation
SELECT rep.sku, rep.old_key
FROM retail_edge_products rep
LEFT JOIN shopify_product_variants spv ON rep.sku = spv.sku
WHERE spv.id IS NULL
AND (rep.old_key = rep.sku OR rep.old_key = '');

-- Reset upload flag for specific parent SKU if needed
UPDATE retail_edge_products SET uploaded_to_shopify = 0 WHERE old_key = '022-05646';
```

---

## 9. Appendix

### 9.1 SQL Queries for Analysis

```sql
-- Find all duplicate SKUs with counts
SELECT sku, COUNT(*) as duplicate_count
FROM shopify_product_variants
WHERE sku IS NOT NULL AND sku != ''
GROUP BY sku
HAVING COUNT(*) > 1
ORDER BY duplicate_count DESC;

-- Find child SKUs with duplicates (the ones we need to fix)
SELECT rep.sku, rep.old_key as parent_sku, COUNT(spv.id) as shopify_count
FROM retail_edge_products rep
JOIN shopify_product_variants spv ON rep.sku = spv.sku
WHERE rep.old_key != rep.sku AND rep.old_key != ''
GROUP BY rep.sku, rep.old_key
HAVING COUNT(spv.id) > 1
ORDER BY shopify_count DESC;

-- Find parent products that need to be created
SELECT parent.sku, parent.title, COUNT(child.id) as child_count
FROM retail_edge_products parent
LEFT JOIN retail_edge_products child ON child.old_key = parent.sku AND child.sku != parent.sku
LEFT JOIN shopify_product_variants spv ON parent.sku = spv.sku
WHERE parent.old_key = parent.sku
AND spv.id IS NULL
AND parent.quantity > 0
GROUP BY parent.sku, parent.title
ORDER BY child_count DESC
LIMIT 20;

-- Database statistics
SELECT
    (SELECT COUNT(*) FROM retail_edge_products WHERE old_key = sku) as parents,
    (SELECT COUNT(*) FROM retail_edge_products WHERE old_key != sku AND old_key != '') as children,
    (SELECT COUNT(*) FROM retail_edge_products WHERE old_key = '') as empty_old_key;
```

### 9.2 GraphQL Mutations Reference

#### Delete Product
```graphql
mutation productDelete($input: ProductDeleteInput!) {
  productDelete(input: $input) {
    deletedProductId
    userErrors {
      field
      message
    }
  }
}

# Variables:
{
  "input": {
    "id": "gid://shopify/Product/123456789"
  }
}
```

#### Delete Variants (Bulk)
```graphql
mutation productVariantsBulkDelete($productId: ID!, $variantsIds: [ID!]!) {
  productVariantsBulkDelete(productId: $productId, variantsIds: $variantsIds) {
    product {
      id
      title
    }
    userErrors {
      field
      message
    }
  }
}

# Variables:
{
  "productId": "gid://shopify/Product/123456789",
  "variantsIds": ["gid://shopify/ProductVariant/987654321"]
}
```

### 9.3 Related Files

| File | Purpose |
|------|---------|
| `app/Console/Commands/Shopify/CreateProduct.php` | Creates Shopify products from RetailEdge |
| `app/Console/Commands/Shopify/DeleteDuplicateVariants.php` | Deletes duplicate variants |
| `app/Console/Commands/Shopify/GetProducts.php` | Syncs products from Shopify to local DB |
| `app/Console/Commands/EWeb/GetProductsFromEWebMain.php` | Syncs products from RetailEdge |
| `app/Console/Commands/Jobs/JobOrchestrator.php` | Orchestrates sync job chains |
| `app/Models/RetailEdgeProduct.php` | RetailEdge product model with parent/child relationships |
| `app/Models/ShopifyProductVariant.php` | Shopify variant model |
| `app/Traits/ShopifyCleanupTrait.php` | Cleanup utilities for stale records |
| `app/Traits/ShopifyErrorFormatterTrait.php` | Error formatting utilities |

### 9.4 Command Reference

```bash
# Delete duplicates (preview)
php artisan shopify:delete-duplicate-variants --dry-run

# Delete duplicates (execute)
php artisan shopify:delete-duplicate-variants --force

# Delete specific SKU
php artisan shopify:delete-duplicate-variants --sku=022-04392

# Create products (after cleanup)
php artisan shopify:create-product

# Sync products from Shopify
php artisan shopifyGetProducts

# Full sync chain
php artisan job:orchestrator main-sync
php artisan job:orchestrator shopify-sync
```

---

**Document End**
