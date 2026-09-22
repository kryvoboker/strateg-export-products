[← Product Updates](product-update-batch-resource.md) · [Back to README](../README.md) · [Categories →](category-resource.md)

# ProductResource (Catalog → Products)

## Purpose

The resource lists local products and allows users to edit products, bind products to stores, export products, and queue API updates.

## Resource Pages

- index (ListProducts): product list.
- create (CreateProduct): create a product.
- edit (EditProduct): edit a product.

Total: 3 pages.

## How to Use

Use filters and search for name, SKU, model, EAN, external product ID, quantity, price, category, attribute, or store. Toolbar actions support binding, export, and updates. On the edit page, save local changes and optionally select Update through API in online store.

## API Update Settings

The API Update Settings tab provides Skip, Delete, and Update modes for each field. These modes become update_instructions for API updates.

## List Columns and Indicators

- Stores linked to the product.
- Batch IDs linked to the product.
- Processed import state.
- Exported state based on external_product_id.

## See Also

- [Documentation Contents](README.md) — complete documentation index.
- [Product Updates](product-update-batch-resource.md) — update batches.
- [Stores](shop-resource.md) — store integrations.
