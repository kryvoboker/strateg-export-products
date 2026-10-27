[← Getting Started](getting-started.md) · [Back to README](../README.md) · [Configuration →](configuration.md)

# Architecture: Modular Monolith

## Overview
Strateg Export Products should be maintained as a modular monolith on Laravel 13. The application is deployed as one codebase and one database, but its business behavior is already split into strong modules: imports, bindings, exports, updates, deletes, restores, translations, SEO synchronization, and external shop integrations.

This is the right fit for the current system because the domain is rich and stateful, but operational simplicity still matters more than independent deployment. The hard part here is not service boundaries across infrastructure. The hard part is keeping product families, shop scope, multilingual content, backups, and remote IDs consistent across many queued workflows.

## Processing Flow
```text
Filament actions
      ↓
Queue preparation services
      ↓
Queued batch and item jobs
      ↓
Local PostgreSQL + external store API
```

1. An import batch loads data from a file, Google Sheets, or a form.
2. Jobs normalize rows and save local products.
3. Binding creates the store relationship and synchronizes language-specific entities.
4. The payload builder prepares data for the external API.
5. Export, update, deletion, and restoration jobs persist statuses and external product identifiers.

Paths in this document are relative to `app-code/app/app/` unless they start with `app-code/` or `docs/`.

## Decision Rationale
- **Project type:** Internal admin platform that owns a normalized product catalog and synchronizes it with external shops.
- **Tech stack:** PHP 8.5, Laravel 13, Filament 5, Livewire 4, PostgreSQL 18.
- **Key factor:** The same logical product can exist as multiple local rows per shop, while still sharing family identity, localized content rules, and export/update/delete/restore workflows.

## Folder Structure
```text
app-code/app/app/
├── Filament/
│   ├── Resources/                         # Admin UI: forms, tables, pages, relation managers, actions
│   └── Navigation/
├── Jobs/                                  # Batch preparation and queued workflow execution
│   ├── Traits/
│   ├── ProcessProductImportBatchJob.php
│   ├── ProcessProductShopBindingJob.php
│   ├── ProcessProductExportItemJob.php
│   ├── ProcessProductUpdateBatchJob.php
│   ├── ProcessProductDeleteBatchJob.php
│   ├── ProcessProductRestoreBatchJob.php
│   └── ...
├── Models/                                # Eloquent models grouped by domain
│   ├── Products/
│   ├── Categories/
│   ├── Attributes/
│   ├── Manufacturers/
│   ├── Brands/
│   ├── Shops/
│   ├── Seo/
│   └── Users/
├── Services/
│   ├── Api/                               # Remote API integrations
│   └── Products/                          # Application-facing product services
├── Supports/
│   ├── Services/
│   │   ├── Products/                      # Core business logic and restore/binding helpers
│   │   ├── SeoSlug/                       # Language-specific slug generation
│   │   ├── Translations/                  # Entity-specific translation services
│   │   └── Ai/                            # AI prompt/caching/translation orchestration
│   └── helpers.php
├── Enums/
├── Exceptions/
├── Http/
└── Providers/
```

## Module Map

### Supports Layer
- Main code:
  - `app-code/app/app/Supports/helpers.php`
  - `app-code/app/app/Supports/Services/Ai/*`
  - `app-code/app/app/Supports/Services/Products/*`
  - `app-code/app/app/Supports/Services/SeoSlug/*`
  - `app-code/app/app/Supports/Services/Translations/*`
- Responsibility:
  - provide small, framework-wide value and normalization helpers
  - isolate product binding, deletion queues, and backup restoration rules
  - generate language-specific SEO keywords
  - coordinate translation prompts, caching, rate limiting, and multilingual payload expansion
- Boundary:
  - application jobs and Filament resources may call public Support service methods
  - private and protected methods remain implementation details of their owning service
  - helpers must stay dependency-light and must not contain workflow orchestration

The complete function and method inventory is maintained in [`docs/supports.md`](../docs/supports.md).

#### Support Service Groups

| Group                | Primary responsibility                                                                      |
|----------------------|---------------------------------------------------------------------------------------------|
| AI translation       | Prompt normalization, translation calls, caching, and rate limiting                         |
| Product binding      | Family locking, shop-scoped duplication, relation synchronization, and language propagation |
| Product backup       | Snapshot validation, local restore, and relation replacement                                |
| Product deletion     | Queue preparation, retry handling, and batch status synchronization                         |
| SEO slugs            | Transliteration and language-specific keyword normalization                                 |
| Translation adapters | Entity scope resolution for product, category, attribute, brand, and manufacturer text      |

### Presentation Module
- Main code:
  - `app-code/app/app/Filament/Resources/*`
- Responsibility:
  - render admin resources
  - collect action input
  - dispatch jobs or call thin application services
- Rule:
  - no heavy business logic in tables, forms, actions, or pages

### Import Module
- Main code:
  - `ProcessProductImportBatchJob`
  - `ProductImportBatch`
  - `ProductImportItem`
- Responsibility:
  - load documents
  - validate required sheets and headers
  - normalize row payloads
  - create local catalog state
  - record batch/item statuses

### Binding Module
- Main code:
  - `ProcessProductShopBindingJob`
  - `ProductShopBindingService`
  - `ProductShopBindingQueueService`
  - `ProductShop`
- Responsibility:
  - bind local product families to shops
  - duplicate products when required by shop isolation
  - synchronize shop-scoped catalog entities
  - synchronize multilingual rows and SEO URLs
- This is the most sensitive module in the whole application.

### Export Module
- Main code:
  - `ProcessProductExportItemJob`
  - `ProductPayloadBuilderService`
  - `InteractsWithShopApi`
- Responsibility:
  - resolve shop-specific payload
  - call remote API
  - persist returned `external_product_id`

### Remote API Boundary
- Main code:
  - `app-code/app/app/Jobs/Traits/InteractsWithShopApi.php`
  - `app-code/app/app/Jobs/ProcessProductExportItemJob.php`
  - `app-code/app/app/Jobs/ProcessProductUpdateItemJob.php`
  - `app-code/app/app/Jobs/ProcessProductDeleteItemJob.php`
  - `app-code/app/app/Jobs/ProcessCatalogProductRestoreItemJob.php`
- Responsibility:
  - resolve configurable remote endpoints and API base URLs
  - authenticate OpenCart-like shops, reuse session tokens, and coordinate reactive refresh with a per-shop cache lock
  - send form-encoded export, update, backup, delete, and restore requests
  - persist remote IDs and external backup snapshots
  - treat non-empty remote `error` and `warning` messages as failures, including on successful HTTP statuses
- Boundary:
  - this application has no inbound `routes/api.php`
  - `docs/openapi.yaml` documents the outbound remote-shop integration as a configurable contract template
  - the remote shop contract remains external and must be verified per integration
  - endpoint configuration alone does not define every request or response field

### Update / Delete / Restore Modules
- Main code:
  - `ProcessProductUpdateBatchJob`
  - `ProductUpdateQueueService`
  - `ProcessProductDeleteBatchJob`
  - `ProductDeleteQueueService`
  - `ProcessProductRestoreBatchJob`
  - `ProcessCatalogProductRestoreBatchJob`
  - `ProductBackupRestoreService`
- Responsibility:
  - build operation items from documents or selections
  - validate shop binding and remote IDs
  - restore local or external state from backups

### Translation And SEO Module
- Main code:
  - `AiTranslationService`
  - `ProductImportPayloadTranslationService`
  - `ProductShopBindingService`
  - `ShopLanguage`
  - `SeoUrl`
- Responsibility:
  - generate or fill multilingual texts
  - keep translated rows aligned with active shop languages
  - maintain per-language SEO URLs

## Dependency Rules
- ✅ Filament resources may dispatch jobs or call dedicated services.
- ✅ Jobs may orchestrate workflows, update status tables, and call services.
- ✅ Services may coordinate models, helpers, translation services, and integration adapters.
- ✅ Models may expose relationship helpers, query helpers, and small domain-specific static lookups.
- ✅ Cross-cutting helpers that are genuinely shared may live in `app/Supports/helpers.php`.
- ✅ Shared Filament read-model assembly should live in a dedicated service when multiple resources hydrate the same workflow state.
- ❌ Filament resource classes must not parse spreadsheets, build remote payloads, or implement binding rules inline.
- ❌ Controllers, pages, or actions must not directly mutate `spme_*_shop` mapping tables unless that logic belongs to a dedicated service.
- ❌ Export/update/delete code must not guess remote identifiers from fuzzy local fields when `spme_product_shop.external_product_id` is missing.
- ❌ Translation logic must not assume one default language row is enough for a multilingual shop.
- ❌ One module must not bypass another module's authoritative service when invariants are involved.

## Layer/Module Communication
- UI actions -> dispatch queue jobs for long-running operations.
- Batch jobs -> create item records and synchronize batch counters/statuses.
- Item jobs -> perform one concrete remote or local operation and persist the result.
- Binding service -> owns duplication, family locking, shop-scoped entity sync, and translation fan-out.
- Queue-preparation service -> owns bulk action summaries, skip rules, and dispatch preparation before jobs are pushed.
- Update/delete queue-preparation services -> own batch/item retry rules so Filament resources do not duplicate operation guards.
- `ProductUpdateQueueService` should be the single update queue owner across:
  - update resources
  - catalog table actions
  - edit-page API update actions
- `ProductRestoreQueueService` should be the UI-level restore owner across:
  - catalog table restore actions
  - import-item bulk restore actions
  - while execution details remain in restore jobs
- Edit state builder -> owns shared product edit hydration arrays used by more than one Filament surface.
- Payload builder -> is the only place that should assemble export/update product payloads from local models.
- Remote API jobs -> own HTTP authentication, endpoint resolution, response validation, and remote failure state; Filament resources must not call shop APIs directly.
- Backup restore service -> validates backup payloads before restore and delegates actual apply logic to dedicated restore helpers.

## Data And Table Rules
- `spme_product_shop` is the authoritative local-to-shop binding table.
- `family_ulid` is the authoritative identity for one logical product family across duplicated local product rows.
- `shop_id` on category/attribute/manufacturer/brand tables defines shop-scoped catalog entities.
- `*_shop` mapping tables hold external IDs for remote catalogs and must be kept in sync during export/update flows.
- `spme_shop_languages` defines the active language set that drives translation and SEO fan-out.
- `spme_*_batches` and `spme_*_items` tables are not audit noise. They are operational state and must stay accurate.
- `spme_product_backups` is the source of truth for restore operations.
- `options.last_error` + `options.error_log_path` on batch tables are the operator-facing error audit contract for import/update/delete preparation flows.
- Detailed batch error text should live in a public storage snapshot written by one shared service, not be reconstructed ad hoc in Filament resources.

## Investigation Guide

### If import is broken
Start with:
- `ProcessProductImportBatchJob`
- `spme_product_import_batches`
- `spme_product_import_items`

Questions:
- Were required sheets present?
- Were headers normalized correctly?
- Was local product created or relinked to an import item?
- Did import options record `error_log_path` or partial failures?

### If binding is broken
Start with:
- `ProductShopBindingService`
- `ProcessProductShopBindingJob`
- `ProductShop`
- `spme_product_shop`

Questions:
- Is the product already bound to the target shop?
- Does another product in the same `family_ulid` already exist in that shop?
- Did the flow bind directly or duplicate the product?
- Were shop-scoped categories/attributes/manufacturer/brand created or reused?
- Did all active shop languages receive synchronized rows?
- Were `ProductResourceOptionsService` caches invalidated after commit for the target shop?
- If the product is still unbound, does the UI resolve labels from `requested language -> NULL language row -> any readable row` before falling back to raw `#id`?
- If the product is still unbound, does the `Attributes` tab derive context from existing `spme_product_to_attributes.shop_language_id` rows, including `NULL`, instead of hiding attributes behind the no-languages placeholder?

### If export/update/delete is broken
Start with:
- `ProcessProductExportItemJob`
- `ProductPayloadBuilderService`
- `ProductDeleteQueueService`
- `ProcessProductUpdateBatchJob`
- `spme_product_shop`

Questions:
- Is product bound to the selected shop?
- Does binding have `external_product_id`?
- Is payload filtered to entities inside the selected shop scope?
- Did the remote response update external IDs correctly?

### If restore is broken
Start with:
- `ProductBackupRestoreService`
- `ProductBackupPayloadRestoreService`
- `ProcessProductRestoreBatchJob`
- `ProcessCatalogProductRestoreBatchJob`
- `spme_product_backups`

Questions:
- Is there an unused valid backup?
- Is it a local snapshot or external snapshot?
- Is restore targeting local product state or remote shop state?

### If translations or SEO are broken
Start with:
- `ShopLanguage`
- `ProductShopBindingService`
- `AiTranslationService`
- `spme_seo_urls`
- `spme_*_descriptions`
- `spme_product_to_attributes`

Questions:
- Did the target shop languages load correctly from cache?
- Is default-language normalization present?
- Is multilingual fan-out present for every active language?
- Are stale copied non-default texts preventing re-translation?

## Key Principles
1. Treat one logical product family and one local product row as different concepts. `family_ulid` links the family; `spme_products.id` identifies one concrete per-shop local record.
2. Keep shop scope explicit. Never infer target shop by product text, model similarity, or indirect relations when `shop_id` or `spme_product_shop` already defines it.
3. Keep multilingual synchronization explicit. Default-language normalization and full active-language fan-out are both required.
4. Keep remote operations traceable. Every export/update/delete/restore flow must leave observable state in batch/item tables and logs.
5. Prefer authoritative services over duplicated logic. Binding, payload assembly, and restore logic should each have one main owner.
6. Preserve recoverability. Backup creation, restore validation, and skip-with-status behavior are more important than optimistic mutation.
7. Keep queue preparation centralized. If batch and item Filament actions enqueue the same workflow, they must delegate to the same application service instead of duplicating queue and placeholder rules.
8. Keep repeated read-model lookup rules centralized. If multiple Filament resources need the same `shop_id` / `shop_name` / `external_product_id` / bound-shop option logic, move that query behavior into narrow model helpers such as `ProductShop` or `Shop` instead of duplicating joins and plucks in resources.

## Code Examples

### Filament Action Should Delegate To Workflow Code
```php
<?php

declare(strict_types=1);

use App\Jobs\ProcessProductShopBindingJob;
use App\Models\Products\Imports\ProductImportItem;
use Filament\Actions\Action;

Action::make('bindToShops')
    ->action(function (ProductImportItem $record, array $data): void {
        ProcessProductShopBindingJob::dispatch(
            product_id: (int) $record->product_id,
            shop_ids: $data['shop_ids'],
            product_import_batch_id: (int) $record->product_import_batch_id,
            source_payload: $record->payload ?? [],
        );
    });
```

### Binding Rules Must Stay Inside The Binding Service
```php
<?php

declare(strict_types=1);

namespace App\Supports\Services\Products;

use App\Models\Products\Product;

final class ProductShopBindingService
{
    public function bindProductToShopAndReturnTargetProduct(int $product_id, int $target_shop_id): array
    {
        // This service decides whether to:
        // 1. bind the original product,
        // 2. reuse an existing family member,
        // 3. duplicate the product for a new shop.
        // UI and jobs should not reimplement these rules.

        return [];
    }
}
```

## Anti-Patterns
- ❌ Writing shop-binding logic directly in Filament actions or pages.
- ❌ Copying non-default translated text into duplicated product rows and assuming translation pipeline will still fix it later.
- ❌ Creating SEO URLs only for default language when the shop has multiple active languages.
- ❌ Hydrating cached `ShopLanguage` rows in a way that drops primary keys and breaks translation loops.
- ❌ Mutating shop-scoped categories / attributes / manufacturer / brand during binding without invalidating `ProductResourceOptionsService` caches after commit.
- ❌ Rendering pre-bind manufacturer / brand / category / attribute labels directly from a single requested language row and showing raw `#id` even though a readable `NULL` or cross-language description already exists.
- ❌ Reimplementing export queue preparation or failed placeholder creation separately in batch and item Filament actions.
- ❌ Repeating `Shop::query()->where('is_active'...)` or `ProductShop` join/pluck logic across multiple resources when the rule is already shared and business-relevant.
- ❌ Marking a batch as `FAILED` while leaving `failed_items = 0`, `options.last_error = null`, and `options.error_log_path = null` when the operator clearly needs an audit trail.
- ❌ Building remote product payloads with raw ad hoc queries scattered across jobs.
- ❌ Treating `spme_*_batches` and `spme_*_items` as disposable temporary data.

## Patch Awareness
Always review `docs/patches/` before changing binding, translation, SEO, or shop-language behavior. Those notes document high-severity regressions that already happened in this codebase and show where hidden invariants live.

## See Also
- [Getting Started](getting-started.md) — start the local environment.
- [Configuration](configuration.md) — queue and store settings.
- [Helper Functions](helpers.md) — shared application functions.
- [Supports Reference](supports.md) — every helper and public Support service method.
- [Remote API Integration](remote-api-integration.md) — implemented outbound shop operations and API availability status.
