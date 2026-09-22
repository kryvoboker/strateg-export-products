[← Helper Functions](helpers.md) · [Back to README](../README.md) · [Product Updates →](product-update-batch-resource.md)

# ProductImportBatchResource

## Purpose

The ProductImports resource manages product imports from Excel, Google Sheets, or manual administration-panel input. It shows batch processing state and supports binding and exporting products to stores.

## Resource Pages

- index (ListProductImportBatches): import batch list.
- create (CreateProductImportBatch): create an import batch.
- view (ViewProductImportBatch): batch details and import items (ProductImportItemsRelationManager).

Total: 3 pages.

## How to Use

1. Open Product Imports → Upload New Products.
2. Select Excel file, Google Sheets, or Administration panel.
3. The batch is processed by ProcessProductImportBatchJob.
4. Review statuses, payloads, product edits, store bindings, exports, and retries on the list or view page.

## Statuses

Source values: EXCEL, Google Sheets, and Administration panel.

Batch statuses: New, Processing, Exporting to API, Completed, Failed, Partially failed, and Cancelled.

Import item statuses: New, Processing, Normalized, Succeeded, and Failed.

Export statuses: Not queued, Exporting, Exported, Export failed, and Partially failed.

## Main Actions

- View result.
- Download or delete the error log.
- Bulk-link products to stores.
- Bulk-export products to stores.
- Retry failed exports.
- For an item: view payload, edit the product, export to stores, or retry failed exports.

## Common Validation Messages

- Excel file is missing.
- Google Sheets URL is invalid.
- A required sheet or column is missing.
- The Product sheet has no data.
- The user is not authenticated.
- The product is not linked to a store.

## See Also

- [Documentation Contents](README.md) — complete documentation index.
- [Product Catalog](product-resource.md) — local product operations.
- [Stores](shop-resource.md) — store integrations.
