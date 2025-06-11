# Dynamic Metafield Assignment Implementation Guide

## Overview

This implementation provides dynamic metafield assignment for Shopify products based on the RetailEdgeProduct hierarchy. The system automatically determines whether metafields should be assigned to the product level or variant level based on the data structure and commonality of values.

## Key Components

### 1. MetafieldAssignmentService (`app/Services/MetafieldAssignmentService.php`)

**Purpose**: Analyzes RetailEdgeProduct data to determine optimal metafield assignment strategy.

**Logic**:
- **Single Variant (≤1 child)**: All metafields assigned to PRODUCT level
- **Multiple Variants (>1 children)**: 
  - Common values across all variants → PRODUCT level
  - Different values across variants → VARIANT level
  - Missing ISDs in some children → Skip metafield

### 2. Enhanced Metafield Definition Creation (`app/Console/Commands/Shopify/ShopifyCreateMetafieldDefinitions.php`)

**Changes**:
- Creates **dual metafield definitions** for each ISD name
- One with `ownerType = "PRODUCT"` (key suffix: `_product`)
- One with `ownerType = "PRODUCTVARIANT"` (key suffix: `_variant`)

**Example**:
```
ISD Name: "Material Type"
→ Creates:
  - material_type_product (PRODUCT owner)
  - material_type_variant (PRODUCTVARIANT owner)
```

### 3. Updated Product Update Command (`app/Console/Commands/Shopify/UpdateProduct.php`)

**Changes**:
- Integrates MetafieldAssignmentService
- Dynamically assigns metafields based on analysis
- Batch processes all metafields using GraphQL `metafieldsSet` mutation
- Provides detailed logging for debugging

### 4. Updated Product Creation Command (`app/Console/Commands/Shopify/CreateProduct.php`)

**Changes**:
- Uses MetafieldAssignmentService for new product creation
- Handles product-level metafields during creation via REST API
- Note: Variant metafields still need to be handled post-creation via GraphQL

## Usage Instructions

### Step 1: Create Metafield Definitions

First, run the metafield definition creation command to set up dual definitions:

```bash
php artisan shopify:create-metafield-definitions
```

This will create both PRODUCT and PRODUCTVARIANT definitions for each unique ISD name.

### Step 2: Update Existing Products

Run the update command to apply dynamic metafield assignment:

```bash
php artisan shopify:update-product
```

### Step 3: Create New Products

Use the existing create command which now includes dynamic metafield logic:

```bash
php artisan shopifyCreateProduct
```

## Example Scenarios

### Scenario 1: Single Variant Product
```
Product: Necklace (SKU: NECK001)
└── Child: NECK001-GOLD (Material: Gold, Stone: Diamond)

Result: All metafields assigned to PRODUCT level
- Material: Gold → Product metafield
- Stone: Diamond → Product metafield
```

### Scenario 2: Multi-Variant with Common Values
```
Product: Ring (SKU: RING001)
├── Child 1: RING001-S-GOLD (Size: Small, Material: Gold, Stone: Diamond)
├── Child 2: RING001-M-GOLD (Size: Medium, Material: Gold, Stone: Diamond)
└── Child 3: RING001-L-GOLD (Size: Large, Material: Gold, Stone: Diamond)

Result: Mixed assignment
- Stone: Diamond (common) → Product metafield
- Material: Gold (common) → Product metafield
- Size: Small/Medium/Large (different) → Variant metafields
```

### Scenario 3: Multi-Variant with Different Values
```
Product: Bracelet (SKU: BRAC001)
├── Child 1: BRAC001-S-GOLD (Size: Small, Material: Gold, Stone: Ruby)
├── Child 2: BRAC001-M-SILVER (Size: Medium, Material: Silver, Stone: Emerald)
└── Child 3: BRAC001-L-GOLD (Size: Large, Material: Gold, Stone: Diamond)

Result: All variant-specific
- Size: Different values → Variant metafields
- Material: Different values → Variant metafields
- Stone: Different values → Variant metafields
```

## Database Schema

### ShopifyMetafield Table
The existing table now stores both PRODUCT and PRODUCTVARIANT definitions:

```sql
| id | name          | namespace | key                    | type                   | owner_type      | gid                |
|----|---------------|-----------|------------------------|------------------------|-----------------|-------------------|
| 1  | Material Type | custom    | material_type_product  | multi_line_text_field  | PRODUCT         | gid://shopify/... |
| 2  | Material Type | custom    | material_type_variant  | multi_line_text_field  | PRODUCTVARIANT  | gid://shopify/... |
```

## Error Handling

The system includes comprehensive error handling:

1. **Missing Metafield Definitions**: Warns and skips if definition not found
2. **Empty Values**: Skips metafields with empty values
3. **API Errors**: Logs detailed error information with context
4. **Missing ISDs**: Skips metafields if not all children have the ISD

## Logging

The implementation provides detailed logging:

- Metafield assignment type (PRODUCT_ONLY vs MIXED)
- Number of metafields being processed
- Individual metafield assignments
- API errors with specific metafield context

## Performance Considerations

1. **Batch Processing**: Uses GraphQL `metafieldsSet` for efficient batch updates
2. **Rate Limiting**: Includes 1-second delays between API calls
3. **Optimized Queries**: Uses eager loading for related data

## Backward Compatibility

- **Existing Metafields**: Manual cleanup required from Shopify admin (as requested)
- **Database**: No migration needed, uses existing schema
- **API**: Maintains existing GraphQL mutation structure

## Testing

To test the implementation:

1. Ensure you have RetailEdgeProducts with various child configurations
2. Run metafield definition creation
3. Run product updates and monitor logs
4. Verify metafield assignments in Shopify admin

## Troubleshooting

### Common Issues:

1. **"Definition not found"**: Run metafield definition creation first
2. **"Empty value"**: Check RetailEdgeProductIsd data for empty isd_value
3. **API Rate Limits**: Increase delay between calls if needed
4. **Missing Children**: Verify RetailEdgeProduct relationships are correct

### Debug Commands:

```bash
# Check metafield definitions
php artisan tinker
>>> App\Models\ShopifyMetafield::where('name', 'Material Type')->get();

# Check product relationships
>>> App\Models\RetailEdgeProduct::with('children')->find(1);
```

## Future Enhancements

Potential improvements:
1. Add configuration for metafield assignment rules
2. Implement metafield value validation
3. Add support for different metafield types per ISD
4. Create admin interface for metafield management
