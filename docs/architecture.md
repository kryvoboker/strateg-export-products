[← Getting Started](getting-started.md) · [Back to README](../README.md) · [Configuration →](configuration.md)

# Architecture

## Overview

The application is a Laravel 13 modular monolith. Filament 5 and Livewire 4 provide the administration interface; queued jobs execute long-running catalog workflows, while application and shared services own queue preparation, product rules, translation, and remote-shop integration.

    Filament actions
          ↓
    Queue preparation services
          ↓
    Queued batch and item jobs
          ↓
    Local PostgreSQL + external store API

## Main Directories

| Directory           | Purpose                                              |
|---------------------|------------------------------------------------------|
| app/Filament        | Administration resources, forms, tables, and actions |
| app/Jobs            | Batch preparation and background operations          |
| app/Models          | Eloquent models for domain entities                  |
| app/Services        | API and product application services                 |
| app/Supports        | Shared services and helper functions                 |
| app/Enums           | Operation types and statuses                         |
| database/migrations | PostgreSQL schema                                    |
| docs                | User and technical documentation                     |

The Laravel application source is under `app-code/app/`; the paths above are relative to `app-code/app/app/` unless they refer to repository-level configuration or documentation.

## Catalog Flows

1. An import batch loads data from a file, Google Sheets, or a form.
2. Jobs normalize rows and save local products.
3. Binding creates the store relationship and synchronizes language-specific entities.
4. The payload builder prepares data for the external API.
5. Export, update, deletion, and restoration jobs persist statuses and external product identifiers.

The spme_product_shop table is the source of truth for local product-to-store relationships and stores external_product_id.

## Module Boundaries

- Filament actions collect input and dispatch jobs.
- Batch jobs prepare item records; item jobs execute individual local or remote operations and persist their outcomes.
- Queue-preparation services own eligibility checks, duplicate prevention, retry selection, and dispatch.
- ProductPayloadBuilderService builds export and update payloads.
- Product binding operations go through binding services.
- Long-running operations do not run synchronously inside UI actions.
- Remote API jobs resolve configured shop endpoints, authenticate OpenCart-like integrations, validate responses, and persist remote IDs; Filament resources do not call shop APIs directly.
- OpenCart session tokens are stored in shop options; a per-shop cache lock coordinates refresh, and a rejected operation is retried once after refresh.
- A non-empty remote `error` or `warning` message is a failed response, even when the HTTP status is successful.
- The repository has no inbound `routes/api.php`. `docs/openapi.yaml` describes the configurable outbound remote-shop integration, not an API served by this application; each shop's external contract remains authoritative.

## Supports Layer

The `app/Supports` tree contains shared helpers and domain services that are reused by jobs, resources, and translation workflows:

- `helpers.php` provides normalization, JSON, URL, date, Telegram, and value-conversion functions.
- `Services/Products` owns binding, backup restoration, deletion queue preparation, and product-language synchronization.
- `Services/SeoSlug` owns transliteration and language-specific SEO keyword generation.
- `Services/Translations` adapts translation services to product, category, attribute, brand, and manufacturer scopes.
- `Services/Ai` owns prompt normalization, translation calls, caching, and rate limiting.

See the [Supports reference](supports.md) for every helper function and public service method.

See the [Remote API Integration](remote-api-integration.md) page for implemented outbound shop operations and API availability status.

## See Also

- [Getting Started](getting-started.md) — start the local environment.
- [Configuration](configuration.md) — queue and store settings.
- [Helper Functions](helpers.md) — shared application functions.