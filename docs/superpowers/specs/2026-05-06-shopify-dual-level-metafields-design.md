# Shopify Dual-Level Metafields & Variant Design Number

**Date:** 2026-05-06
**Branch:** feature/shopify-code-improvements
**Status:** Design approved, awaiting implementation plan

## Goals

1. Write Shopify metafields at **both** product and variant level when a value is uniform across siblings, instead of only at one level. Differing-per-variant fields stay variant-only.
2. Surface the **full design number** (e.g., `1581-18WD3-SX`) on each variant via a new `design_number_variant` metafield. The product description keeps its existing `prefix-before-hyphen` form.
3. Reconcile existing 12k+ products on Shopify with the new placement rule via a one-time backfill. Side-effect: cleans up the ~6,177 cross-placed ISD pairs flagged in `SHOPIFY_METAFIELDS_AUDIT.md` §5.1.

## Non-goals

- Changing the namespace convention (`custom` stays).
- Changing the metafield key suffix convention (`_product` / `_variant` stays).
- Changing the parent product description format (`explode('-', $real_design_number)[0]` stays).
- Adding a metaobject layer.
- Recurring backfill in the orchestrator chain (decided against for now; see §6).

## Background

- `MetafieldAssignmentService::determineMetafieldAssignment()` is the existing chooser. Today:
  - `≤1 child` → all ISDs go to PRODUCT only.
  - Multi-variant → uniform values → PRODUCT only; differing values → VARIANT only.
- `ShopifyCreateMetafieldDefinitions` already creates definitions at both `PRODUCT` and `PRODUCTVARIANT` scope, with keys `{base}_product` and `{base}_variant`. So the schema layer already supports dual-level writes.
- Description code in `CreateProduct.php:879`, `UpdateProduct.php:309`, `UpdateProductDescriptions.php:351` already does `explode('-', ...)[0]`. The parent half of Task 2 is done.
- 5 metafield definitions are missing per the audit: `TotStnWeight` plus four `Strap*` fields. They reappear when `shopify:create-metafield-definitions` is re-run, provided the underlying ISD rows exist in `retail_edge_product_isds`.

## Design

### 1. `MetafieldAssignmentService` — dual-level writes

Modify `analyzeForMultiVariant()`:
- Uniform value across all siblings → write to product **and** to each variant (today: product only).
- Differing values → write to that variant only (unchanged).

Modify `getAllProductISDs()` (single-variant case):
- `childrenCount === 1` → write to product **and** to the single variant.
- `childrenCount === 0` → product only.

Return shape unchanged: `['type' => ..., 'product_metafields' => [...], 'variant_metafields' => [...]]`. The `variant_metafields` array becomes a `[sku => [...]]` map even in the previously-empty cases.

Callers (`CreateProduct`, `UpdateProduct`) already iterate both arrays; no caller change required beyond accepting more rows.

### 2. Variant `design_number_variant` metafield

New definition (one entry, not derived from ISD):
- Owner: `PRODUCTVARIANT`
- Namespace: `custom`
- Key: `design_number_variant`
- Type: `single_line_text_field`
- Name: `Design Number`

Registered via `ShopifyCreateMetafieldDefinitions` — small inline block after the ISD loop, idempotent (skips if `shopify_metafields` row already exists for that key + owner).

Write path: `CreateProduct.php` and `UpdateProduct.php` append `{ key: 'design_number_variant', value: $variant_real_design_number }` to each variant's metafield payload. Value source = the variant's own RetailEdge `real_design_number`. Mirror row written to `shopify_product_variant_metafields`.

### 3. Backfill command — `shopify:backfill-metafields`

```
shopify:backfill-metafields {--dry-run} {--force} {--sku=} {--limit=}
```

Mirrors the shape of `shopify:delete-duplicate-products`. Uses `ShopifyErrorFormatterTrait`. Logs via `SyncLogger` with a new constant `OP_METAFIELD_BACKFILL`.

Per parent product:
1. Resolve parent RetailEdge SKU via `shopify_product_variants.sku` join (NOT `shopify_products.sku`).
2. Call `MetafieldAssignmentService::determineMetafieldAssignment()`.
3. Read existing Shopify metafields for the product and its variants.
4. Diff and `metafieldsSet` only missing/changed entries to keep runs idempotent.
5. Set `design_number_variant` on each variant from its RetailEdge `real_design_number`.
6. Mirror writes to `shopify_product_metafields` and `shopify_product_variant_metafields`.

Rate limit: 100 ms sleep between mutations (same as `DeleteDuplicateProducts`).

### 4. Chain integration

The backfill is **not** wired into the recurring `shopify-sync` chain in this work — it runs on-demand only for the initial cleanup. Rationale in §6.

If we later decide to add it (e.g., drift is observed), the slot would sit between `shopify:delete-duplicate-variants` and `shopifyCreateProduct`:

```
1. shopifyGetProducts
2. shopify:delete-duplicate-products
3. shopify:delete-duplicate-variants
4. shopify:backfill-metafields        ← future, not part of this work
5. shopifyCreateProduct
6. shopifyUploadImages
7. shopifyArchiveProducts
```

## Production rollout

```
1. php artisan shopify:create-metafield-definitions
2. php artisan shopify:backfill-metafields --dry-run
3. php artisan shopify:backfill-metafields --force --limit=100
4. php artisan shopify:backfill-metafields --force
```

Step 1 registers `design_number_variant` plus any of the 5 audit-flagged definitions whose underlying ISD rows now exist. Steps 2–4 stage the backfill exactly as the dedup work was staged.

## Tradeoffs and decisions (§6)

**Why dual-level instead of variant-only?** Storefront filters and theme code can read product-level metafields without enumerating variants — useful for collection pages. Mirroring uniform values to the parent supports that without lying about per-variant differences.

**Why mirror to variant in the single-child case?** Consistency. A merchant reading a variant in the admin sees the same data shape regardless of whether the parent has one child or many. The cost is one extra metafield row per single-variant product, ~negligible.

**Why backfill is not in the recurring chain initially.** First run is heaviest (~12k products). After that the diff is empty and the command is a no-op, but the join cost still runs every 3 hours. Decision: run on-demand for the initial cleanup; revisit recurring inclusion only if drift is observed.

**Why `design_number_variant` is one synthetic definition, not a generalised "synthetic ISD" abstraction.** Single entry doesn't justify an abstraction. If a second non-ISD variant metafield ever appears, refactor at that point.

## Risks & mitigations

- **Rate limit on backfill.** 100 ms inter-mutation sleep + `--limit` flag for staged runs. Same pattern that worked for `delete-duplicate-products`.
- **Wrong join during backfill.** Documented project memory `shopify_data_model.md` covers the `shopify_products.sku` empty-column trap. Implementation must join via `shopify_product_variants.sku`.
- **Diff logic miss.** Idempotent diff prevents repeated rewrites and keeps reruns safe.
- **Missing ISD rows for the 5 audit-flagged definitions.** Re-running `shopify:create-metafield-definitions` won't help if the underlying `retail_edge_product_isds` rows are absent. Out of scope for this design; flagged as a data-side follow-up.

## Out of scope

- Reconciling RetailEdge ISD source data for any of the 5 audit-flagged definitions whose ISD rows are missing.
- Removing the cross-placement audit findings via a separate migration script (handled implicitly by the backfill).
- Re-running `shopify:create-metafield-definitions` automatically; remains a manual deploy step.
