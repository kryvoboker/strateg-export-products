[← Product Updates](product-update-batch-resource.md) · [Back to README](../README.md) · [Product Catalog →](product-resource.md)

# ProductDeleteResource

## Purpose

Product Deletes removes products from a selected remote shop. It does not delete the local catalog row. Each deletion is recorded as a batch item and is executed only after a remote backup request succeeds.

Deletion can be started from a Product Deletes batch, an import or update item, or the catalog product table. The shared `ProductDeleteQueueService` keeps the shop selection, duplicate prevention, retry rules, and batch status handling consistent across those entry points.

## Input modes

The create form accepts an Excel file or a Google Sheets URL. The source document must contain a `Product` worksheet. Each row must identify a product with one of these strategies:

| Strategy            | Required values                                         |
|---------------------|---------------------------------------------------------|
| Direct local lookup | `Product Id`                                            |
| Remote lookup       | `Shop Id` plus `External Product Id`, `Model`, or `EAN` |

When several lookup values are supplied, they are intersected and must resolve to one product. An ambiguous or unresolved row becomes a failed delete item with its source row and error message retained.

## Queue and execution

1. `CreateProductDelete` stores the source metadata and dispatches `ProcessProductDeleteBatchJob`.
2. The preparation job normalizes Excel or Google Sheets rows and creates `ProductDeleteItem` records.
3. The relation manager queues a single item or a bulk selection through `ProductDeleteQueueService`.
4. `ProcessProductDeleteItemJob` resolves the shop binding, requests a remote backup, and calls the configured delete endpoint.
5. The item and parent batch counters are synchronized. Failed items can be retried.

Deletion requires a `spme_product_shop` binding and a non-empty `external_product_id`. The remote backup is stored in `spme_product_backups` before deletion when the shop API supports the configured backup endpoint.

## Statuses

Batch statuses are `new`, `processing`, `completed`, `failed`, `partial_failed`, and `canceled`. Item statuses are `new`, `processing`, `deleted`, and `failed`.

## See Also

- [Product Data Workflows](product-data-workflows.md) — complete product lifecycle.
- [Remote API Integration](remote-api-integration.md) — delete, backup, and restore request configuration.
- [Stores](shop-resource.md) — remote endpoint settings.