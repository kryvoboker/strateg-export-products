[← Getting Started](getting-started.md) · [Back to README](../README.md) · [Configuration →](configuration.md)

# Architecture

## Overview

The application is a Laravel modular monolith. Filament provides the administration interface, queued jobs process long-running operations, and services contain catalog rules and external-store integrations.

    Filament actions
          ↓
    Batch / queue preparation services
          ↓
    Queued item jobs
          ↓
    Local PostgreSQL + external store API

## Main Directories

| Directory | Purpose |
|---|---|
| app/Filament | Administration resources, forms, tables, and actions |
| app/Jobs | Batch preparation and background operations |
| app/Models | Eloquent models for domain entities |
| app/Services | API and product application services |
| app/Supports | Shared services and helper functions |
| app/Enums | Operation types and statuses |
| database/migrations | PostgreSQL schema |
| docs | User and technical documentation |

## Catalog Flows

1. An import batch loads data from a file, Google Sheets, or a form.
2. Jobs normalize rows and save local products.
3. Binding creates the store relationship and synchronizes language-specific entities.
4. The payload builder prepares data for the external API.
5. Export, update, deletion, and restoration jobs persist statuses and external product identifiers.

The spme_product_shop table is the source of truth for local product-to-store relationships and stores external_product_id.

## Module Boundaries

- Filament actions collect input and dispatch jobs.
- Jobs coordinate operations and statuses without duplicating service rules.
- ProductPayloadBuilderService builds export and update payloads.
- Product binding operations go through binding services.
- Long-running operations do not run synchronously inside UI actions.

## Supports Layer

The `app/Supports` tree contains shared helpers and domain services that are reused by jobs, resources, and translation workflows:

- `helpers.php` provides normalization, JSON, URL, date, Telegram, and value-conversion functions.
- `Services/Products` owns binding, backup restoration, deletion queue preparation, and product-language synchronization.
- `Services/SeoSlug` owns transliteration and language-specific SEO keyword generation.
- `Services/Translations` adapts translation services to product, category, attribute, brand, and manufacturer scopes.
- `Services/Ai` owns prompt normalization, translation calls, caching, and rate limiting.

See the [Supports reference](supports.md) for every helper function and public service method.

## See Also

- [Getting Started](getting-started.md) — start the local environment.
- [Configuration](configuration.md) — queue and store settings.
- [Helper Functions](helpers.md) — shared application functions.
