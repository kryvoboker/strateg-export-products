[← Supports Reference](supports.md) · [Back to README](../README.md) · [Product Import →](product-import-batch-resource.md)

# Product Data Workflows

This page describes the complete product lifecycle from source document or admin form to the local catalog and, when requested, a remote shop.

## Workflow overview

```text
Excel / Google Sheets / Admin form
              |
              v
      Product import batch
              |
              v
   Normalize rows and persist items
              |
              v
       Local product catalog
              |
       bind to a shop
              |
              v
  export -> update -> delete / restore
              |
              v
       Remote shop product
```

Every long-running operation is represented by a batch and item model. Jobs update item status and the parent batch counters. Failures are kept in the item error message and in the batch error audit metadata, with a detailed snapshot stored on the public storage disk.

## Import and create products

The Product Imports form accepts three input modes:

| Source | Form input | Preparation job | Result |
|---|---|---|---|
| Local Excel | `.xlsx` or `.xls` upload | `ProcessProductImportBatchJob` | One normalized import item per product row |
| Google Sheets | Spreadsheet URL containing the document ID | `ProcessProductImportBatchJob` | Sheets are read through `revolution/laravel-google-sheets`, exported to local Excel chunks, then normalized |
| Admin form | Product fields, multilingual descriptions, images, discounts, specials, categories, attributes, and SEO values | `ProcessProductImportBatchJob` | One manual import item with a normalized payload |

Excel imports are checked before the batch is created. The required worksheets are `Product`, `Description`, `Image`, `Product Category`, `Product Attribute`, `Seo Url`, `Special`, and `Discount`. The import job performs the same sheet and header checks for Google Sheets and records row-level failures.

Import preparation creates or updates local products and related catalog rows. A product can then be bound to one or more shops. Binding may reuse the existing family member or duplicate a product for shop isolation; `family_ulid` keeps those copies connected.

## Export products to a shop

Export is initiated from an import batch, import item, or the catalog product table. `ProductExportQueueService` creates export items and dispatches `ProcessProductExportItemJob`.

An export item is eligible only when:

- the local product exists;
- the selected shop exists;
- a `spme_product_shop` binding exists for the product and shop; and
- the export item is not already processing or successfully exported.

`ProductPayloadBuilderService` builds a shop-scoped payload containing the product, descriptions, images, categories, attributes, manufacturer, brand, specials, discounts, and active shop languages. A successful remote response must contain an external product ID. That ID is saved to `spme_product_shop.external_product_id` and is required for later update, delete, and restore operations.

## Update products

Product Updates accepts Excel or Google Sheets documents. Its `Product` sheet identifies each row by at least one of `Product Id`, `External Product Id`, `Model`, or `EAN`. When a lookup uses `External Product Id`, `Model`, or `EAN`, `Shop Id` is required. Multiple supplied identifiers must resolve to the same product.

The update preparation job:

1. Reads each worksheet and normalizes headers and cell values.
2. Resolves the local product and shop binding.
3. Saves a local backup before applying local field, description, image, category, attribute, manufacturer, brand, SEO, special, and discount changes.
4. Stores update instructions in `ProductUpdateItem`.
5. Lets an operator queue updates for selected shops.

The API update job creates an external backup before the remote update, builds a shop-scoped payload, and calls the configured update endpoint. Products without a shop binding or external ID are skipped and reported in the action summary.

The catalog edit page uses the same update queue service for field-level API modes. Each field can be skipped, deleted, or updated, and the resulting instructions are stored with the update item.

## Delete products from a shop

Product Deletes accepts Excel or Google Sheets documents. The `Product` sheet resolves a target by `Product Id`, or by a combination of `Shop Id` and one or more of `External Product Id`, `Model`, or `EAN`. A row with no identifier becomes a failed delete item instead of being silently ignored.

Delete preparation creates `ProductDeleteItem` records. The operator then queues individual or bulk deletion. The item job first requests a remote backup, then calls the remote delete endpoint. A delete is shop-specific and does not remove the local product row. Failed items can be retried from the relation manager.

## Restore products

Restore has two scopes:

| Scope | Input | Job/service | Effect |
|---|---|---|---|
| Local catalog restore | Existing import items or catalog products | `ProductRestoreQueueService`, restore jobs, `ProductBackupRestoreService` | Replaces local product and related rows from a local snapshot |
| Remote shop restore | Exported product binding with a valid external ID | Update/restore queue and remote restore job | Sends an external backup payload back to the selected shop |

Restore is available only when a valid, unused backup exists for the relevant product and shop context. The tuple `(product_id, shop_id, external_product_id)` identifies external snapshots.

## Status and audit behavior

| Layer | Typical states | Stored in |
|---|---|---|
| Import/update/delete batch | `new`, `processing`, `completed`, `failed`, `partial_failed`, `canceled` | Batch table |
| Import/update item | `new`, `processing`, `normalized`, `successed`, `failed`, `not_queued` | Item table |
| Delete item | `new`, `processing`, `deleted`, `failed` | Delete item table |
| Export item | `processing`, `normalized`, `exported`, `partial_failed`, `failed` | Export item table |

Use the batch counters and item records as the operational source of truth. Check `options.last_error`, `options.error_log_path`, and the linked error-log snapshot when a batch is partially or fully failed.

## See Also

- [Product Imports](product-import-batch-resource.md) — import resource pages and actions.
- [Product Updates](product-update-batch-resource.md) — update-specific document rules.
- [Remote API Integration](remote-api-integration.md) — outbound shop requests and API status.
