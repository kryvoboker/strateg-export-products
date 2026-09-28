[← Product Import](product-import-batch-resource.md) · [Back to README](../README.md) · [Product Deletes →](product-delete-batch-resource.md)

# ProductUpdateBatchResource

## Purpose

The ProductUpdates resource uploads update batches and performs bulk or individual product updates in stores through their APIs.

## Resource Pages

- index (ListProductUpdateBatches): update batch list.
- create (CreateProductUpdateBatch): create an update batch from Excel or Google Sheets.
- view (ViewProductUpdateBatch): batch details and update items (ProductUpdateItemsRelationManager).

Total: 3 pages.

## How to Use

1. Open Product Updates → Upload Products for Update.
2. Upload an Excel file or enter a Google Sheets URL.
3. The batch is processed by ProcessProductUpdateBatchJob.
4. Inspect items and payloads, update stores in bulk or individually, and retry failed updates.

The `Product` sheet identifies a product with `Product Id`, or with `Shop Id` plus one or more of `External Product Id`, `Model`, and `EAN`. When several identifiers are supplied, they must resolve to the same product. The preparation job creates a local backup, applies local changes, and stores the remote update instructions in the item payload before an API update is queued.

## Statuses

Sources: CSV file, Excel file, Google Sheet, Local products, and Individual API update from Edit Product.

Batch statuses: New, Processing, Updating through API, Completed, Failed, Partially failed, and Cancelled.

Item statuses: New, Processing, Normalized, Succeeded, Failed, and Not queued.

## Main Actions

- Bulk-update products in stores.
- Retry failed updates.
- For an item: view payload, update stores, or retry failed updates.

## Common Skip Reasons

- The product is not linked to the selected store.
- The binding has no external_product_id.
- The record is already queued, updated, or already has a failed record.

See [Product Data Workflows](product-data-workflows.md) for the full update and restore lifecycle.

## See Also

- [Documentation Contents](README.md) — complete documentation index.
- [Product Catalog](product-resource.md) — catalog update actions.
- [Stores](shop-resource.md) — store integrations.
