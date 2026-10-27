[← Users](user-resource.md) · [Back to README](../README.md) · [Security →](SECURITY.md)

# Project: Strateg Export Products

## Overview
Strateg Export Products is an internal admin application for maintaining a shared product catalog and synchronizing it with multiple external shops. The application imports catalog data from Excel or Google Sheets, stores a normalized local representation in PostgreSQL, binds products to one or more shops, translates multilingual content, exports products to shop APIs, updates already exported products, deletes remote products, and restores products from saved backups.

The application is not a simple CRUD panel. The important business logic is concentrated in queue jobs and services that prepare batches, normalize row payloads, duplicate products when a product family must exist in more than one shop, synchronize shop-scoped catalog entities, maintain multilingual rows, and keep traceable statuses in batch/item tables.

## Tech Stack
- **Language:** PHP 8.5.3
- **Framework:** Laravel 13
- **Admin/UI:** Filament 5, Livewire 4, Tailwind CSS 4
- **Database:** PostgreSQL 18
- **Spreadsheet Integration:** `phpoffice/phpspreadsheet`, `revolution/laravel-google-sheets`
- **AI/Translation:** `openai-php/client`
- **Other Integrations:** Telegram bot notifications, external shop APIs including OpenCart-like flows

## What The Application Does
- Imports products from Excel files, Google Sheets, or manual admin input into local catalog tables.
- Keeps import/update/delete work traceable through batch and item tables with statuses and payload snapshots.
- Binds one logical product family to one or many shops.
- Duplicates products when one local product is already bound to another shop and a separate shop-specific copy is required.
- Synchronizes categories, attributes, manufacturer, brand, descriptions, SEO URLs, specials, discounts, and multilingual content to the target shop scope.
- Builds outbound product payloads and sends them to remote shop APIs.
- Stores external IDs per shop so later update/delete/restore operations target the right remote product.
- Saves local and external product backups and uses them for restore flows.
- Uses AI-backed translation helpers and translation caches to fill multilingual rows.

## Core Modules

### 1. Product Import
- Entry point job: `app-code/app/app/Jobs/ProcessProductImportBatchJob.php`
- Main tables:
  - `spme_product_import_batches`
  - `spme_product_import_items`
  - `spme_products`
- Input sources:
  - Excel file
  - Google Sheets
  - Admin panel/manual payload
- Expected sheets in spreadsheet import:
  - `Product`
  - `Description`
  - `Image`
  - `Product Category`
  - `Product Attribute`
  - `Seo Url`
  - `Special`
  - `Discount`
- Import prepares normalized payload rows, creates or updates local catalog entities, records row-level failures, and can auto-bind imported products to shops using `ProcessProductShopBindingJob`.

### 2. Product-To-Shop Binding
- Entry point job: `app-code/app/app/Jobs/ProcessProductShopBindingJob.php`
- Core service: `app-code/app/app/Supports/Services/Products/ProductShopBindingService.php`
- Queue preparation service for bulk bind UI actions: `app-code/app/app/Services/Products/ProductShopBindingQueueService.php`
- Main tables:
  - `spme_product_shop`
  - `spme_products`
  - `spme_category_shop`
  - `spme_attribute_shop`
  - `spme_manufacturer_shop`
  - `spme_brand_shop`
- This is one of the most important parts of the system.
- Binding rules:
  - If product is not yet bound anywhere, it can be bound directly to the target shop.
  - If a product family is already bound to the target shop, binding is skipped and the existing family member is reused.
  - If product is already bound to another shop, a duplicated product may be created for the new shop.
  - `family_ulid` is used to track same logical product across duplicated per-shop copies.
  - After binding, the service synchronizes shop-scoped categories, attributes, manufacturer, brand, descriptions, attribute texts, and SEO URLs for all active shop languages.
  - After binding changes shop-scoped catalog entities, `ProductResourceOptionsService` caches for the target shop must be invalidated after commit. Otherwise the import-item edit modal can show stale manufacturer / brand / category / attribute options even when DB rows are already correct.
  - Before a product is bound to a shop, product-edit forms must still resolve readable manufacturer / brand / category / attribute labels from the best available description row. The fallback order is: requested `shop_language_id` -> `NULL` language row from import -> any other readable row -> raw `#id` only when no readable row exists.
  - Before a product is bound to a shop, the `Атрибути` tab must not depend on active shop languages only. If product attribute rows exist with `shop_language_id = NULL`, the UI must expose them through a stable virtual pre-bind tab instead of rendering the empty-state message.

### 3. Product Export
- Entry point job: `app-code/app/app/Jobs/ProcessProductExportItemJob.php`
- Payload builder: `app-code/app/app/Services/Products/Payload/ProductPayloadBuilderService.php`
- Main tables:
  - `spme_product_export_items`
  - `spme_product_shop`
  - `spme_shops`
- Export requires:
  - valid local `product_id`
  - valid target `shop_id`
  - existing local binding in `spme_product_shop`
- After successful export, `spme_product_shop.external_product_id` is updated. That external ID is critical for later update/delete/restore actions.

### 3.1 Product Edit State Hydration
- Shared read-model builder: `app-code/app/app/Services/Products/ProductEditStateBuilderService.php`
- The catalog product edit page and the import-item edit modal must hydrate from the same authoritative service.
- This service is responsible for:
  - resolving current shop and default language context
  - building category / attribute / SEO grouped form state
  - preserving pre-bind behavior for `NULL` language attribute rows

### 4. Product Update
- Preparation job: `app-code/app/app/Jobs/ProcessProductUpdateBatchJob.php`
- Queue preparation service for update resources: `app-code/app/app/Services/Products/ProductUpdateQueueService.php`
- The same service now also owns catalog product bulk-update actions and edit-page API update queue preparation.
- Related jobs:
  - `app-code/app/app/Jobs/ProcessProductUpdateItemJob.php`
  - `app-code/app/app/Jobs/ProcessCatalogProductRestoreBatchJob.php`
  - `app-code/app/app/Jobs/ProcessCatalogProductRestoreItemJob.php`
- Main tables:
  - `spme_product_update_batches`
  - `spme_product_update_items`
  - `spme_product_backups`
- Update batch documents identify target records using keys such as `product_id`, `external_product_id`, `model`, and `ean`.
- Restore operations for already exported products are tracked in update batch items and use external backups tied to a concrete `(product_id, shop_id, external_product_id)` tuple.

### 5. Product Delete
- Preparation job: `app-code/app/app/Jobs/ProcessProductDeleteBatchJob.php`
- Queueing service: `app-code/app/app/Supports/Services/Products/ProductDeleteQueueService.php`
- Item job: `app-code/app/app/Jobs/ProcessProductDeleteItemJob.php`
- Main tables:
  - `spme_product_delete_batches`
  - `spme_product_delete_items`
  - `spme_product_shop`
- Delete is shop-specific. It requires a bound product with a non-empty `external_product_id`.

### 6. Product Restore And Backups
- Bulk local restore job: `app-code/app/app/Jobs/ProcessProductRestoreBatchJob.php`
- UI-level restore queue service: `app-code/app/app/Services/Products/ProductRestoreQueueService.php`
- Restore service: `app-code/app/app/Supports/Services/Products/ProductBackupRestoreService.php`
- Payload apply service: `app-code/app/app/Supports/Services/Products/Backup/ProductBackupPayloadRestoreService.php`
- Main table:
  - `spme_product_backups`
- There are two backup classes:
  - local product snapshots for restoring local catalog state
  - external snapshots for restoring already exported remote product state

### 7. Translation And SEO Synchronization
- Translation job: `app-code/app/app/Jobs/ProcessProductTranslationJob.php`
- AI facade service: `app-code/app/app/Supports/Services/Ai/AiTranslationService.php`
- Payload translation service: `app-code/app/app/Supports/Services/Translations/Product/ProductImportPayloadTranslationService.php`
- Core shop-language model:
  - `app-code/app/app/Models/Shops/ShopLanguage.php`
- Main tables:
  - `spme_shop_languages`
  - `spme_product_descriptions`
  - `spme_product_to_attributes`
  - `spme_category_descriptions`
  - `spme_attribute_descriptions`
  - `spme_manufacturer_descriptions`
  - `spme_brand_descriptions`
  - `spme_seo_urls`
  - `spme_ai_translation_caches`

## Main Business Entities And Tables

### Products
- `spme_products`
  - base product record
  - fields include `product_import_item_id`, `family_ulid`, `marked_to_shop`, `model`, `sku`, `ean`, `quantity`, `minimum`, `image`, `price`, `is_active`, dates
- `spme_product_descriptions`
  - localized product texts by `shop_language_id`
- `spme_product_images`
  - additional images
- `spme_product_specials`
  - special prices
- `spme_product_discounts`
  - discount tiers
- `spme_product_to_attributes`
  - product attribute values by `attribute_id` and `shop_language_id`
- `spme_product_to_manufacturer_brand`
  - selected manufacturer and brand for product

### Import / Update / Delete Tracking
- `spme_product_import_batches` -> one import session
- `spme_product_import_items` -> one normalized/imported product row
- `spme_product_update_batches` -> one update/restore session
- `spme_product_update_items` -> one update/restore operation row
- `spme_product_delete_batches` -> one delete session
- `spme_product_delete_items` -> one delete operation row
- These tables persist `payload`, `status`, `error_message`, `processed_at`, counters, and options JSON for traceability.
- Batch-level operator-facing error audit is persisted in two layers:
  - concise metadata in batch `options`:
    - `last_error`
    - `error_log_path`
  - full human-readable snapshot file on the public storage disk under `storage/app/public/logs/product-batches/...`
- The shared owner for these snapshots is `app-code/app/app/Services/Products/ProductBatchErrorAuditService.php`.

### Shop Binding And Remote IDs
- `spme_product_shop`
  - the central binding table between local products and shops
  - stores `product_import_batch_id`, `product_id`, `shop_id`, `external_product_id`
  - this table answers:
    - is product bound to shop?
    - what remote product id was assigned by that shop?
    - which import batch created the binding?

### Catalog Taxonomy And Shop Scope
- `spme_categories`
  - categories, optionally scoped by `shop_id`, grouped by `family_ulid`
- `spme_category_descriptions`
  - localized category texts
- `spme_category_product`
  - product to category relation
- `spme_category_shop`
  - external category ID mapping per shop
- `spme_attributes`
  - attributes, optionally scoped by `shop_id`, grouped by `family_ulid`
- `spme_attribute_descriptions`
  - localized attribute names
- `spme_attribute_shop`
  - external attribute ID mapping per shop
- `spme_manufacturers`, `spme_brands`
  - manufacturer and brand entities, also can be shop-scoped and grouped by `family_ulid`
- `spme_manufacturer_descriptions`, `spme_brand_descriptions`
  - localized names
- `spme_manufacturer_shop`, `spme_brand_shop`
  - external IDs per shop

### Shops And Languages
- `spme_shops`
  - shop metadata and API configuration
  - important fields: `type`, `base_url`, `api_url`, `options`, API endpoint path fragments, tokens
- `spme_shop_languages`
  - active/default languages per shop
  - default language is always active
  - cache-backed methods on `ShopLanguage` are used heavily in binding/translation flows

### SEO And AI
- `spme_seo_urls`
  - polymorphic SEO table by `seoable_type`, `seoable_id`, `shop_language_id`
  - used for products and catalog entities
- `spme_ai_translation_caches`
  - caches AI prompts and answers for repeated translation requests

### Backups
- `spme_product_backups`
  - polymorphic backup table with `backupable_type`, `backupable_id`, `backup_source`, `backup_kind`, `shop_id`, `external_product_id`, `payload`, `is_used`

## Key Relationships
- `spme_product_import_batches.id -> spme_product_import_items.product_import_batch_id`
- `spme_product_import_items.id -> spme_products.product_import_item_id`
- `spme_products.id -> spme_product_descriptions.product_id`
- `spme_products.id -> spme_product_images.product_id`
- `spme_products.id -> spme_product_to_attributes.product_id`
- `spme_products.id -> spme_product_to_manufacturer_brand.product_id`
- `spme_products.id -> spme_product_shop.product_id`
- `spme_products.id -> spme_category_product.product_id`
- `spme_categories.id -> spme_category_descriptions.category_id`
- `spme_categories.id -> spme_category_shop.category_id`
- `spme_attributes.id -> spme_attribute_descriptions.attribute_id`
- `spme_attributes.id -> spme_attribute_shop.attribute_id`
- `spme_manufacturers.id -> spme_manufacturer_descriptions.manufacturer_id`
- `spme_manufacturers.id -> spme_manufacturer_shop.manufacturer_id`
- `spme_brands.id -> spme_brand_descriptions.brand_id`
- `spme_brands.id -> spme_brand_shop.brand_id`
- `spme_shops.id -> spme_shop_languages.shop_id`
- `spme_shop_languages.id` is referenced by product/category/attribute/manufacturer/brand localized rows and `spme_seo_urls`

## Where To Look First When Debugging
- Import parsing / sheet header mismatch:
  - `ProcessProductImportBatchJob`
- Product did not bind or duplicated unexpectedly:
  - `ProductShopBindingService`
  - `ProductShop`
  - `spme_product_shop`
- Edit modal shows wrong or incomplete manufacturer / brand / category / attribute options right after binding:
  - `ProductResourceOptionsService`
  - `ProductShopBindingService`
  - `spme_*_shop`
  - `spme_*_descriptions`
- Edit modal or product edit page shows raw `#id` labels before binding:
  - `ProductResourceOptionsService`
  - `ProductEditStateBuilderService`
  - `ProductForm`
  - `ProductImportItemsRelationManager`
  - `EditProduct`
  - verify whether a readable `NULL`-language or cross-language description row already exists before assuming translation data is missing
- Import bulk-bind actions behave differently between batch list and item relation manager:
  - `ProductShopBindingQueueService`
  - `ProductImportBatchesTable`
  - `ProductImportItemsRelationManager`
  - `ProcessProductShopBindingJob`
- Import export/retry actions behave differently between batch list and item relation manager:
  - `ProductExportQueueService`
  - `ProductImportBatchesTable`
  - `ProductImportItemsRelationManager`
  - `ProcessProductExportItemJob`
- Update export-to-shop actions behave differently between batch list and item relation manager:
  - `ProductUpdateQueueService`
  - `ProductUpdateBatchesTable`
  - `ProductUpdateItemsRelationManager`
  - `ProcessProductUpdateItemJob`
- Catalog table update actions or edit-page API update behave differently from update resources:
  - `ProductUpdateQueueService`
  - `ProductsTable`
  - `EditProduct`
  - `ProcessProductUpdateItemJob`
- Delete/retry actions behave differently between batch list and item relation manager:
  - `ProductDeleteQueueService`
  - `ProductDeletesTable`
  - `ProductDeleteItemsRelationManager`
  - `ProcessProductDeleteItemJob`
- Restore actions behave differently between catalog products and import items:
  - `ProductRestoreQueueService`
  - `ProductsTable`
  - `ProductImportItemsRelationManager`
  - `ProcessCatalogProductRestoreBatchJob`
  - `ProcessProductRestoreBatchJob`
- Repeated shop/product binding read lookups in UI paths:
  - `ProductShop`
  - `Shop`
  - `ProductsTable`
  - `ProductImportItemsRelationManager`
  - `ProductUpdateItemsRelationManager`
  - `ProductDeleteItemsRelationManager`
- Wrong payload sent to external API:
  - `ProductPayloadBuilderService`
  - `ProcessProductExportItemJob`
- Update/delete/restore queue behavior:
  - `ProcessProductUpdateBatchJob`
  - `ProductDeleteQueueService`
  - `ProcessCatalogProductRestoreBatchJob`
- Multilingual or SEO issues:
  - `ShopLanguage`
  - `ProductShopBindingService`
  - `spme_*_descriptions`
  - `spme_seo_urls`
- Backup validity / restore bugs:
  - `ProductBackupRestoreService`
  - `ProductBackupPayloadRestoreService`
  - `spme_product_backups`

## Important Recent Regression History
See `docs/patches/` for high-signal bug notes. The current codebase already contains fixes around:
- SEO URL synchronization for all active shop languages
- manufacturer and brand multilingual synchronization
- duplicated-product attribute translation correctness
- normalized comparison for stale copied translations
- cached `ShopLanguage` hydration preserving language IDs

## Architecture
See `docs/ARCHITECTURE.md` for the development rules and module boundaries.
Pattern: Modular Monolith

## See Also

- [Architecture](ARCHITECTURE.md) — modules and dependency rules.
- [Product Data Workflows](product-data-workflows.md) — operation flows.
- [Roadmap](ROADMAP.md) — planned work.
